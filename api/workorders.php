<?php
// ConstructFlow — Work Orders API
// create · list · detail · update_status · verify · update_assignees · update_details · reassign_verifier


require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

// ── POST ?action=create ──────────────────────────────────────
// Body: { report_id, assigned_to, severity, instructions, deadline }
if ($method === 'POST' && $action === 'create') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $reportId     = (int)($body['report_id']   ?? 0);
    $workerIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($body['worker_ids'] ?? []))
    )));
    $leadId    = (int)($body['lead_id'] ?? 0);
    // Backwards compatible with the old single-worker field.
    if (!$workerIds && !empty($body['assigned_to'])) {
        $workerIds = [(int)$body['assigned_to']];
    }
    if (count($workerIds) === 1) {
        $leadId = $workerIds[0];
    }
    $assignedTo = $leadId;
    $severity     = in_array($body['severity'] ?? '', ['low','moderate','high','critical'], true)
                    ? $body['severity'] : null;
    $instructions = clean($body['instructions'] ?? '');
    $deadline     = clean($body['deadline'] ?? '');

    if (!$reportId || !$workerIds || !$severity) {
        json_response(false, 'Report, at least one field worker, and severity are required.', [], 400);
    }
    // Same rule as the inspection task: a deadline is optional, but it cannot
    // already have passed. The client <input min> is a hint, not a boundary.
    if ($deadline !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $deadline);
        if (!$d || $d->format('Y-m-d') !== $deadline) {
            json_response(false, 'Enter a valid deadline.', [], 400);
        }
        // Compared by the database, for the same reason as the task due date.
        $past = getDB()->prepare('SELECT CAST(? AS DATE) < CURDATE()');
        $past->execute([$deadline]);
        if ((int)$past->fetchColumn() === 1) {
            json_response(false, 'The deadline must be today or a later date.', [], 400);
        }
    }
    // Critical work may carry three people; anything else, two. Enforced
    // here because a client-side limit is not a boundary.
    $maxWorkers = in_array($severity, ['high','critical'], true) ? 3 : 2;
    if (count($workerIds) > $maxWorkers) {
        json_response(false,
            "A $severity work order may have at most $maxWorkers field worker"
            . ($maxWorkers === 1 ? '' : 's') . '.', [], 400);
    }
    if (!$leadId || !in_array($leadId, $workerIds, true)) {
        json_response(false, 'Choose which worker leads this work order.', [], 400);
    }

    $db = getDB();

    $report = $db->prepare('SELECT * FROM InspectionReports WHERE report_id = ? AND team_id = ?');
    $report->execute([$reportId, $teamId]);
    $reportRow = $report->fetch();
    if (!$reportRow) {
        json_response(false, 'Inspection report not found.', [], 404);
    }

    // The check the original file described but never performed.
    $dupe = $db->prepare('SELECT wo_code FROM WorkOrders WHERE report_id = ? AND team_id = ? LIMIT 1');
    $dupe->execute([$reportId, $teamId]);
    if ($existing = $dupe->fetch()) {
        json_response(false, "A work order ({$existing['wo_code']}) already exists for this report.", [], 409);
    }

    // Assignee must be an active field worker IN THIS TEAM.
    $worker = $db->prepare(
        'SELECT 1 FROM TeamMembers tm
         JOIN Users u ON tm.user_id = u.user_id
         WHERE tm.team_id = ? AND tm.user_id = ?
           AND tm.role = "field_worker" AND tm.status = "active" AND u.is_active = 1'
    );
    foreach ($workerIds as $wid) {
        $worker->execute([$teamId, $wid]);
        if (!$worker->fetch()) {
            json_response(false, 'One of the people chosen is not an active field worker in this team.', [], 400);
        }
    }

    // nextCode() can hand two simultaneous requests the same number; the
    // unique key turns the second away, so take the next one and retry.
    for ($try = 1; ; $try++) {
        $code = generateWOCode($teamId);
        try {
            $db->prepare(
                'INSERT INTO WorkOrders
                 (team_id, wo_code, report_id, created_by, assigned_to, severity, instructions, deadline)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$teamId, $code, $reportId, $actor['user_id'], $assignedTo, $severity, $instructions, $deadline ?: null]);
            break;
        } catch (PDOException $e) {
            if ($try >= 3 || !isDuplicateCode($e)) throw $e;
        }
    }
    $woId = (int)$db->lastInsertId();

    $ins = $db->prepare('INSERT INTO WorkOrderAssignees (wo_id, user_id, is_lead) VALUES (?, ?, ?)');
    foreach ($workerIds as $wid) {
        $ins->execute([$woId, $wid, $wid === $leadId ? 1 : 0]);
    }

    // The supervisor changed the engine's recommendation.
    $db->prepare(
        'UPDATE InspectionReports
            SET severity = ?, severity_overridden_by = ?, severity_overridden_at = NOW()
          WHERE report_id = ? AND team_id = ?
            AND recommended_severity IS NOT NULL
            AND recommended_severity <> ?'
    )->execute([$severity, $actor['user_id'], $reportId, $teamId, $severity]);

    $db->prepare('UPDATE InspectionReports SET status = "assigned" WHERE report_id = ? AND team_id = ?')
       ->execute([$reportId, $teamId]);

    // The inspection task is finished the moment its finding is acted on.
    // The inspector was asked to go and look; they looked, they filed, and a
    // field worker now holds the repair — there is nothing further to submit
    // against the task, so leaving it open only invited a supervisor to close
    // it by hand afterwards. The guard on status keeps this idempotent and
    // leaves an already-closed task alone.
    $taskId = (int)($reportRow['task_id'] ?? 0);
    if ($taskId) {
        $closeTask = $db->prepare(
            'UPDATE InspectionTasks SET status = "closed"
              WHERE task_id = ? AND team_id = ? AND status <> "closed"'
        );
        $closeTask->execute([$taskId, $teamId]);
        if ($closeTask->rowCount() > 0) {
            logActivity($actor['user_id'], 'close_task', 'task', $taskId,
                "{$actor['name']} closed the inspection task when work order $code was assigned.", $teamId);
        }
    }

    notifyUsers($workerIds, $teamId, "New work order: $code",
        "{$actor['name']} assigned you to a work order.", woDetails($woId) + ['Instructions' => $instructions]);

    logActivity($actor['user_id'], 'create_work_order', 'work_order', $woId,
        "{$actor['name']} created and assigned work order $code.", $teamId);

    json_response(true, 'Work order created and assigned successfully.', [
        'wo_id'   => $woId,
        'wo_code' => $code,
    ]);
}

