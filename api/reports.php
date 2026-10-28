<?php

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/SeverityClassifier.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Every POST here is authenticated, so all of them require a token.
if ($method === 'POST') {
    verifyCsrf();
}

/**
 * The severity a report is filed under. Normally the engine's grade. When
 * the engine recognised no listed defect type, its grade is only the
 * general default, so the report is held at Moderate at least and flagged
 * for review instead of sinking to the bottom of the queue as Low. The
 * supervisor reassesses it and overrides as needed. The engine's own grade
 * is kept in recommended_severity either way.
 */
function filedSeverity(array &$cls): string {
    $engine = $cls['severity'] ?? 'low';
    if (($cls['defect_listed'] ?? true) || $engine !== 'low') {
        return $engine;
    }
    $cls['needs_review'] = true;
    $cls['notes'][] = 'no defect type recognised; filed as Moderate until the supervisor reassesses it';
    return 'moderate';
}

// ── POST ?action=submit ──────────────────────────────────────
// Multipart: task_id, title, description, location_text,
//            latitude, longitude, phase, photos[]
// issue_type and severity are derived by the classifier, not submitted.
if ($method === 'POST' && $action === 'submit') {
    $m      = requireTeamRole('field_inspector');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = $_POST;   // multipart, for the photo upload
    // Refused before anything is saved, so the report is never filed short.
    if (tooManyPhotos('photos')) {
        json_response(false, 'You can attach up to ' . MAX_PHOTOS_COUNT . ' photos. Remove some and try again.', [], 400);
    }

    $taskId        = (int)($body['task_id'] ?? 0);
    $title         = clean($body['title'] ?? '');
    $description   = clean($body['description'] ?? '');
    $location_text = clean($body['location_text'] ?? '');
    $address_text  = clean($body['address_text'] ?? '');
    requireMaxLength(['Title' => [$title, 200], 'Location' => [$location_text, 255], 'Address' => [$address_text, 255]]);
    $provinceCode = clean($body['province_code'] ?? '');
    $cityCode     = clean($body['city_code'] ?? '');
    $brgyCode     = clean($body['brgy_code'] ?? '');
    $latitude      = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
    $longitude     = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;
    // decimal(10,7) would happily store a latitude of 500, and coordinates now
    // reach this endpoint from typed text as well as from the device, so the
    // range is checked here instead of being trusted from the form.
    if (($latitude !== null && abs($latitude) > 90) || ($longitude !== null && abs($longitude) > 180)) {
        json_response(false, 'Those coordinates are outside the valid range.', [], 400);
    }
    // Both halves or neither — a latitude on its own is not a location.
    if (($latitude === null) !== ($longitude === null)) {
        $latitude = null; $longitude = null;
    }
    $phase         = in_array($body['phase'] ?? '', ['construction','post_completion'], true)
                     ? $body['phase'] : 'construction';

    // issue_type and severity are no longer submitted — the engine derives
    // both from the description below.
    if (!$taskId || !$title || !$description || !$location_text) {
        json_response(false, 'A valid assigned task, title, description, and location are required.', [], 400);
    }
    // The engine needs something to read. A three-word description cannot be classified.
    if (mb_strlen($description) < 15) {
        json_response(false, 'Please describe the issue in a full sentence so it can be classified.', [], 400);
    }

    $db = getDB();

    // ── Rule-based severity classification ───────────────────
    // A recommendation only. The supervisor reviews and may override
    // when turning this into a work order.
    $kb         = KnowledgeBase::load($db, $phase);
    $classifier = new SeverityClassifier($kb);
    $cls        = $classifier->classify($description);

    $severity = filedSeverity($cls);

    // issue_type is NOT NULL and is still shown on the report detail view,
    // so it is filled from the engine's defect type rather than a dropdown.
    // Unclassifiable reports keep a readable placeholder instead of failing.
    $issue_type = 'Unclassified';
    if (!empty($cls['defect_type_id'])) {
        $dt = $db->prepare('SELECT name FROM DefectTypes WHERE defect_type_id = ? LIMIT 1');
        $dt->execute([$cls['defect_type_id']]);
        $issue_type = $dt->fetchColumn() ?: 'Unclassified';
    }

    // The task must be in this team AND assigned to this inspector.
    $task = $db->prepare('SELECT * FROM InspectionTasks WHERE task_id = ? AND team_id = ? AND assigned_to = ?');
    $task->execute([$taskId, $teamId, $actor['user_id']]);
    $t = $task->fetch();

    if (!$t) {
        json_response(false, 'This task is not assigned to you.', [], 403);
    }
    if ($t['status'] !== 'assigned') {
        json_response(false, $t['status'] === 'cancelled'
            ? 'This task was cancelled by the supervisor.'
            : 'A report has already been submitted for this task.', [], 400);
    }

    // nextCode() can hand two simultaneous requests the same number; the
    // unique key turns the second away, so take the next one and retry.
    for ($try = 1; ; $try++) {
        $code = generateReportCode($teamId);
        try {
            $db->prepare(
                'INSERT INTO InspectionReports
                 (team_id, report_code, task_id, submitted_by, title, issue_type, description,
                  location_text, address_text, province_code, city_code, brgy_code,
                  latitude, longitude, severity,
                  phase, defect_type_id, element_type_id, d_level, c_level,
                  recommended_severity, needs_review, matched_terms, classification_notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $teamId, $code, $taskId, $actor['user_id'], $title, $issue_type,
                $description, $location_text, $address_text ?: null,
                $provinceCode ?: null, $cityCode ?: null, $brgyCode ?: null,
                $latitude, $longitude, $severity,
                $phase, $cls['defect_type_id'], $cls['element_type_id'],
                $cls['d_level'], $cls['c_level'], $cls['severity'],
                $cls['needs_review'] ? 1 : 0, implode(', ', $cls['matched_terms']),
                empty($cls['notes']) ? null : implode("\n", $cls['notes']),
            ]);
            break;
        } catch (PDOException $e) {
            if ($try >= 3 || !isDuplicateCode($e)) throw $e;
        }
    }
    $reportId = (int)$db->lastInsertId();

    $db->prepare('UPDATE InspectionTasks SET status = "submitted" WHERE task_id = ? AND team_id = ?')
       ->execute([$taskId, $teamId]);

    $photoResult = handleMultiplePhotoUploads('photos', 'REP', $reportId);
    foreach ($photoResult['saved'] as $photoPath) {
        $db->prepare(
            'INSERT INTO PhotoEvidence (report_id, file_path, file_name, latitude, longitude)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$reportId, $photoPath, basename($photoPath), $latitude, $longitude]);
    }

    notifyRole($teamId, 'supervisor', "New inspection report: $code",
        "{$actor['name']} filed an inspection report. It is waiting for your review.", [
            'Report'   => "$code · $title",
            'Severity' => ucfirst($severity),
            'Location' => $address_text ?: $location_text,
        ]);

    logActivity($actor['user_id'], 'submit_report', 'report', $reportId,
        "{$actor['name']} submitted inspection report $code.", $teamId);

    json_response(true, 'Inspection report submitted successfully.', [
        'report_id'      => $reportId,
        'report_code'    => $code,
        'photo_warnings' => $photoResult['errors'],
        'classification' => [
            'severity'      => $cls['severity'],
            'filed_as'      => $severity,
            'issue_type'    => $issue_type,
            'd_level'       => $cls['d_level'],
            'c_level'       => $cls['c_level'],
            'needs_review'  => $cls['needs_review'],
            'matched_terms' => $cls['matched_terms'],
            'notes'         => $cls['notes'],
        ],
    ]);
}

// ── GET ?action=list ─────────────────────────────────────────
// field_inspector → own reports only
// supervisor      → all reports in the team
// Optional: ?severity=high&status=pending
if ($method === 'GET' && $action === 'list') {
    // Field workers reach a finding only through the work order they are on.
    $m      = requireTeamRole('supervisor', 'administrator', 'field_inspector');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    $where  = ['r.team_id = ?'];
    $params = [$teamId];

    if ($m['role'] === 'field_inspector') {
        $where[]  = 'r.submitted_by = ?';
        $params[] = $actor['user_id'];
    }
    if ($sev = ($_GET['severity'] ?? '')) {
        $where[]  = 'r.severity = ?';
        $params[] = $sev;
    }
    if ($st = ($_GET['status'] ?? '')) {
        $where[]  = 'r.status = ?';
        $params[] = $st;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT r.*,
                u.name AS submitted_by_name,
                t.location_text AS task_location,
                (SELECT file_path FROM PhotoEvidence WHERE report_id = r.report_id LIMIT 1) AS photo
         FROM InspectionReports r
         JOIN Users u ON r.submitted_by = u.user_id
         JOIN InspectionTasks t ON r.task_id = t.task_id
         $whereSQL
         ORDER BY FIELD(r.severity,'critical','high','moderate','low'), r.submitted_at DESC"
    );
    $stmt->execute($params);

    json_response(true, 'Reports retrieved.', [
        'reports' => markFormerMembers($stmt->fetchAll(), $teamId, ['submitted_by' => 'submitted_by_name']),
    ]);
}

// ── GET ?action=detail&id=1 ──────────────────────────────────
if ($method === 'GET' && $action === 'detail') {
    $m        = requireTeamRole('supervisor', 'administrator', 'field_inspector');
    $actor    = currentUser();
    $teamId   = (int)$m['team_id'];
    $reportId = (int)($_GET['id'] ?? 0);
    $db       = getDB();

    $stmt = $db->prepare(
        'SELECT r.*, u.name AS submitted_by_name, t.location_text AS task_location
         FROM InspectionReports r
         JOIN Users u ON r.submitted_by = u.user_id
         JOIN InspectionTasks t ON r.task_id = t.task_id
         WHERE r.report_id = ? AND r.team_id = ?'
    );
    $stmt->execute([$reportId, $teamId]);
    $report = $stmt->fetch();

    if (!$report) {
        json_response(false, 'Report not found.', [], 404);
    }
    // An inspector sees only their own submissions.
    if ($m['role'] === 'field_inspector' && $report['submitted_by'] != $actor['user_id']) {
        json_response(false, 'Access denied.', [], 403);
    }

    $photos = $db->prepare('SELECT * FROM PhotoEvidence WHERE report_id = ?');
    $photos->execute([$reportId]);

    $wo = $db->prepare(
        'SELECT w.*, u.name AS assigned_to_name
         FROM WorkOrders w
         LEFT JOIN Users u ON w.assigned_to = u.user_id
         WHERE w.report_id = ? AND w.team_id = ?'
    );
    $wo->execute([$reportId, $teamId]);

    json_response(true, 'Report detail retrieved.', [
        'report'     => markFormerMember($report, $teamId, ['submitted_by' => 'submitted_by_name']),
        'photos'     => $photos->fetchAll(),
        'work_order' => markFormerMember($wo->fetch() ?: null, $teamId, ['assigned_to' => 'assigned_to_name']),
    ]);
}

// ── POST ?action=edit ────────────────────────────────────────
// Body: { report_id, title, description, location_text, address_text,
//         province_code, city_code, brgy_code, phase }
//
// An inspector may correct their own report until a supervisor turns it into
// a work order — after that a crew is working to it and it must not move.
// The description is re-classified on save so the severity never contradicts
// the text it was derived from.
//
if ($method === 'POST' && $action === 'edit') {
    $m      = requireTeamRole('field_inspector');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $body   = getBody();

    $reportId      = (int)($body['report_id'] ?? 0);
    $title         = clean($body['title'] ?? '');
    $description   = clean($body['description'] ?? '');
    $location_text = clean($body['location_text'] ?? '');
    $address_text  = clean($body['address_text'] ?? '');
    requireMaxLength(['Title' => [$title, 200], 'Location' => [$location_text, 255], 'Address' => [$address_text, 255]]);
    $provinceCode  = clean($body['province_code'] ?? '');
    $cityCode      = clean($body['city_code'] ?? '');
    $brgyCode      = clean($body['brgy_code'] ?? '');
    $phase         = in_array($body['phase'] ?? '', ['construction','post_completion'], true)
                     ? $body['phase'] : 'construction';

    if (!$reportId || !$title || !$description || !$location_text) {
        json_response(false, 'Title, description, and location are required.', [], 400);
    }
    if (mb_strlen($description) < 15) {
        json_response(false, 'Please describe the issue in a full sentence so it can be classified.', [], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM InspectionReports WHERE report_id = ? AND team_id = ?');
    $stmt->execute([$reportId, $teamId]);
    $rep = $stmt->fetch();

    if (!$rep) {
        json_response(false, 'Report not found.', [], 404);
    }
    if ((int)$rep['submitted_by'] !== (int)$actor['user_id']) {
        json_response(false, 'You can only edit your own reports.', [], 403);
    }

    $wo = $db->prepare('SELECT wo_code FROM WorkOrders WHERE report_id = ? LIMIT 1');
    $wo->execute([$reportId]);
    if ($existing = $wo->fetch()) {
        json_response(false,
            "A work order ({$existing['wo_code']}) has already been created from this report, so it can no longer be edited.",
            [], 409);
    }

    // Re-run the engine: an edited description must not keep the old severity.
    $kb         = KnowledgeBase::load($db, $phase);
    $classifier = new SeverityClassifier($kb);
    $cls        = $classifier->classify($description);
    $severity   = filedSeverity($cls);

    $issue_type = 'Unclassified';
    if (!empty($cls['defect_type_id'])) {
        $dt = $db->prepare('SELECT name FROM DefectTypes WHERE defect_type_id = ? LIMIT 1');
        $dt->execute([$cls['defect_type_id']]);
        $issue_type = $dt->fetchColumn() ?: 'Unclassified';
    }

    $db->prepare(
        'UPDATE InspectionReports
            SET title = ?, issue_type = ?, description = ?,
                location_text = ?, address_text = ?,
                province_code = ?, city_code = ?, brgy_code = ?,
                phase = ?, severity = ?, defect_type_id = ?, element_type_id = ?,
                d_level = ?, c_level = ?, recommended_severity = ?,
                needs_review = ?, matched_terms = ?, classification_notes = ?
          WHERE report_id = ? AND team_id = ?'
    )->execute([
        $title, $issue_type, $description,
        $location_text, $address_text ?: null,
        $provinceCode ?: null, $cityCode ?: null, $brgyCode ?: null,
        $phase, $severity, $cls['defect_type_id'], $cls['element_type_id'],
        $cls['d_level'], $cls['c_level'], $cls['severity'],
        $cls['needs_review'] ? 1 : 0, implode(', ', $cls['matched_terms']),
        empty($cls['notes']) ? null : implode("\n", $cls['notes']),
        $reportId, $teamId,
    ]);

    logActivity($actor['user_id'], 'edit_report', 'report', $reportId,
        "{$actor['name']} edited report {$rep['report_code']}; severity re-classified as $severity.", $teamId);

    json_response(true, 'Report updated and re-classified.', [
        'severity'   => $severity,
        'needs_review' => $cls['needs_review'],
        'issue_type' => $issue_type,
    ]);
}

json_response(false, 'Invalid action.', [], 400);
