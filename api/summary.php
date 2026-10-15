<?php
// ConstructFlow — Printable summaries
//
// One endpoint behind every printable document, so the four types share a
// single payload shape and a single set of access rules. print.js renders
// whichever sections come back and skips the rest.
//
//   document&type=task|report|workorder|case&id=<id>
//
// Access mirrors the detail endpoints exactly: an inspector may print only
// their own task or report, a field worker only a work order they are on,
// and supervisors and administrators may print anything in their team.
// Enforced here rather than trusted from the page, because a printable URL
// is the first thing somebody edits by hand.

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method !== 'GET') {
    json_response(false, 'Invalid action.', [], 400);
}

/**
 * The moment a document was produced, taken from the database.
 *
 * Every other date on these sheets was written by MySQL, so the footer has
 * to come from the same clock. Read from PHP it disagreed with the record it
 * sits under by however far date.timezone is from the database server's
 * timezone — six hours, on the machine this was found on.
 */
function generatedAt(): string {
    return (string)getDB()->query('SELECT NOW()')->fetchColumn();
}

// ── Status banner ────────────────────────────────────────────
// Every document states its own state at the top. A work order printed
// mid-repair is a progress snapshot, and must not be mistakable for proof
// that the job was finished and verified.
function woStatusBanner(array $wo): array {
    $closed = !empty($wo['closed_at']);

    if ($closed) {
        $parts = [];
        if (!empty($wo['assigned_to_name']))          $parts[] = "Completed by {$wo['assigned_to_name']}";
        if (!empty($wo['supervisor_verified_name']))  $parts[] = "verified by {$wo['supervisor_verified_name']} (supervisor)";
        if (!empty($wo['inspector_verified_name']))   $parts[] = "verified by {$wo['inspector_verified_name']} (inspector)";
        if (!empty($wo['closed_by_name']))            $parts[] = "closed by {$wo['closed_by_name']}";
        return [
            'label' => 'CLOSED',
            'tone'  => 'done',
            'note'  => implode(' · ', $parts),
        ];
    }

    return match ($wo['status']) {
        'completed', 'pending_supervisor' => [
            'label' => 'AWAITING VERIFICATION',
            'tone'  => 'pending',
            'note'  => 'The field worker has marked this done. It has not been verified yet.',
        ],
        'pending_inspector' => [
            'label' => 'AWAITING INSPECTOR',
            'tone'  => 'pending',
            'note'  => 'Passed supervisor review, waiting on the field inspector to sign off.',
        ],
        'pending_closure' => [
            'label' => 'AWAITING CLOSURE',
            'tone'  => 'pending',
            'note'  => 'Both verifications are in. Waiting on the supervisor to close it.',
        ],
        'rework' => [
            'label' => 'SENT BACK FOR REWORK',
            'tone'  => 'warn',
            'note'  => 'A verifier returned this work order. It is not finished.',
        ],
        'on_hold' => [
            'label' => 'ON HOLD',
            'tone'  => 'warn',
            'note'  => 'Work is paused. This is a progress snapshot, not a completion record.',
        ],
        'in_progress' => [
            'label' => 'IN PROGRESS',
            'tone'  => 'warn',
            'note'  => 'Work is underway. This is a progress snapshot, not a completion record.',
        ],
        default => [
            'label' => 'ASSIGNED — NOT STARTED',
            'tone'  => 'warn',
            'note'  => 'No work has been recorded yet. This is a progress snapshot.',
        ],
    };
}

function reportStatusBanner(array $r): array {
    return match ($r['status']) {
        'completed' => ['label' => 'RESOLVED',  'tone' => 'done',
                        'note'  => 'The work order raised from this report has been closed.'],
        'rejected'  => ['label' => 'REJECTED',  'tone' => 'warn',
                        'note'  => 'This report was rejected and no work order was raised.'],
        'assigned',
        'in_progress' => ['label' => 'IN PROGRESS', 'tone' => 'warn',
                        'note'  => 'A work order has been raised and the repair is not finished.'],
        default     => ['label' => 'AWAITING REVIEW', 'tone' => 'pending',
                        'note'  => 'Submitted and classified. No work order has been raised yet.'],
    };
}