// ── GET ?action=list ─────────────────────────────────────────
// field_worker → only their own work orders
// supervisor   → all work orders in the team
if ($method === 'GET' && $action === 'list') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $where  = ['w.team_id = ?'];
    $params = [$teamId];

    if ($m['role'] === 'field_worker') {
        // Support workers see the job too; only the lead can act on it.
        $where[]  = 'w.wo_id IN (SELECT wo_id FROM WorkOrderAssignees WHERE user_id = ?)';
        $params[] = $actor['user_id'];
    }
    if ($status = ($_GET['status'] ?? '')) {
        $where[]  = 'w.status = ?';
        $params[] = $status;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT w.*,
                r.report_code, r.title AS report_title, r.location_text, r.address_text,
                t.location_text AS task_location,
                u_creator.name AS created_by_name,
                u_worker.name  AS assigned_to_name,
                vi.name        AS verify_inspector_name,
                                (SELECT GROUP_CONCAT(u2.name ORDER BY a2.is_lead DESC, u2.name SEPARATOR 0x1F) FROM WorkOrderAssignees a2 JOIN Users u2 ON a2.user_id = u2.user_id WHERE a2.wo_id = w.wo_id) AS crew_names,
                                (SELECT GROUP_CONCAT(u2.user_id ORDER BY a2.is_lead DESC, u2.name SEPARATOR ',') FROM WorkOrderAssignees a2 JOIN Users u2 ON a2.user_id = u2.user_id WHERE a2.wo_id = w.wo_id) AS crew_ids,
                (SELECT status  FROM FieldWorkUpdates WHERE wo_id = w.wo_id ORDER BY updated_at DESC LIMIT 1) AS latest_update_status,
                (SELECT remarks FROM FieldWorkUpdates WHERE wo_id = w.wo_id ORDER BY updated_at DESC LIMIT 1) AS latest_remarks
         FROM WorkOrders w
         JOIN InspectionReports r ON w.report_id = r.report_id
         JOIN InspectionTasks   t ON r.task_id   = t.task_id
         JOIN Users u_creator ON w.created_by = u_creator.user_id
         LEFT JOIN Users u_worker ON w.assigned_to = u_worker.user_id
         LEFT JOIN Users vi ON w.verify_inspector_id = vi.user_id
         $whereSQL
         ORDER BY FIELD(w.severity,'critical','high','moderate','low'), w.created_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Work orders retrieved.', [
        'work_orders' => markFormerMembers($stmt->fetchAll(), $teamId, [
            'created_by'          => 'created_by_name',
            'assigned_to'         => 'assigned_to_name',
            'verify_inspector_id' => 'verify_inspector_name',
            'crew_ids'            => 'crew_names',
        ]),
    ]);
}

