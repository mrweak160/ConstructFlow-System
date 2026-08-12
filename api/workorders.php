<?php
// ConstructFlow — Work Orders API
// Handles: create, assign, list, update status

require_once __DIR__ . '/../config/helpers.php';
setCORSHeaders();
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── POST /api/workorders.php?action=create ────────────────────
// Supervisor creates a work order from an inspection report.
// Body: { report_id, assigned_to, severity, instructions, deadline }
if ($method === 'POST' && $action === 'create') {
    $user = requireRole('supervisor');
    $body = getBody();

    $reportId    = (int)($body['report_id']    ?? 0);
    $assignedTo  = (int)($body['assigned_to']  ?? 0);
    $severity    = in_array($body['severity'] ?? '', ['low','moderate','high','critical'])
                   ? $body['severity'] : null;
    $instructions = sanitize($body['instructions'] ?? '');
    $deadline     = sanitize($body['deadline'] ?? '');

    if (!$reportId || !$assignedTo || !$severity) {
        json_response(false, 'Report, assigned worker, and severity are required.', [], 400);
    }

    $db = getDB();

    // Verify the report exists and is not already assigned
    $report = $db->prepare('SELECT * FROM InspectionReports WHERE report_id = ?');
    $report->execute([$reportId]);
    $r = $report->fetch();
    if (!$r) {
        json_response(false, 'Inspection report not found.', [], 404);
    }

    // Verify the assigned user is a field worker
    $worker = $db->prepare('SELECT * FROM Users WHERE user_id = ? AND role = "field_worker" AND is_active = 1');
    $worker->execute([$assignedTo]);
    if (!$worker->fetch()) {
        json_response(false, 'Assigned user is not an active field worker.', [], 400);
    }

    $code = generateWOCode();

    $stmt = $db->prepare(
        'INSERT INTO WorkOrders
         (wo_code, report_id, created_by, assigned_to, severity, instructions, deadline)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $reportId,
        $user['user_id'],
        $assignedTo,
        $severity,
        $instructions,
        $deadline ?: null,
    ]);
    $woId = (int)$db->lastInsertId();

    // Update report status to assigned
    $db->prepare('UPDATE InspectionReports SET status = "assigned" WHERE report_id = ?')
       ->execute([$reportId]);

    logActivity(
        $user['user_id'], 'create_work_order',
        'work_order', $woId,
        "{$user['name']} created and assigned work order $code."
    );

    json_response(true, 'Work order created and assigned successfully.', [
        'wo_id'   => $woId,
        'wo_code' => $code,
    ]);
}