function taskStatusBanner(array $t): array {
    return match ($t['status']) {
        'closed'    => ['label' => 'CLOSED',    'tone' => 'done',
                        'note'  => 'The inspection is finished and the task has been closed.'],
        'submitted' => ['label' => 'SUBMITTED', 'tone' => 'done',
                        'note'  => 'The inspector has submitted a report for this task.'],
        default     => ['label' => 'ASSIGNED',  'tone' => 'warn',
                        'note'  => 'No report has been submitted for this task yet.'],
    };
}

// ── Loaders ──────────────────────────────────────────────────
// Each returns null when the record is not in this team, which is what
// stops id-guessing across teams: the row simply is not found.

function loadTask(PDO $db, int $teamId, int $taskId): ?array {
    $st = $db->prepare(
        'SELECT t.*,
                u_creator.name  AS created_by_name,
                u_assignee.name AS assigned_to_name
           FROM InspectionTasks t
           JOIN Users u_creator  ON t.created_by  = u_creator.user_id
           JOIN Users u_assignee ON t.assigned_to = u_assignee.user_id
          WHERE t.task_id = ? AND t.team_id = ?'
    );
    $st->execute([$taskId, $teamId]);
    $row = $st->fetch() ?: null;
    return markFormerMember($row, $teamId, [
        'created_by'  => 'created_by_name',
        'assigned_to' => 'assigned_to_name',
    ]);
}

function loadReport(PDO $db, int $teamId, int $reportId): ?array {
    $st = $db->prepare(
        'SELECT r.*, u.name AS submitted_by_name,
                ov.name AS overridden_by_name,
                dt.name AS defect_type_name,
                et.name AS element_type_name
           FROM InspectionReports r
           JOIN Users u ON r.submitted_by = u.user_id
           LEFT JOIN Users ov       ON r.severity_overridden_by = ov.user_id
           LEFT JOIN DefectTypes dt ON r.defect_type_id  = dt.defect_type_id
           LEFT JOIN ElementTypes et ON r.element_type_id = et.element_type_id
          WHERE r.report_id = ? AND r.team_id = ?'
    );
    $st->execute([$reportId, $teamId]);
    $row = $st->fetch() ?: null;
    return markFormerMember($row, $teamId, [
        'submitted_by'           => 'submitted_by_name',
        'severity_overridden_by' => 'overridden_by_name',
    ]);
}

function loadWorkOrder(PDO $db, int $teamId, int $woId): ?array {
    $st = $db->prepare(
        'SELECT w.*,
                u_creator.name AS created_by_name,
                u_worker.name  AS assigned_to_name,
                vi.name        AS verify_inspector_name,
                sv.name        AS supervisor_verified_name,
                iv.name        AS inspector_verified_name,
                cb.name        AS closed_by_name
           FROM WorkOrders w
           JOIN Users u_creator ON w.created_by = u_creator.user_id
           LEFT JOIN Users u_worker ON w.assigned_to            = u_worker.user_id
           LEFT JOIN Users vi       ON w.verify_inspector_id    = vi.user_id
           LEFT JOIN Users sv       ON w.supervisor_verified_by = sv.user_id
           LEFT JOIN Users iv       ON w.inspector_verified_by  = iv.user_id
           LEFT JOIN Users cb       ON w.closed_by              = cb.user_id
          WHERE w.wo_id = ? AND w.team_id = ?'
    );
    $st->execute([$woId, $teamId]);
    $row = $st->fetch() ?: null;
    return markFormerMember($row, $teamId, [
        'created_by'             => 'created_by_name',
        'assigned_to'            => 'assigned_to_name',
        'verify_inspector_id'    => 'verify_inspector_name',
        'supervisor_verified_by' => 'supervisor_verified_name',
        'inspector_verified_by'  => 'inspector_verified_name',
        'closed_by'              => 'closed_by_name',
    ]);
}