// ── GET ?action=detail&id=1 ──────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $woId   = (int)($_GET['id'] ?? 0);
    $db     = getDB();

    $stmt = $db->prepare(
        'SELECT w.*,
                r.report_code, r.title AS report_title, r.location_text, r.address_text,
                t.location_text AS task_location,
                r.description AS report_description,
                r.submitted_by,
                u_creator.name AS created_by_name,
                u_worker.name  AS assigned_to_name,
                vi.name        AS verify_inspector_name,
                (SELECT GROUP_CONCAT(u2.name ORDER BY a2.is_lead DESC, u2.name SEPARATOR 0x1F) FROM WorkOrderAssignees a2 JOIN Users u2 ON a2.user_id = u2.user_id WHERE a2.wo_id = w.wo_id) AS crew_names,
                (SELECT GROUP_CONCAT(u2.user_id ORDER BY a2.is_lead DESC, u2.name SEPARATOR \',\') FROM WorkOrderAssignees a2 JOIN Users u2 ON a2.user_id = u2.user_id WHERE a2.wo_id = w.wo_id) AS crew_ids
         FROM WorkOrders w
         JOIN InspectionReports r ON w.report_id = r.report_id
         JOIN InspectionTasks   t ON r.task_id   = t.task_id
         JOIN Users u_creator ON w.created_by = u_creator.user_id
         LEFT JOIN Users u_worker ON w.assigned_to = u_worker.user_id
         LEFT JOIN Users vi ON w.verify_inspector_id = vi.user_id
         WHERE w.wo_id = ? AND w.team_id = ?'
    );
    $stmt->execute([$woId, $teamId]);
    $wo = $stmt->fetch();

    if (!$wo) {
        json_response(false, 'Work order not found.', [], 404);
    }
    $wo = markFormerMember($wo, $teamId, [
        'created_by'          => 'created_by_name',
        'assigned_to'         => 'assigned_to_name',
        'verify_inspector_id' => 'verify_inspector_name',
        'crew_ids'            => 'crew_names',
    ]);
    // Anyone on the crew may read the work order; only the lead may act on
    // it, which update_status enforces separately.
    if ($m['role'] === 'field_worker') {
        $onCrew = $db->prepare('SELECT 1 FROM WorkOrderAssignees WHERE wo_id = ? AND user_id = ?');
        $onCrew->execute([$woId, $actor['user_id']]);
        if (!$onCrew->fetch()) {
            json_response(false, 'Access denied.', [], 403);
        }
    }

    $updates = $db->prepare(
        'SELECT fu.*, u.name AS updated_by_name
         FROM FieldWorkUpdates fu
         JOIN Users u ON fu.updated_by = u.user_id
         WHERE fu.wo_id = ?
         ORDER BY fu.updated_at DESC'
    );
    $updates->execute([$woId]);
    $allUpdates = markFormerMembers($updates->fetchAll(), $teamId, ['updated_by' => 'updated_by_name']);

    // The crew as rows, lead first — the edit form needs ids, not names.
    $crew = $db->prepare(
        'SELECT a.user_id, a.is_lead, u.name
           FROM WorkOrderAssignees a JOIN Users u ON a.user_id = u.user_id
          WHERE a.wo_id = ? ORDER BY a.is_lead DESC, u.name'
    );
    $crew->execute([$woId]);
    $crewRows = markFormerMembers($crew->fetchAll(), $teamId, ['user_id' => 'name']);

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
        'crew'       => $crewRows,
    ]);
}

