<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/logseal.php';

// ── UNEXPECTED ERRORS ────────────────────────────────────────
// Anything uncaught (a database error, a bug) still answers in JSON, so the
// page shows a message instead of doing nothing. The detail goes to the
// server's error log, never to the browser.
set_exception_handler(function (Throwable $e): void {
    error_log('ConstructFlow: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo json_encode(['success' => false, 'data' => [],
        'message' => 'Something went wrong on the server. Please try again; if it keeps happening, tell your administrator.']);
});

// ── TIME ZONE ────────────────────────────────────────────────
// Every time the system records or compares is Philippine time, whatever
// the host's own clocks are set to: the live database's server zone, and
// PHP's date.timezone, are both set independently of this app. getDB()
// keeps one connection per request, so the session setting holds for it.
// An offset rather than 'Asia/Manila': named zones need MySQL's time-zone
// tables, which shared hosts do not always load. Manila has no DST.
date_default_timezone_set('Asia/Manila');
getDB()->exec("SET time_zone = '+08:00'");

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
        'SELECT user_id, name, email, is_active, email_verified_at, account_type, avatar_path, contact_number
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
        'contact_number'=> $row['contact_number'],
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
/**
 * The byte GROUP_CONCAT joins name lists with, chosen because it cannot
 * occur inside a person's name. ASCII 31, the unit separator.
 */
const NAME_GLUE = "\x1F";

/**
 * Appends "(removed)" to the name of anyone who is no longer on this team.
 *
 * Removal is per-team but a name is global: the same person may still be
 * working in another team, may be re-invited to this one, and can still
 * log in. So the marker is decided when records are read and never
 * written into Users.name — the mistake would be invisible until someone
 * in a second team saw a colleague labelled as gone.
 *
 * Safe to apply everywhere because records belong to the team: every
 * listing is already scoped by team_id, so this only ever labels people
 * inside the team whose records are being looked at.
 *
 * $pairs maps an id column to the name column it belongs to, e.g.
 * ['assigned_to' => 'assigned_to_name']. A joined pair such as
 * ['crew_ids' => 'crew_names'] is marked position by position; both
 * GROUP_CONCATs carry the same ORDER BY, so the lists line up.
 *
 * Those name lists are joined on NAME_GLUE rather than on ", ". A comma is
 * legal inside a name — "Santos, Jr." is ordinary here — and splitting on
 * one produced more fragments than there were ids, which shifted every
 * later name onto the wrong id and marked the wrong person as removed.
 * This function is what turns the glue back into the ", " the UI shows, so
 * it has to run over every row whether or not anyone has actually left.
 */
function markFormerMembers(array $rows, int $teamId, array $pairs): array {
    if (!$rows) return $rows;

    $st = getDB()->prepare('SELECT user_id FROM TeamMembers WHERE team_id = ? AND status = "removed"');
    $st->execute([$teamId]);
    $gone = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

    foreach ($rows as &$row) {
        foreach ($pairs as $idCol => $nameCol) {
            if (!isset($row[$idCol], $row[$nameCol])) continue;
            if ($row[$idCol] === null || $row[$nameCol] === '') continue;

            $ids   = explode(',', (string)$row[$idCol]);
            $names = explode(NAME_GLUE, (string)$row[$nameCol]);
            foreach ($ids as $i => $id) {
                // An erased account already reads "(deleted)"; "(removed)"
                // on top of it would say the same thing twice.
                if (isset($names[$i]) && isset($gone[(int)$id])
                    && !str_ends_with($names[$i], '(deleted)')) {
                    $names[$i] .= ' (removed)';
                }
            }
            $row[$nameCol] = implode(', ', $names);
        }
    }
    unset($row);
    return $rows;
}

/** Single-row convenience wrapper around markFormerMembers(). */
function markFormerMember(?array $row, int $teamId, array $pairs): ?array {
    if (!$row) return $row;
    return markFormerMembers([$row], $teamId, $pairs)[0];
}

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