function loadCrew(PDO $db, int $teamId, int $woId): array {
    $st = $db->prepare(
        'SELECT a.user_id, a.is_lead, u.name
           FROM WorkOrderAssignees a JOIN Users u ON a.user_id = u.user_id
          WHERE a.wo_id = ? ORDER BY a.is_lead DESC, u.name'
    );
    $st->execute([$woId]);
    return markFormerMembers($st->fetchAll(), $teamId, ['user_id' => 'name']);
}

function loadUpdates(PDO $db, int $teamId, int $woId): array {
    $st = $db->prepare(
        'SELECT fu.*, u.name AS updated_by_name
           FROM FieldWorkUpdates fu JOIN Users u ON fu.updated_by = u.user_id
          WHERE fu.wo_id = ? ORDER BY fu.updated_at ASC'
    );
    $st->execute([$woId]);
    return markFormerMembers($st->fetchAll(), $teamId, ['updated_by' => 'updated_by_name']);
}

function loadPhotos(PDO $db, int $reportId): array {
    $st = $db->prepare('SELECT * FROM PhotoEvidence WHERE report_id = ? ORDER BY photo_id');
    $st->execute([$reportId]);
    return $st->fetchAll();
}

// ── GET ?action=document ─────────────────────────────────────
if ($action === 'document') {
    $m      = requireTeam();
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $role   = $m['role'];
    $type   = $_GET['type'] ?? '';
    $id     = (int)($_GET['id'] ?? 0);
    $db     = getDB();

    if (!in_array($type, ['task', 'report', 'workorder', 'case'], true) || $id < 1) {
        json_response(false, 'Unknown document.', [], 400);
    }

    $out = [
        'type'         => $type,
        'team_name'    => $m['team_name'],
        'generated_by' => $actor['name'],
        'generated_at' => generatedAt(),
        'viewer_role'  => roleLabel($role),
    ];

    // Walk the chain outwards from whichever record the id names. Each
    // document type then renders the part of the chain it needs, and a
    // broken link (a task with no report yet, a report with no work order)
    // simply leaves that section out rather than failing.
    //   task       → id is a task id
    //   report     → id is a report id
    //   workorder  → id is a work order id
    //   case       → id is a report id, the spine of the whole story
    $task = $report = $wo = null;

    if ($type === 'workorder') {
        $wo = loadWorkOrder($db, $teamId, $id);
        if (!$wo) json_response(false, 'Work order not found.', [], 404);
        $report = loadReport($db, $teamId, (int)$wo['report_id']);
    } elseif ($type === 'task') {
        $task = loadTask($db, $teamId, $id);
        if (!$task) json_response(false, 'Task not found.', [], 404);
        $rs = $db->prepare('SELECT report_id FROM InspectionReports WHERE task_id = ? AND team_id = ?');
        $rs->execute([$id, $teamId]);
        if ($rid = (int)($rs->fetchColumn() ?: 0)) {
            $report = loadReport($db, $teamId, $rid);
        }
    } else {                                   // report or case
        $report = loadReport($db, $teamId, $id);
        if (!$report) json_response(false, 'Report not found.', [], 404);
    }

    if ($report && !$task) {
        $task = loadTask($db, $teamId, (int)$report['task_id']);
    }
    if ($report && !$wo && $type !== 'task') {
        $ws = $db->prepare('SELECT wo_id FROM WorkOrders WHERE report_id = ? AND team_id = ?');
        $ws->execute([(int)$report['report_id'], $teamId]);
        if ($wid = (int)($ws->fetchColumn() ?: 0)) {
            $wo = loadWorkOrder($db, $teamId, $wid);
        }
    }

    // ── Access, mirroring the detail endpoints ───────────────
    // An inspector sees only their own work; a field worker only a work
    // order they are on. Supervisors and administrators see everything in
    // the team.
    if ($role === 'field_inspector') {
        $ownsReport = $report && (int)$report['submitted_by'] === (int)$actor['user_id'];
        $ownsTask   = $task   && (int)$task['assigned_to']   === (int)$actor['user_id'];
        if (!$ownsReport && !$ownsTask) {
            json_response(false, 'Access denied.', [], 403);
        }
    }
    if ($role === 'field_worker') {
        if (!$wo) {
            json_response(false, 'Access denied.', [], 403);
        }
        $on = $db->prepare('SELECT 1 FROM WorkOrderAssignees WHERE wo_id = ? AND user_id = ?');
        $on->execute([(int)$wo['wo_id'], $actor['user_id']]);
        if (!$on->fetch()) {
            json_response(false, 'Access denied.', [], 403);
        }
        // A worker's document is the job, not the paperwork around it.
        $task = null;
    }

    // ── Banner comes from the primary record of this document ─
    if ($type === 'task')                       $out['status'] = taskStatusBanner($task);
    elseif ($type === 'report')                 $out['status'] = reportStatusBanner($report);
    elseif ($wo)                                $out['status'] = woStatusBanner($wo);
    elseif ($report)                            $out['status'] = reportStatusBanner($report);
    else                                        $out['status'] = taskStatusBanner($task);

    $out['task']       = $task;
    $out['report']     = $report;
    $out['work_order'] = $wo;
    $out['crew']       = $wo     ? loadCrew($db, $teamId, (int)$wo['wo_id'])    : [];
    $out['updates']    = $wo     ? loadUpdates($db, $teamId, (int)$wo['wo_id']) : [];
    $out['photos']     = $report ? loadPhotos($db, (int)$report['report_id'])   : [];

    // An audit trail of who printed what. The table was in the schema from
    // the start and this is the first thing to use it.
    $titles = [
        'task'      => 'Inspection Task ' . ($task['task_code']     ?? $id),
        'report'    => 'Inspection Report ' . ($report['report_code'] ?? $id),
        'workorder' => 'Work Order ' . ($wo['wo_code']               ?? $id),
        'case'      => 'Case File ' . ($report['report_code']        ?? $id),
    ];
    $types = [
        'task'      => 'inspection_summary',
        'report'    => 'inspection_summary',
        'workorder' => 'work_order_summary',
        'case'      => 'full_export',
    ];
    try {
        $db->prepare(
            'INSERT INTO GeneratedReports (team_id, title, report_type, generated_by) VALUES (?, ?, ?, ?)'
        )->execute([$teamId, $titles[$type], $types[$type], $actor['user_id']]);
    } catch (Throwable $e) {
        // A printout is not worth failing over an audit row.
    }

    json_response(true, 'Document retrieved.', $out);
}