// ── POST ?action=update_status ───────────────────────────────
// Multipart: wo_id, status, remarks, photos[]
if ($method === 'POST' && $action === 'update_status') {
    // Field workers only. A supervisor acts through ?action=verify, never here:
    // allowing them in let a work order be driven straight to 'completed',
    // skipping both verification stages and leaving the parent report stranded
    // at 'assigned' with no signature recorded.
    $m      = requireTeamRole('field_worker');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = $_POST ?: getBody();

    $woId    = (int)($body['wo_id'] ?? 0);
    $status  = in_array($body['status'] ?? '', ['in_progress','on_hold','completed'], true)
               ? $body['status'] : null;

    // Marking work done never closes a work order; it enters verification.
    if ($status === 'completed') {
        $status = 'pending_supervisor';
    }
    $remarks = clean($body['remarks'] ?? '');

    if (!$woId || !$status) {
        json_response(false, 'Work order ID and status are required.', [], 400);
    }

    $db = getDB();
    $wo = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ? AND team_id = ?');
    $wo->execute([$woId, $teamId]);
    $w = $wo->fetch();

    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }
    // Only the lead may move the work order; support crew can view, not edit.
    if ((int)$w['assigned_to'] !== (int)$actor['user_id']) {
        json_response(false, 'You can only update your own work orders.', [], 403);
    }
   
    if (in_array($w['status'], ['completed','pending_supervisor','pending_inspector','pending_closure'], true)) {
        json_response(false, 'This work order is already submitted for verification.', [], 409);
    }

    $db->prepare(
        'INSERT INTO FieldWorkUpdates (wo_id, updated_by, status, remarks) VALUES (?, ?, ?, ?)'
    )->execute([$woId, $actor['user_id'], $status, $remarks]);
    $updateId = (int)$db->lastInsertId();

     $photoResult = handleMultiplePhotoUploads('photos', 'WO', $woId);
    foreach ($photoResult['saved'] as $photoPath) {
        $db->prepare(
            'INSERT INTO FieldWorkPhotos (update_id, file_path, file_name) VALUES (?, ?, ?)'
        )->execute([$updateId, $photoPath, basename($photoPath)]);
    }

    $db->prepare('UPDATE WorkOrders SET status = ? WHERE wo_id = ? AND team_id = ?')
       ->execute([$status, $woId, $teamId]);

    // Keep the parent report's status in step with the work order.
    // The report is only complete once the inspector has signed off, so
    // nothing here closes it — see the verify action below.
    $reportStatus = match ($status) {
        'in_progress' => 'in_progress',
        default                 => null,
    };

    if ($reportStatus) {
        $db->prepare('UPDATE InspectionReports SET status = ? WHERE report_id = ? AND team_id = ?')
           ->execute([$reportStatus, $w['report_id'], $teamId]);
    }

    if ($status === 'pending_supervisor') {
        notifyRole($teamId, 'supervisor', "Work done, ready for review: {$w['wo_code']}",
            "{$actor['name']} marked this work order as done. It is waiting for your review.",
            woDetails($woId) + ['Remarks' => $remarks]);
    }

    logActivity($actor['user_id'], 'update_work_order', 'work_order', $woId,
        "{$actor['name']} updated work order {$w['wo_code']} to '$status'.", $teamId);

    json_response(true, 'Work order status updated successfully.', [
        'photo_warnings' => $photoResult['errors'],
    ]);
    
}

