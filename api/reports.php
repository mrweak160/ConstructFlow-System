<?php
// ConstructFlow — Inspection Reports API
// Handles: submit report, list reports, get single report

require_once __DIR__ . '/../config/helpers.php';
setCORSHeaders();
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── POST /api/reports.php?action=submit ───────────────────────
// Field Inspector submits a new inspection report.
// Multipart form: title, issue_type, description, location_text,
//                 latitude, longitude, severity, photo (file)
if ($method === 'POST' && $action === 'submit') {
    $user = requireRole('field_inspector');
    $body = $_POST; // multipart for photo upload

    $taskId        = (int)($body['task_id'] ?? 0);
    $title         = sanitize($body['title'] ?? '');
    $issue_type    = sanitize($body['issue_type'] ?? '');
    $description   = sanitize($body['description'] ?? '');
    $location_text = sanitize($body['location_text'] ?? '');
    $latitude      = isset($body['latitude'])  ? (float)$body['latitude']  : null;
    $longitude     = isset($body['longitude']) ? (float)$body['longitude'] : null;
    $severity      = in_array($body['severity'] ?? '', ['low','moderate','high','critical'])
                     ? $body['severity'] : 'low';

    if (!$taskId || !$title || !$description || !$location_text) {
        json_response(false, 'A valid assigned task, title, description, and location are required.', [], 400);
    }

    $db = getDB();

    // Verify the task exists, belongs to this inspector, and hasn't already been submitted
    $task = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND assigned_to = ?');
    $task->execute([$taskId, $user['user_id']]);
    $t = $task->fetch();

    if (!$t) {
        json_response(false, 'This task is not assigned to you.', [], 403);
    }
    if ($t['status'] !== 'assigned') {
        json_response(false, 'A report has already been submitted for this task.', [], 400);
    }

    $code = generateReportCode();

    $stmt = $db->prepare(
        'INSERT INTO InspectionReports
         (report_code, task_id, submitted_by, title, issue_type, description,
          location_text, latitude, longitude, severity)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $taskId,
        $user['user_id'],
        $title,
        $issue_type,
        $description,
        $location_text,
        $latitude,
        $longitude,
        $severity,
    ]);
    $reportId = (int)$db->lastInsertId();

    // Mark the task as submitted — it stays open until the Supervisor reviews and closes it
    $db->prepare('UPDATE InspectionTasks SET status = "submitted" WHERE task_id = ?')
       ->execute([$taskId]);

    // Handle photo upload(s) if provided — supports multiple photos per report
    $photoPaths = handleMultiplePhotoUploads('photos', $reportId);
    foreach ($photoPaths as $photoPath) {
        $db->prepare(
            'INSERT INTO PhotoEvidence (report_id, file_path, file_name, latitude, longitude)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $reportId,
            $photoPath,
            basename($photoPath),
            $latitude,
            $longitude,
        ]);
    }

    logActivity(
        $user['user_id'], 'submit_report',
        'report', $reportId,
        "{$user['name']} submitted inspection report $code."
    );

    json_response(true, 'Inspection report submitted successfully.', [
        'report_id'   => $reportId,
        'report_code' => $code,
    ]);
}

// ── GET /api/reports.php?action=list ─────────────────────────
// Returns reports based on role:
//   field_inspector → own reports only
//   supervisor      → all pending/assigned reports
//   administrator   → all reports
// Optional filters: ?severity=high&status=pending
if ($method === 'GET' && $action === 'list') {
    $user     = requireAuth();
    $db       = getDB();
    $severity = $_GET['severity'] ?? '';
    $status   = $_GET['status']   ?? '';

    $where  = [];
    $params = [];

    if ($user['role'] === 'field_inspector') {
        $where[]  = 'r.submitted_by = ?';
        $params[] = $user['user_id'];
    }
    if ($severity) {
        $where[]  = 'r.severity = ?';
        $params[] = $severity;
    }
    if ($status) {
        $where[]  = 'r.status = ?';
        $params[] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare(
        "SELECT r.*,
                u.name AS submitted_by_name,
                (SELECT file_path FROM PhotoEvidence WHERE report_id = r.report_id LIMIT 1) AS photo
         FROM InspectionReports r
         JOIN Users u ON r.submitted_by = u.user_id
         $whereSQL
         ORDER BY r.submitted_at DESC"
    );
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    json_response(true, 'Reports retrieved.', ['reports' => $reports]);
}

// ── GET /api/reports.php?action=detail&id=1 ───────────────────
// Returns a single report with photos and linked work order.
if ($method === 'GET' && $action === 'detail') {
    $user     = requireAuth();
    $reportId = (int)($_GET['id'] ?? 0);
    $db       = getDB();

    $stmt = $db->prepare(
        'SELECT r.*, u.name AS submitted_by_name
         FROM InspectionReports r
         JOIN Users u ON r.submitted_by = u.user_id
         WHERE r.report_id = ?'
    );
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();

    if (!$report) {
        json_response(false, 'Report not found.', [], 404);
    }

    // Get photos
    $photos = $db->prepare('SELECT * FROM PhotoEvidence WHERE report_id = ?');
    $photos->execute([$reportId]);

    // Get linked work order if any
    $wo = $db->prepare(
        'SELECT w.*, u.name AS assigned_to_name
         FROM WorkOrders w
         LEFT JOIN Users u ON w.assigned_to = u.user_id
         WHERE w.report_id = ?'
    );
    $wo->execute([$reportId]);

    json_response(true, 'Report detail retrieved.', [
        'report'     => $report,
        'photos'     => $photos->fetchAll(),
        'work_order' => $wo->fetch() ?: null,
    ]);
}

// ── GET /api/reports.php?action=stats ────────────────────────
// Dashboard stats for Field Inspector.
if ($method === 'GET' && $action === 'stats') {
    $user = requireRole('field_inspector', 'supervisor', 'administrator');
    $db   = getDB();

    $where  = $user['role'] === 'field_inspector' ? 'WHERE submitted_by = ?' : '';
    $params = $user['role'] === 'field_inspector' ? [$user['user_id']] : [];

    $today = date('Y-m-d');

    $todayCount = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM InspectionReports
         $where" . ($where ? ' AND ' : 'WHERE ') . "DATE(submitted_at) = ?"
    );
    $todayParams = $params;
    $todayParams[] = $today;
    $todayCount->execute($todayParams);

    $critical = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM InspectionReports
         $where" . ($where ? ' AND ' : 'WHERE ') . "severity = 'critical' AND status NOT IN ('completed','rejected')"
    );
    $critical->execute($params);

    $resolved = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM InspectionReports $where"
    );
    $resolved->execute($params);
    $total = (int)$resolved->fetchColumn();

    $resolvedCount = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM InspectionReports
         $where" . ($where ? ' AND ' : 'WHERE ') . "status = 'completed'"
    );
    $resolvedCount->execute($params);
    $resolvedNum = (int)$resolvedCount->fetchColumn();

    $rate = $total > 0 ? round(($resolvedNum / $total) * 100) : 0;

    json_response(true, 'Stats retrieved.', [
        'reports_today'   => (int)$todayCount->fetchColumn(),
        'critical_flagged'=> (int)$critical->fetchColumn(),
        'resolution_rate' => $rate . '%',
        'total'           => $total,
    ]);
}

json_response(false, 'Invalid action.', [], 400);