// ── Shared filter parsing ────────────────────────────────────
// "All records" grows without bound, so the UI always offers a range and
// this parses whatever it sends. An absent range means all time, which
// the UI only ever asks for explicitly.
function rangeFilter(string $col): array {
    $where = [];
    $args  = [];
    $from  = clean($_GET['from'] ?? '');
    $to    = clean($_GET['to']   ?? '');

    $ok = function (string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    };

    if ($from !== '' && $ok($from)) { $where[] = "$col >= ?"; $args[] = $from . ' 00:00:00'; }
    if ($to   !== '' && $ok($to))   { $where[] = "$col <= ?"; $args[] = $to   . ' 23:59:59'; }

    // Only what was actually applied: echoing back a date that failed
    // validation made the sheet state a range it had not filtered by.
    return [$where, $args,
            ($from !== '' && $ok($from)) ? $from : null,
            ($to   !== '' && $ok($to))   ? $to   : null];
}

/** Counts keyed by a column, with every band present even at zero. */
function tally(array $rows, string $key, array $keys): array {
    $out = array_fill_keys($keys, 0);
    foreach ($rows as $r) {
        $k = (string)($r[$key] ?? '');
        if (array_key_exists($k, $out)) $out[$k]++;
    }
    return $out;
}

const SEVERITIES    = ['low', 'moderate', 'high', 'critical'];
const REPORT_STATES = ['pending', 'assigned', 'in_progress', 'completed', 'rejected'];
const WO_STATES     = ['pending', 'in_progress', 'on_hold', 'pending_supervisor',
                       'pending_inspector', 'pending_closure', 'rework', 'completed'];