// ── POST ?action=verify ──────────────────────────────────────
// Body: { wo_id, decision: approve|reject, remarks }
//
// Three stages, in order:
//   pending_supervisor -> supervisor checks the work and names an inspector
//   pending_inspector  -> that inspector confirms it on site
//   pending_closure    -> supervisor reviews the whole record and closes it
// Any stage may reject, which sends the job back to the field worker.
//
if ($method === 'POST' && $action === 'verify') {
    $m      = requireTeamRole('supervisor', 'field_inspector');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = $_POST ?: getBody();   // multipart when the inspector sends photos

    $woId     = (int)($body['wo_id'] ?? 0);
    $decision = in_array($body['decision'] ?? '', ['approve','reject'], true)
                ? $body['decision'] : null;
    $remarks  = clean($body['remarks'] ?? '');
    $verifyInspector = (int)($body['verify_inspector_id'] ?? 0);

    if (!$woId || !$decision) {
        json_response(false, 'Work order and decision are required.', [], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ? AND team_id = ?');
    $stmt->execute([$woId, $teamId]);
    $w = $stmt->fetch();
    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }

    // Each role may only act at its own stage. The supervisor owns two of
    // them: the first review and the final closure.
    $allowed = $m['role'] === 'supervisor'
             ? ['pending_supervisor', 'pending_closure']
             : ['pending_inspector'];
    if (!in_array($w['status'], $allowed, true)) {
        json_response(false, 'This work order is not awaiting your verification.', [], 409);
    }
    $stage = $w['status'];
    // Only the nominated inspector may sign off, the same way a field worker
    // may only update their own work order.
    if ($m['role'] === 'field_inspector'
        && (int)$w['verify_inspector_id'] !== (int)$actor['user_id']) {
        json_response(false, 'This verification is assigned to another inspector.', [], 403);
    }
    if ($decision === 'reject' && $remarks === '') {
        json_response(false, 'Please say what needs reworking.', [], 400);
    }

    // Photos from the site check. Saved before anything is recorded, so a
    // sign-off whose photos all fail (wrong type, too large) is refused rather
    // than going through without the proof it rests on.
    $photos = $stage === 'pending_inspector'
             ? handleMultiplePhotoUploads('photos', 'WO', $woId)
             : ['saved' => [], 'errors' => []];
    if ($stage === 'pending_inspector' && $decision === 'approve' && !$photos['saved']) {
        json_response(false, $photos['errors']
            ? 'None of the photos could be used. ' . implode(' ', $photos['errors'])
            : 'Add at least one photo of the finished work.', [], 400);
    }

    if ($decision === 'reject') {
        $newStatus = 'rework';              // back to the field worker from any stage
    } else {
        $newStatus = match ($stage) {
            'pending_supervisor' => 'pending_inspector',
            'pending_inspector'  => 'pending_closure',
            'pending_closure'    => 'completed',
        };
    }

    // Approving as supervisor means handing the site to a named inspector,
    // not leaving it for whoever happens to look.
    if ($newStatus === 'pending_inspector') {
        if (!$verifyInspector) {
            json_response(false, 'Choose an inspector to verify the work on site.', [], 400);
        }
        $chk = $db->prepare(
            'SELECT 1 FROM TeamMembers tm JOIN Users u ON tm.user_id = u.user_id
              WHERE tm.team_id = ? AND tm.user_id = ?
                AND tm.role = "field_inspector" AND tm.status = "active" AND u.is_active = 1'
        );
        $chk->execute([$teamId, $verifyInspector]);
        if (!$chk->fetch()) {
            json_response(false, 'That person is not an active field inspector in this team.', [], 400);
        }
        $db->prepare('UPDATE WorkOrders SET verify_inspector_id = ? WHERE wo_id = ? AND team_id = ?')
           ->execute([$verifyInspector, $woId, $teamId]);
    }

    $db->prepare(
        'INSERT INTO FieldWorkUpdates (wo_id, updated_by, status, remarks) VALUES (?, ?, ?, ?)'
    )->execute([$woId, $actor['user_id'], $newStatus, $remarks]);
    $updateId = (int)$db->lastInsertId();
    foreach ($photos['saved'] as $photoPath) {
        $db->prepare(
            'INSERT INTO FieldWorkPhotos (update_id, file_path, file_name) VALUES (?, ?, ?)'
        )->execute([$updateId, $photoPath, basename($photoPath)]);
    }

    // Each stage stamps its own column, so the closure never overwrites the
    // first supervisor review. A rejection stamps nothing and only moves the
    // status: stamping it recorded a review time with no reviewer, and at
    // final closure set closed_at on a job that had just been reopened —
    // which the summaries, the workload ranking and the printed case file
    // all read as closed.
    if ($decision === 'reject') {
        $db->prepare('UPDATE WorkOrders SET status = ? WHERE wo_id = ? AND team_id = ?')
           ->execute([$newStatus, $woId, $teamId]);
    } elseif ($stage === 'pending_supervisor') {
        $db->prepare(
            'UPDATE WorkOrders SET status = ?, supervisor_verified_by = ?, supervisor_verified_at = NOW()
              WHERE wo_id = ? AND team_id = ?'
        )->execute([$newStatus, $actor['user_id'], $woId, $teamId]);
    } elseif ($stage === 'pending_inspector') {
        $db->prepare(
            'UPDATE WorkOrders SET status = ?, inspector_verified_by = ?, inspector_verified_at = NOW()
              WHERE wo_id = ? AND team_id = ?'
        )->execute([$newStatus, $actor['user_id'], $woId, $teamId]);
    } else {   // pending_closure
        $db->prepare(
            'UPDATE WorkOrders SET status = ?, closed_by = ?, closed_at = NOW()
              WHERE wo_id = ? AND team_id = ?'
        )->execute([$newStatus, $actor['user_id'], $woId, $teamId]);
    }

    // The report closes only when the supervisor closes the work order.
    if ($newStatus === 'completed') {
        $db->prepare('UPDATE InspectionReports SET status = "completed" WHERE report_id = ? AND team_id = ?')
           ->execute([$w['report_id'], $teamId]);
    } elseif ($newStatus === 'rework') {
        $db->prepare('UPDATE InspectionReports SET status = "in_progress" WHERE report_id = ? AND team_id = ?')
           ->execute([$w['report_id'], $teamId]);
    }

    // Tell whoever holds the work order now.
    $facts = woDetails($woId);
    if ($decision === 'reject') {
        notifyUsers(woCrewIds($woId), $teamId, "Rework needed: {$w['wo_code']}",
            "{$actor['name']} sent this work order back for rework.", $facts + ['What to fix' => $remarks]);
    } elseif ($newStatus === 'pending_inspector') {
        notifyUsers([$verifyInspector], $teamId, "Site check needed: {$w['wo_code']}",
            "{$actor['name']} asked you to check the finished work on site.", $facts);
    } elseif ($newStatus === 'pending_closure') {
        notifyRole($teamId, 'supervisor', "Checked on site, ready to close: {$w['wo_code']}",
            "{$actor['name']} checked the finished work on site and approved it.", $facts + ['Remarks' => $remarks]);
    } else {
        notifyUsers(woCrewIds($woId), $teamId, "Work order closed: {$w['wo_code']}",
            "{$actor['name']} closed this work order. The job is complete.", $facts);
    }

    $stageLabel = match ($stage) {
        'pending_supervisor' => 'supervisor review',
        'pending_inspector'  => 'site verification',
        'pending_closure'    => 'final closure',
    };
    logActivity($actor['user_id'], 'verify_work_order', 'work_order', $woId,
        "{$actor['name']} {$decision}d work order {$w['wo_code']} at {$stageLabel}.", $teamId);

    json_response(true, $decision === 'approve'
        ? match ($newStatus) {
            'pending_inspector' => 'Approved — sent to the inspector for site verification.',
            'pending_closure'   => 'Verified on site — sent back to the supervisor to close.',
            'completed'         => 'Work order closed. The whole job is complete.',
          }
        : 'Sent back for rework.',
        ['status' => $newStatus, 'photo_warnings' => $photos['errors']]);
}

// ── POST ?action=update_assignees ────────────────────────────
// Body: { wo_id, worker_ids: [], lead_id }
//
if ($method === 'POST' && $action === 'update_assignees') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $woId      = (int)($body['wo_id'] ?? 0);
    $workerIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($body['worker_ids'] ?? []))
    )));
    $leadId = (int)($body['lead_id'] ?? 0);
    if (count($workerIds) === 1) { $leadId = $workerIds[0]; }

    if (!$woId || !$workerIds) {
        json_response(false, 'Work order and at least one field worker are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ? AND team_id = ?');
    $stmt->execute([$woId, $teamId]);
    $w = $stmt->fetch();
    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }
    if ($w['status'] === 'completed') {
        json_response(false, 'This work order is already closed.', [], 409);
    }

    $maxWorkers = in_array($w['severity'], ['high','critical'], true) ? 3 : 2;
    if (count($workerIds) > $maxWorkers) {
        json_response(false, "A {$w['severity']} work order may have at most $maxWorkers field workers.", [], 400);
    }
    if (!$leadId || !in_array($leadId, $workerIds, true)) {
        json_response(false, 'Choose which worker leads this work order.', [], 400);
    }

    $chk = $db->prepare(
        'SELECT 1 FROM TeamMembers tm JOIN Users u ON tm.user_id = u.user_id
          WHERE tm.team_id = ? AND tm.user_id = ?
            AND tm.role = "field_worker" AND tm.status = "active" AND u.is_active = 1'
    );
    foreach ($workerIds as $wid) {
        $chk->execute([$teamId, $wid]);
        if (!$chk->fetch()) {
            json_response(false, 'One of the people chosen is not an active field worker in this team.', [], 400);
        }
    }

    $before = woCrewIds($woId);

    // Replace the roster wholesale — simpler than diffing, and the table
    // carries no per-assignee state that would be lost.
    $db->prepare('DELETE FROM WorkOrderAssignees WHERE wo_id = ?')->execute([$woId]);
    $ins = $db->prepare('INSERT INTO WorkOrderAssignees (wo_id, user_id, is_lead) VALUES (?, ?, ?)');
    foreach ($workerIds as $wid) {
        $ins->execute([$woId, $wid, $wid === $leadId ? 1 : 0]);
    }
    $db->prepare('UPDATE WorkOrders SET assigned_to = ? WHERE wo_id = ? AND team_id = ?')
       ->execute([$leadId, $woId, $teamId]);

    $facts = woDetails($woId);
    notifyUsers(array_diff($workerIds, $before), $teamId, "New work order: {$w['wo_code']}",
        "{$actor['name']} added you to a work order.", $facts + ['Instructions' => $w['instructions']]);
    notifyUsers(array_diff($before, $workerIds), $teamId, "Removed from work order: {$w['wo_code']}",
        "{$actor['name']} took you off this work order. You no longer need to work on it.", $facts);

    logActivity($actor['user_id'], 'update_assignees', 'work_order', $woId,
        "{$actor['name']} updated the crew on work order {$w['wo_code']}.", $teamId);

    json_response(true, 'Crew updated.');
}