// ── GET /api/workorders.php?action=list ───────────────────────
// Returns work orders based on role:
//   supervisor    → all work orders with filter options
//   field_worker  → only work orders assigned to them
//   administrator → all work orders
if ($method === 'GET' && $action === 'list') {
    $user   = requireAuth();
    $db     = getDB();
    $status = $_GET['status'] ?? '';

    $where  = [];
    $params = [];

    if ($user['role'] === 'field_worker') {
        $where[]  = 'w.assigned_to = ?';
        $params[] = $user['user_id'];
    }
    if ($status) {
        $where[]  = 'w.status = ?';
        $params[] = $status;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare(
        "SELECT w.*,
                r.report_code, r.title AS report_title, r.location_text,
                u_creator.name  AS created_by_name,
                u_worker.name   AS assigned_to_name,
                (SELECT status FROM FieldWorkUpdates WHERE wo_id = w.wo_id ORDER BY updated_at DESC LIMIT 1) AS latest_update_status,
                (SELECT remarks FROM FieldWorkUpdates WHERE wo_id = w.wo_id ORDER BY updated_at DESC LIMIT 1) AS latest_remarks
         FROM WorkOrders w
         JOIN InspectionReports r ON w.report_id = r.report_id
         JOIN Users u_creator ON w.created_by = u_creator.user_id
         LEFT JOIN Users u_worker ON w.assigned_to = u_worker.user_id
         $whereSQL
         ORDER BY w.created_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Work orders retrieved.', ['work_orders' => $stmt->fetchAll()]);
}

// ── GET /api/workorders.php?action=detail&id=1 ────────────────
// Returns a single work order with all its field work updates.
if ($method === 'GET' && $action === 'detail') {
    $user = requireAuth();
    $woId = (int)($_GET['id'] ?? 0);
    $db   = getDB();

    $stmt = $db->prepare(
        'SELECT w.*,
                r.report_code, r.title AS report_title, r.location_text, r.description AS report_description,
                u_creator.name AS created_by_name,
                u_worker.name  AS assigned_to_name
         FROM WorkOrders w
         JOIN InspectionReports r ON w.report_id = r.report_id
         JOIN Users u_creator ON w.created_by = u_creator.user_id
         LEFT JOIN Users u_worker ON w.assigned_to = u_worker.user_id
         WHERE w.wo_id = ?'
    );
    $stmt->execute([$woId]);
    $wo = $stmt->fetch();

    if (!$wo) {
        json_response(false, 'Work order not found.', [], 404);
    }

    // Field worker can only see their own work orders
    if ($user['role'] === 'field_worker' && $wo['assigned_to'] !== $user['user_id']) {
        json_response(false, 'Access denied.', [], 403);
    }

    // Get all field work updates for this WO
   // Get all field work updates for this WO
    $updates = $db->prepare(
        'SELECT fu.*, u.name AS updated_by_name
         FROM FieldWorkUpdates fu
         JOIN Users u ON fu.updated_by = u.user_id
         WHERE fu.wo_id = ?
         ORDER BY fu.updated_at DESC'
    );
    $updates->execute([$woId]);
    $allUpdates = $updates->fetchAll();

    // Attach photos to each update (one-to-many, same pattern as report photos)
    if ($allUpdates) {
        $updateIds    = array_column($allUpdates, 'update_id');
        $placeholders = implode(',', array_fill(0, count($updateIds), '?'));
        $photoStmt = $db->prepare("SELECT * FROM FieldWorkPhotos WHERE update_id IN ($placeholders) ORDER BY photo_id");
        $photoStmt->execute($updateIds);

        $photosByUpdate = [];
        foreach ($photoStmt->fetchAll() as $p) {
            $photosByUpdate[$p['update_id']][] = $p;
        }
        foreach ($allUpdates as &$u) {
            $u['photos'] = $photosByUpdate[$u['update_id']] ?? [];
        }
        unset($u);
    }

    json_response(true, 'Work order detail retrieved.', [
        'work_order' => $wo,
        'updates'    => $allUpdates,
    ]);
}

// ── POST /api/workorders.php?action=update_status ─────────────
// Field Worker updates the status of their work order.
// Body: { wo_id, status, remarks } + optional photo file
if ($method === 'POST' && $action === 'update_status') {
    $user  = requireRole('field_worker', 'supervisor');
    $body  = $_POST ?: getBody();
    $woId  = (int)($body['wo_id'] ?? 0);
    $status= in_array($body['status'] ?? '', ['in_progress','on_hold','completed'])
             ? $body['status'] : null;
    $remarks = sanitize($body['remarks'] ?? '');

    if (!$woId || !$status) {
        json_response(false, 'Work order ID and status are required.', [], 400);
    }

    $db = getDB();

    // Verify the work order exists and belongs to this field worker
    $wo = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ?');
    $wo->execute([$woId]);
    $w = $wo->fetch();

    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }
    if ($user['role'] === 'field_worker' && $w['assigned_to'] != $user['user_id']) {
        json_response(false, 'You can only update your own work orders.', [], 403);
    }

// Insert field work update
    $db->prepare(
        'INSERT INTO FieldWorkUpdates (wo_id, updated_by, status, remarks)
         VALUES (?, ?, ?, ?)'
    )->execute([$woId, $user['user_id'], $status, $remarks]);
    $updateId = $db->lastInsertId();

    // Handle completion evidence photo(s) — supports multiple
    $photoPaths = handleMultiplePhotoUploads('photos', $woId);
    foreach ($photoPaths as $photoPath) {
        $db->prepare(
            'INSERT INTO FieldWorkPhotos (update_id, file_path, file_name)
             VALUES (?, ?, ?)'
        )->execute([$updateId, $photoPath, basename($photoPath)]);
    }

    // Update work order status
    $db->prepare('UPDATE WorkOrders SET status = ? WHERE wo_id = ?')
       ->execute([$status, $woId]);

    // If completed, also mark the parent report as completed
    if ($status === 'completed') {
        $db->prepare('UPDATE InspectionReports SET status = "completed" WHERE report_id = ?')
           ->execute([$w['report_id']]);
    } elseif ($status === 'in_progress') {
        $db->prepare('UPDATE InspectionReports SET status = "in_progress" WHERE report_id = ?')
           ->execute([$w['report_id']]);
    }

    logActivity(
        $user['user_id'], 'update_work_order',
        'work_order', $woId,
        "{$user['name']} updated work order {$w['wo_code']} to '$status'."
    );

    json_response(true, 'Work order status updated successfully.');
}

// ── GET /api/workorders.php?action=stats ─────────────────────
// Dashboard stats for Supervisor.
if ($method === 'GET' && $action === 'stats') {
    $user = requireRole('supervisor', 'administrator');
    $db   = getDB();

    $total    = $db->query('SELECT COUNT(*) FROM WorkOrders')->fetchColumn();
    $ongoing  = $db->query("SELECT COUNT(*) FROM WorkOrders WHERE status IN ('pending','in_progress')")->fetchColumn();
    $completed= $db->query("SELECT COUNT(*) FROM WorkOrders WHERE status = 'completed'")->fetchColumn();
    $highSev  = $db->query("SELECT COUNT(*) FROM WorkOrders WHERE severity IN ('high','critical') AND status != 'completed'")->fetchColumn();

    $totalRep = $db->query('SELECT COUNT(*) FROM InspectionReports')->fetchColumn();
    $pending  = $db->query("SELECT COUNT(*) FROM InspectionReports WHERE status = 'pending'")->fetchColumn();

    json_response(true, 'Supervisor stats retrieved.', [
        'total_reports'   => (int)$totalRep,
        'pending_reports' => (int)$pending,
        'ongoing_orders'  => (int)$ongoing,
        'completed_orders'=> (int)$completed,
        'high_severity'   => (int)$highSev,
    ]);
}

json_response(false, 'Invalid action.', [], 400);