// ── GET ?action=reports ──────────────────────────────────────
// Every inspection report in the team, filtered, plus the overview the
// cover page quotes. Supervisors get this too: they run the work, and a
// summary only an administrator could read would be an odd restriction.
if ($action === 'reports') {
    $m      = requireTeamRole('supervisor', 'administrator');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    [$where, $args, $from, $to] = rangeFilter('r.submitted_at');
    array_unshift($where, 'r.team_id = ?');
    array_unshift($args, $teamId);

    $sev = clean($_GET['severity'] ?? '');
    if (in_array($sev, SEVERITIES, true)) { $where[] = 'r.severity = ?'; $args[] = $sev; }

    $st = clean($_GET['status'] ?? '');
    if (in_array($st, REPORT_STATES, true)) { $where[] = 'r.status = ?'; $args[] = $st; }

    $stmt = $db->prepare(
        'SELECT r.report_id, r.report_code, r.title, r.issue_type, r.severity,
                r.recommended_severity, r.classification_confidence, r.status,
                r.submitted_at, r.address_text, r.location_text,
                r.submitted_by, u.name AS submitted_by_name,
                (SELECT wo_code FROM WorkOrders w WHERE w.report_id = r.report_id LIMIT 1) AS wo_code
           FROM InspectionReports r
           JOIN Users u ON r.submitted_by = u.user_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY r.submitted_at DESC'
    );
    $stmt->execute($args);
    $rows = markFormerMembers($stmt->fetchAll(), $teamId, ['submitted_by' => 'submitted_by_name']);

    // An override is simply a final severity that differs from what the
    // engine recommended — nothing separate is stored for it.
    $overridden = 0;
    foreach ($rows as $r) {
        if ($r['recommended_severity'] && $r['severity'] !== $r['recommended_severity']) {
            $overridden++;
        }
    }

    json_response(true, 'Reports summary retrieved.', [
        'kind'         => 'reports',
        'team_name'    => $m['team_name'],
        'generated_by' => $actor['name'],
        'generated_at' => generatedAt(),
        'filters'      => ['from' => $from, 'to' => $to,
                           'severity' => $sev ?: null, 'status' => $st ?: null],
        'rows'         => $rows,
        'totals'       => [
            'count'      => count($rows),
            'severity'   => tally($rows, 'severity', SEVERITIES),
            'status'     => tally($rows, 'status', REPORT_STATES),
            'overridden' => $overridden,
        ],
    ]);
}

// ── GET ?action=workorders ───────────────────────────────────
if ($action === 'workorders') {
    $m      = requireTeamRole('supervisor', 'administrator');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    [$where, $args, $from, $to] = rangeFilter('w.created_at');
    array_unshift($where, 'w.team_id = ?');
    array_unshift($args, $teamId);

    $sev = clean($_GET['severity'] ?? '');
    if (in_array($sev, SEVERITIES, true)) { $where[] = 'w.severity = ?'; $args[] = $sev; }

    $st = clean($_GET['status'] ?? '');
    if (in_array($st, WO_STATES, true)) { $where[] = 'w.status = ?'; $args[] = $st; }

    $stmt = $db->prepare(
        'SELECT w.wo_id, w.wo_code, w.severity, w.status, w.created_at, w.deadline, w.closed_at,
                w.created_by, w.assigned_to,
                r.report_code, r.title AS report_title,
                uc.name AS created_by_name, uw.name AS assigned_to_name,
                (SELECT GROUP_CONCAT(u2.name ORDER BY a2.is_lead DESC, u2.name SEPARATOR 0x1F)
                   FROM WorkOrderAssignees a2 JOIN Users u2 ON a2.user_id = u2.user_id
                  WHERE a2.wo_id = w.wo_id) AS crew_names,
                (SELECT GROUP_CONCAT(u2.user_id ORDER BY a2.is_lead DESC, u2.name SEPARATOR ",")
                   FROM WorkOrderAssignees a2 JOIN Users u2 ON a2.user_id = u2.user_id
                  WHERE a2.wo_id = w.wo_id) AS crew_ids,
                CASE WHEN w.closed_at IS NULL THEN NULL
                     ELSE TIMESTAMPDIFF(DAY, w.created_at, w.closed_at) END AS days_open,
                (w.closed_at IS NULL AND w.deadline IS NOT NULL
                 AND w.deadline < CURDATE()) AS is_overdue
           FROM WorkOrders w
           JOIN InspectionReports r ON w.report_id = r.report_id
           JOIN Users uc ON w.created_by = uc.user_id
           LEFT JOIN Users uw ON w.assigned_to = uw.user_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY w.created_at DESC'
    );
    $stmt->execute($args);
    $rows = markFormerMembers($stmt->fetchAll(), $teamId, [
        'created_by'  => 'created_by_name',
        'assigned_to' => 'assigned_to_name',
        'crew_ids'    => 'crew_names',
    ]);

    $closed  = array_values(array_filter($rows, fn($w) => $w['days_open'] !== null));
    $avg     = $closed ? round(array_sum(array_column($closed, 'days_open')) / count($closed), 1) : null;
    // Overdue is judged by the database, against the same clock that wrote
    // the deadline. Measured with PHP's date() it under-reported for the
    // hours each day when the two servers disagree on the calendar day.
    $overdue = count(array_filter($rows, fn($w) => (int)$w['is_overdue'] === 1));

    json_response(true, 'Work orders summary retrieved.', [
        'kind'         => 'workorders',
        'team_name'    => $m['team_name'],
        'generated_by' => $actor['name'],
        'generated_at' => generatedAt(),
        'filters'      => ['from' => $from, 'to' => $to,
                           'severity' => $sev ?: null, 'status' => $st ?: null],
        'rows'         => $rows,
        'totals'       => [
            'count'         => count($rows),
            'severity'      => tally($rows, 'severity', SEVERITIES),
            'closed'        => count($closed),
            'open'          => count($rows) - count($closed),
            'overdue'       => $overdue,
            'avg_days_open' => $avg,
        ],
    ]);
}