// ── POST ?action=update_details ─────────────────────────────
// Body: { wo_id, instructions, deadline }
//
// The supervisor's correction to an open work order. Severity and crew are
// not here: severity sets the crew limit, and the crew has its own action.
if ($method === 'POST' && $action === 'update_details') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $woId         = (int)($body['wo_id'] ?? 0);
    $instructions = clean($body['instructions'] ?? '');
    $deadline     = clean($body['deadline'] ?? '');
    if (!$woId) {
        json_response(false, 'Work order is required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ? AND team_id = ?');
    $stmt->execute([$woId, $teamId]);
    $w = $stmt->fetch();
    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }
    if ($w['status'] === 'completed') {
        json_response(false, 'This work order is already closed.', [], 409);
    }
    if ($deadline !== '' && $deadline !== (string)($w['deadline'] ?? '')) {
        $d = DateTime::createFromFormat('Y-m-d', $deadline);
        if (!$d || $d->format('Y-m-d') !== $deadline) {
            json_response(false, 'Enter a valid date.', [], 400);
        }
        $past = getDB()->prepare('SELECT CAST(? AS DATE) < CURDATE()');
        $past->execute([$deadline]);
        if ((int)$past->fetchColumn() === 1) {
            json_response(false, 'The date must be today or a later date.', [], 400);
        }
    }

    $db->prepare('UPDATE WorkOrders SET instructions = ?, deadline = ? WHERE wo_id = ? AND team_id = ?')
       ->execute([$instructions, $deadline ?: null, $woId, $teamId]);

    notifyUsers(woCrewIds($woId), $teamId, "Work order updated: {$w['wo_code']}",
        "{$actor['name']} changed the instructions or deadline of this work order.",
        woDetails($woId) + ['Instructions' => $instructions]);

    logActivity($actor['user_id'], 'update_work_order_details', 'work_order', $woId,
        "{$actor['name']} edited the details of work order {$w['wo_code']}.", $teamId);

    json_response(true, "Work order {$w['wo_code']} updated.");
}