/** Reports, reviews and closures wait for a supervisor, so a team keeps one. */
function assertNotLastSupervisor(int $teamId, string $name): void {
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM TeamMembers WHERE team_id = ? AND role = "supervisor" AND status = "active"'
    );
    $stmt->execute([$teamId]);
    if ((int)$stmt->fetchColumn() <= 1) {
        json_response(false,
            "$name is the team's only supervisor. Make someone else a supervisor first, so reports and reviews are not left waiting.",
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

/** post_max_size and friends as bytes ("12M" -> 12582912). */
function iniBytes(string $key): int {
    $v = trim((string)ini_get($key));
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** True when PHP discarded the request body because it was over post_max_size. */
function requestTooLarge(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$_POST && !$_FILES
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > iniBytes('post_max_size');
}

function verifyCsrf(): void {
    if (requestTooLarge()) {
        json_response(false, 'The photos are too large to send together. Remove some photos and try again.', [], 413);
    }
    startSecureSession();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token'])) {
        json_response(false, 'Your sign-in has expired. Please sign in again.', [], 401);
    }
    if (!hash_equals($_SESSION['csrf_token'], (string)$sent)) {
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
    //
    // expires_at was written by MySQL's NOW(), so it is measured against
    // MySQL's NOW(). PHP's date.timezone and the database server's timezone
    // are set independently, and any gap between them quietly lengthens or
    // shortens every verification and password-reset code by that amount.
    // The caller's row is reused as-is, so no SELECT elsewhere has to change.
    $exp = getDB()->prepare('SELECT CAST(? AS DATETIME) < NOW()');
    $exp->execute([$row['expires_at']]);
    if ((int)$exp->fetchColumn() === 1) {
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

// ── CONTACT NUMBER ───────────────────────────────────────────
const CONTACT_NUMBER_ERROR = 'Enter a valid Philippine mobile number, e.g. 0917 123 4567.';

/**
 * A Philippine mobile number in one stored shape, 09XXXXXXXXX, or null if
 * the input is not one. Spaces, dashes, dots and brackets are ignored, and
 * the +63 / 63 country-code forms are accepted.
 */
function normalizeContactNumber(string $raw): ?string {
    $d = preg_replace('/[\s\-().]/', '', $raw);
    if (preg_match('/^\+?63(9\d{9})$/', $d, $m)) return '0' . $m[1];
    return preg_match('/^09\d{9}$/', $d) ? $d : null;
}

/* no 1 letter name */
function nameIsLongEnough(string $name): bool {
    return mb_strlen(trim($name)) >= 2;
}

// ── ACTIVITY LOG ─────────────────────────────────────────────
function logActivity(
    ?int $userId,
    string $action,
    ?string $targetType = null,
    ?int $targetId = null,
    ?string $description = null,
    ?int $teamId = null
): void {
    try {
        $teamId = $teamId ?? ($_SESSION['active_team_id'] ?? null);
        $db = getDB();
        $db->prepare(
            'INSERT INTO ActivityLogs (user_id, team_id, action, target_type, target_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $teamId, $action, $targetType, $targetId, $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        // Signed when the request ends (config/logseal.php).
        queueLogSeal((int)$db->lastInsertId());
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

/**
 * True when an insert failed only because another request took the same
 * REP-/TASK-/WO- number first. nextCode() reads MAX()+1, so two requests in
 * the same instant can compute the same code; the unique key rejects the
 * second, and the caller takes the next number and tries again.
 */
function isDuplicateCode(PDOException $e): bool {
    return $e->getCode() === '23000'
        && preg_match('/uq_(report|task|wo)_code/', $e->getMessage()) === 1;
}

// ── INPUT ────────────────────────────────────────────────────
function clean(mixed $val): string {
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$val));
}

/** Refuses text longer than its column, instead of the database cutting it short. */
function requireMaxLength(array $fields): void {
    foreach ($fields as $label => [$value, $max]) {
        if (mb_strlen((string)$value) > $max) {
            json_response(false, "$label can be at most $max characters.", [], 400);
        }
    }
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

/** True when a request carries more photos than one submission may. */
function tooManyPhotos(string $fieldName): bool {
    $names = $_FILES[$fieldName]['name'] ?? null;
    return is_array($names) && count(array_filter($names, 'strlen')) > MAX_PHOTOS_COUNT;
}

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

// ── ACCOUNT ERASURE ──────────────────────────────────────────

/**
 * Everything that would keep this Users row alive. The schema aims
 * eleven RESTRICT foreign keys at Users on purpose — an inspection
 * record must never lose its author — so this count is what decides
 * whether an account can be erased outright or has to be anonymised
 * where it stands.
 */
function accountRecordCounts(int $uid): array {
    $db = getDB();
    $q  = function (string $sql, int $binds) use ($db, $uid): int {
        $st = $db->prepare($sql);
        $st->execute(array_fill(0, $binds, $uid));
        return (int)$st->fetchColumn();
    };
    return [
        'reports'     => $q('SELECT COUNT(*) FROM InspectionReports WHERE submitted_by = ?', 1),
        'tasks'       => $q('SELECT COUNT(*) FROM InspectionTasks WHERE created_by = ? OR assigned_to = ?', 2),
        'work_orders' => $q('SELECT COUNT(*) FROM WorkOrders WHERE created_by = ? OR assigned_to = ?', 2),
        'crew'        => $q('SELECT COUNT(*) FROM WorkOrderAssignees WHERE user_id = ?', 1),
        'updates'     => $q('SELECT COUNT(*) FROM FieldWorkUpdates WHERE updated_by = ?', 1),
        'generated'   => $q('SELECT COUNT(*) FROM GeneratedReports WHERE generated_by = ?', 1),
        'owned_teams' => $q('SELECT COUNT(*) FROM Teams WHERE owner_id = ?', 1),
    ];
}

/**
 * Erases an account. The owner deleting their own (api/auth.php) and an
 * administrator erasing a former member (api/teams.php) both come here.
 *   no linked records → the Users row goes, and CASCADE takes the
 *                       memberships, invitations and reset codes.
 *   any linked record → the row stays and keeps its display name,
 *                       marked "(deleted)", because in a safety record
 *                       the author is part of the record. Every other
 *                       personal field is overwritten and the login is
 *                       closed for good.
 * Either way the email address is released, so the person can sign up
 * again later with the same one.
 *
 * $describe(kept) writes the log line. $keepRemoved keeps the "removed"
 * memberships, so a team that erased the person still lists them as
 * erased. Returns the number of linked records kept; throws, rolled back,
 * if anything fails.
 */
function eraseAccount(int $uid, ?int $actorId, string $logAction, callable $describe, bool $keepRemoved = false): int {
    $db   = getDB();
    $stmt = $db->prepare('SELECT name, email, avatar_path FROM Users WHERE user_id = ?');
    $stmt->execute([$uid]);
    $row  = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException("No account #$uid.");
    }
    $kept  = array_sum(accountRecordCounts($uid));
    $name  = (string)$row['name'];
    $email = (string)$row['email'];

    $db->beginTransaction();
    try {
        // Inside the transaction, so a rollback cannot leave behind a
        // line claiming a deletion that never happened. Deliberately
        // without the name: an erasure that writes the name into a
        // fresh log entry is not an erasure.
        logActivity($actorId, $logAction, 'user', $uid, $describe($kept));

        // Personal data that lives outside Users, keyed by the address.
        $db->prepare('DELETE FROM Invitations WHERE email = ?')->execute([$email]);
        $db->prepare('DELETE FROM PendingRegistrations WHERE email = ?')->execute([$email]);
        $db->prepare('DELETE FROM PasswordResetCodes WHERE user_id = ?')->execute([$uid]);

        // The address is written into the free text of the activity log
        // too — "Failed login attempt for: juan@…". It is unique, so
        // every line carrying it is this person's and can be rewritten
        // outright. Replaced rather than deleted, so the team's audit
        // trail keeps its shape.
        // That rewrites signed entries, so their chains are re-signed from
        // the first one touched when the request ends (config/logseal.php).
        $touched = $db->prepare(
            'SELECT COALESCE(team_id, 0) AS chain_key, MIN(chain_seq) AS from_seq
               FROM ActivityLogs WHERE description LIKE ? AND chain_seq IS NOT NULL
              GROUP BY COALESCE(team_id, 0)'
        );
        $touched->execute(['%' . $email . '%']);
        foreach ($touched->fetchAll() as $t) {
            resealLogFrom((int)$t['chain_key'], (int)$t['from_seq']);
        }
        $db->prepare('UPDATE ActivityLogs SET description = REPLACE(description, ?, ?) WHERE description LIKE ?')
           ->execute([$email, 'a deleted account', '%' . $email . '%']);

        // The name is deliberately left alone here. It is kept on the
        // account itself (see below), so rewriting it out of the log
        // would contradict that — and a name is not unique, so a global
        // rewrite would relabel a namesake's entries as well.

        if ($kept === 0) {
            $db->prepare('DELETE FROM Users WHERE user_id = ?')->execute([$uid]);
        } else {
            // The display name survives, marked. A supervisor looking at
            // a two-year-old work order still needs to know whose crew
            // did the job; "Deleted user" erases accountability along
            // with the person. Marked in the stored value rather than at
            // render time so every screen, export and email agrees
            // without eighteen queries having to opt in.
            //
            // Trimmed to 90 so the marker fits the varchar(100) column.
            $marked = mb_substr($name, 0, 90) . ' (deleted)';

            $db->prepare(
                'UPDATE Users
                    SET name = ?, email = ?, password_hash = ?,
                        gender = "prefer_not_to_say", birthdate = NULL, address = NULL, contact_number = NULL,
                        avatar_path = NULL, is_active = 0,
                        email_verified_at = NULL, terms_accepted_at = NULL
                  WHERE user_id = ?'
            )->execute([
                $marked,
                'deleted-' . $uid . '@deleted.invalid',
                // Not a usable password and not a hash of anything the
                // person knows: this login can never be used again.
                password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
                $uid,
            ]);
            // The membership rows outlive an anonymised user, so drop
            // them — no team should be able to list a ghost. Records
            // keep their author because every listing joins Users, not
            // TeamMembers. An administrator's erase keeps the "removed"
            // rows: those teams list the person as a former member, erased.
            $db->prepare($keepRemoved
                ? 'DELETE FROM TeamMembers WHERE user_id = ? AND status <> "removed"'
                : 'DELETE FROM TeamMembers WHERE user_id = ?')->execute([$uid]);
        }
        $db->commit();
    } catch (Throwable $e) {
        // commit() can throw too, and rolling back a transaction that is
        // no longer open throws again, burying the real error.
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    deleteAvatarFile($row['avatar_path']);
    return $kept;
}

/** True once eraseAccount() has anonymised the row. */
function isErasedAccount(array $user): bool {
    return !(int)$user['is_active'] && str_ends_with((string)$user['email'], '@deleted.invalid');
}