// ── Workload ─────────────────────────────────────────────────
// Ranking people by a plain count of finished jobs rewards whoever picked
// up the easy work: ten minor cracks would outrank three critical column
// repairs. So each finished job is worth points, and the points come out
// of the severity matrix rather than out of the air.
//
// Each band is produced by a known set of D×C cells. Take the mean D×C of
// the cells that yield each band and normalise against low:
//
//   low       D×C of 1,2,2,3,3,4     mean  2.50   → 1
//   moderate  D×C of 4,4,6,6         mean  5.00   → 2
//   high      D×C of 8,8,9           mean  8.33   → 3
//   critical  D×C of 12,12,16        mean 13.33   → 5
//
// Which means "why is a critical job worth five?" is answered by the
// matrix, not by a designer's hunch.
const WORKLOAD_WEIGHTS = ['low' => 1, 'moderate' => 2, 'high' => 3, 'critical' => 5];

/** Applies the weights and sorts, leaving both rankings available. */
function scoreWorkload(array $rows): array {
    foreach ($rows as &$r) {
        $score = 0;
        foreach (WORKLOAD_WEIGHTS as $band => $w) {
            $r[$band] = (int)($r[$band] ?? 0);
            $score   += $r[$band] * $w;
        }
        $r['done']   = (int)$r['done'];
        $r['lead']   = (int)($r['lead'] ?? 0);
        $r['score']  = $score;
        $r['user_id'] = (int)$r['user_id'];
    }
    unset($r);

    // Highest score first; a tie breaks on the harder work, then on name,
    // so the order is stable rather than whatever MySQL felt like.
    usort($rows, function ($a, $b) {
        return [$b['score'], $b['critical'], $b['high'], $a['name']]
           <=> [$a['score'], $a['critical'], $a['high'], $b['name']];
    });
    return $rows;
}

