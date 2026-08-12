<?php
require_once __DIR__ . '/db.php';

// ── ROLES ────────────────────────────────────────────────────
// Single source of truth for every valid system role. Used to reject
// accounts with an unexpected/invalid role value at login time, and
// by any endpoint that needs to validate a role coming from a client.
const ALLOWED_ROLES = ['administrator', 'supervisor', 'field_inspector', 'field_worker'];

function isValidRole(string $role): bool {
    return in_array($role, ALLOWED_ROLES, true);
}

// ── RESPONSE ─────────────────────────────────────────────────
function json_response(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// ── SESSION AUTH ─────────────────────────────────────────────
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,   // set true when using HTTPS
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

    // Re-validate against the database on every request rather than trusting
    // the role/active flag captured at login time. Without this, an account
    // that an administrator deactivates or re-roles keeps acting under its
    // old privileges for the lifetime of its existing session (a
    // privilege-escalation / access-revocation gap), because a session
    // cookie alone was otherwise sufficient to keep using stale permissions.
    $db   = getDB();
    $stmt = $db->prepare('SELECT role, is_active FROM Users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$sessionUser['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_active'] || !isValidRole($row['role'])) {
        // Account deleted, deactivated, or left with an invalid role since
        // login — the session is no longer valid.
        session_destroy();
        return null;
    }

    // Keep the session's role in sync with the database so a role change
    // takes effect immediately instead of waiting for the next login.
    if ($row['role'] !== $sessionUser['role']) {
        $sessionUser['role'] = $row['role'];
        $_SESSION['user']    = $sessionUser;
    }

    return $sessionUser;
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) {
        json_response(false, 'Unauthorized. Please log in.', [], 401);
    }
    return $user;
}

function requireRole(string ...$roles): array {
    $user = requireAuth();
    if (!in_array($user['role'], $roles)) {
        json_response(false, 'Access denied. Insufficient permissions.', [], 403);
    }
    return $user;
}

// ── ACTIVITY LOG ─────────────────────────────────────────────
function logActivity(
    ?int $userId,
    string $action,
    string $targetType = null,
    int $targetId = null,
    string $description = null
): void {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO ActivityLogs (user_id, action, target_type, target_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            $targetType,
            $targetId,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // logging failure should not break the request
    }
}

// ── CODE GENERATORS ──────────────────────────────────────────
function generateReportCode(): string {
    $db   = getDB();
    $row  = $db->query('SELECT COUNT(*) AS cnt FROM InspectionReports')->fetch();
    $next = (int)$row['cnt'] + 1;
    return 'REP-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

function generateTaskCode(): string {
    $db   = getDB();
    $row  = $db->query('SELECT COUNT(*) AS cnt FROM InspectionTasks')->fetch();
    $next = (int)$row['cnt'] + 1;
    return 'TASK-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

function generateWOCode(): string {
    $db   = getDB();
    $row  = $db->query('SELECT COUNT(*) AS cnt FROM WorkOrders')->fetch();
    $next = (int)$row['cnt'] + 1;
    return 'WO-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

// ── INPUT SANITIZATION ───────────────────────────────────────
function sanitize(mixed $val): string {
    return htmlspecialchars(strip_tags(trim((string)$val)), ENT_QUOTES, 'UTF-8');
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? $_POST;
}

// ── FILE UPLOAD ───────────────────────────────────────────────
function handlePhotoUpload(string $fieldName, int $reportId): ?string {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime    = mime_content_type($_FILES[$fieldName]['tmp_name']);
    if (!in_array($mime, $allowed)) return null;

    $ext      = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
    $filename = 'REP' . $reportId . '_' . time() . '_' . uniqid() . '.' . $ext;
    $dir      = __DIR__ . '/../uploads/photos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest     = $dir . $filename;

    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dest)) {
        return 'uploads/photos/' . $filename;
    }
    return null;
}

function handleMultiplePhotoUploads(string $fieldName, int $reportId): array {
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName]['name'])) {
        return [];
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $dir     = __DIR__ . '/../uploads/photos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $saved = [];
    $count = count($_FILES[$fieldName]['name']);

    for ($i = 0; $i < $count; $i++) {
        if ($_FILES[$fieldName]['error'][$i] !== UPLOAD_ERR_OK) continue;

        $tmpName = $_FILES[$fieldName]['tmp_name'][$i];
        $mime    = mime_content_type($tmpName);
        if (!in_array($mime, $allowed)) continue;

        $ext      = pathinfo($_FILES[$fieldName]['name'][$i], PATHINFO_EXTENSION);
        $filename = 'REP' . $reportId . '_' . time() . '_' . uniqid() . '.' . $ext;
        $dest     = $dir . $filename;

        if (move_uploaded_file($tmpName, $dest)) {
            $saved[] = 'uploads/photos/' . $filename;
        }
    }
    return $saved;
}

// ── CORS HEADERS (for local dev) ─────────────────────────────
function setCORSHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