// ── POST ?action=reassign_verifier ───────────────────────────
// Body: { wo_id, verify_inspector_id }
//
// Hands a site verification to another inspector. Without it, a job whose
// nominated inspector left the team, changed role or simply could not go
// was stuck at pending_inspector for good: only that inspector may sign
// off, and the supervisor's own stages come before and after this one.
if ($method === 'POST' && $action === 'reassign_verifier') {
    $m      = requireTeamRole('supervisor');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();
    $woId   = (int)($body['wo_id'] ?? 0);
    $newId  = (int)($body['verify_inspector_id'] ?? 0);

    if (!$woId || !$newId) {
        json_response(false, 'Work order and inspector are required.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM WorkOrders WHERE wo_id = ? AND team_id = ?');
    $stmt->execute([$woId, $teamId]);
    $w = $stmt->fetch();
    if (!$w) {
        json_response(false, 'Work order not found.', [], 404);
    }
    if ($w['status'] !== 'pending_inspector') {
        json_response(false, 'Only a work order awaiting site verification can be handed to another inspector.', [], 409);
    }
    if ((int)$w['verify_inspector_id'] === $newId) {
        json_response(false, 'That inspector is already verifying this work order.', [], 400);
    }
    $chk = $db->prepare(
        'SELECT u.name FROM TeamMembers tm JOIN Users u ON tm.user_id = u.user_id
          WHERE tm.team_id = ? AND tm.user_id = ?
            AND tm.role = "field_inspector" AND tm.status = "active" AND u.is_active = 1'
    );
    $chk->execute([$teamId, $newId]);
    $name = $chk->fetchColumn();
    if (!$name) {
        json_response(false, 'That person is not an active field inspector in this team.', [], 400);
    }

    $db->prepare('UPDATE WorkOrders SET verify_inspector_id = ? WHERE wo_id = ? AND team_id = ?')
       ->execute([$newId, $woId, $teamId]);

    $facts = woDetails($woId);
    notifyUsers([$newId], $teamId, "Site check needed: {$w['wo_code']}",
        "{$actor['name']} asked you to check the finished work on site.", $facts);
    notifyUsers([(int)$w['verify_inspector_id']], $teamId, "Site check moved: {$w['wo_code']}",
        "{$actor['name']} handed this site check to $name. You no longer need to do it.", $facts);

    logActivity($actor['user_id'], 'reassign_verifier', 'work_order', $woId,
        "{$actor['name']} handed site verification of work order {$w['wo_code']} to $name.", $teamId);

    json_response(true, "Site verification handed to $name.");
}

json_response(false, 'Invalid action.', [], 400);