// ── GET ?action=workload ─────────────────────────────────────
if ($action === 'workload') {
    $m      = requireTeamRole('supervisor', 'administrator');
    $actor  = currentUser();
    $teamId = (int)$m['team_id'];
    $db     = getDB();

    // Field workers are measured on work orders that actually closed:
    // counting assigned-but-unfinished work would reward hoarding it.
    [$wWhere, $wArgs, $from, $to] = rangeFilter('w.closed_at');
    $workerSql =
        'SELECT u.user_id, u.name,
                COUNT(*) AS done,
                SUM(a.is_lead = 1) AS lead,
                SUM(w.severity = "low")      AS low,
                SUM(w.severity = "moderate") AS moderate,
                SUM(w.severity = "high")     AS high,
                SUM(w.severity = "critical") AS critical
           FROM WorkOrderAssignees a
           JOIN WorkOrders w ON w.wo_id = a.wo_id
           JOIN Users u      ON u.user_id = a.user_id
          WHERE w.team_id = ? AND w.closed_at IS NOT NULL'
        . ($wWhere ? ' AND ' . implode(' AND ', $wWhere) : '') .
        ' GROUP BY u.user_id, u.name';
    $st = $db->prepare($workerSql);
    $st->execute(array_merge([$teamId], $wArgs));
    $workers = scoreWorkload($st->fetchAll());

    // How many work orders actually closed, which is not the same as how
    // many assignments they generated.
    $cnt = $db->prepare(
        'SELECT COUNT(*) FROM WorkOrders w
          WHERE w.team_id = ? AND w.closed_at IS NOT NULL'
        . ($wWhere ? ' AND ' . implode(' AND ', $wWhere) : '')
    );
    $cnt->execute(array_merge([$teamId], $wArgs));
    $closedOrders = (int)$cnt->fetchColumn();

    // An inspector's deliverable is the report. Scoring them on whether a
    // work order later closed would mark them down for somebody else's
    // pace, so a submitted report is the finished unit of work.
    [$rWhere, $rArgs] = rangeFilter('r.submitted_at');
    // The verified count has to answer the same question as the rest of the
    // row. Left unscoped it reported an all-time figure sitting beside
    // range-scoped ones, which reads as though it belonged to the range.
    [$vWhere, $vArgs] = rangeFilter('vw.inspector_verified_at');
    $inspectorSql =
        'SELECT u.user_id, u.name,
                COUNT(*) AS done,
                0 AS lead,
                SUM(r.severity = "low")      AS low,
                SUM(r.severity = "moderate") AS moderate,
                SUM(r.severity = "high")     AS high,
                SUM(r.severity = "critical") AS critical,
                (SELECT COUNT(*) FROM WorkOrders vw
                  WHERE vw.team_id = r.team_id AND vw.inspector_verified_by = u.user_id'
        . ($vWhere ? ' AND ' . implode(' AND ', $vWhere) : '') . ')
                  AS verified
           FROM InspectionReports r
           JOIN Users u ON u.user_id = r.submitted_by
          WHERE r.team_id = ?'
        . ($rWhere ? ' AND ' . implode(' AND ', $rWhere) : '') .
        ' GROUP BY u.user_id, u.name, r.team_id';
    $st = $db->prepare($inspectorSql);
    // The subquery's placeholders come first in the statement text.
    $st->execute(array_merge($vArgs, [$teamId], $rArgs));
    $inspectors = scoreWorkload($st->fetchAll());

    // Someone who has left still did the work, so they stay on the board
    // with the marker the rest of the system shows.
    $workers    = markFormerMembers($workers,    $teamId, ['user_id' => 'name']);
    $inspectors = markFormerMembers($inspectors, $teamId, ['user_id' => 'name']);

    json_response(true, 'Workload retrieved.', [
        'team_name'    => $m['team_name'],
        'generated_by' => $actor['name'],
        'generated_at' => generatedAt(),
        'filters'      => ['from' => $from, 'to' => $to],
        'weights'      => WORKLOAD_WEIGHTS,
        'weight_note'  => 'Each severity is worth the mean D×C score of the matrix cells '
                        . 'that produce it, normalised so low = 1.',
        'workers'      => $workers,
        'inspectors'   => $inspectors,
        'totals'       => [
            // Two different numbers, and conflating them would overstate the
            // work: a crewed job closes once but credits every person on it,
            // so assignments exceed work orders whenever a crew is shared.
            'worker_assignments' => array_sum(array_column($workers, 'done')),
            'worker_orders'      => $closedOrders,
            'worker_points'      => array_sum(array_column($workers, 'score')),
            'inspector_reports'  => array_sum(array_column($inspectors, 'done')),
            'inspector_points'   => array_sum(array_column($inspectors, 'score')),
        ],
    ]);
}

json_response(false, 'Invalid action.', [], 400);
