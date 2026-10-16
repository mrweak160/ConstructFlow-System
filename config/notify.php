<?php
// ConstructFlow — Hand-off emails
//
// When work changes hands, whoever now holds it gets an email: a task to
// inspect, a report to review, a repair to carry out, a job to check on site
// or to close.
//
// Emails are queued while the request runs and sent once the response has
// gone back, so a slow or failing mail service never delays or breaks the
// action itself. Nobody is emailed about their own action, and only active
// members of the team are emailed.

/**
 * Queues one email to each of $userIds.
 *
 * $lead is the sentence that says what happened; $details are label => value
 * rows under it (empty values are left out).
 */
function notifyUsers(array $userIds, int $teamId, string $subject, string $lead, array $details = []): void
{
    $actorId = (int)($_SESSION['user']['user_id'] ?? 0);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $userIds),
        fn($id) => $id > 0 && $id !== $actorId
    )));
    if (!$ids) {
        return;
    }

    try {
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDB()->prepare(
            "SELECT u.email, u.name, t.name AS team_name
               FROM Users u
               JOIN TeamMembers tm ON tm.user_id = u.user_id AND tm.team_id = ? AND tm.status = 'active'
               JOIN Teams t        ON t.team_id = tm.team_id
              WHERE u.user_id IN ($in) AND u.is_active = 1"
        );
        $stmt->execute(array_merge([$teamId], $ids));
        foreach ($stmt->fetchAll() as $r) {
            queueMail($r['email'], $r['name'], $subject,
                handoffEmailBody($r['name'], $r['team_name'], $lead, $details));
        }
    } catch (Throwable $e) {
        error_log('ConstructFlow: could not queue a notification: ' . $e->getMessage());
    }
}

/** Queues one email to every active member of the team with this role. */
function notifyRole(int $teamId, string $role, string $subject, string $lead, array $details = []): void
{
    try {
        $stmt = getDB()->prepare(
            "SELECT user_id FROM TeamMembers WHERE team_id = ? AND role = ? AND status = 'active'"
        );
        $stmt->execute([$teamId, $role]);
        notifyUsers($stmt->fetchAll(PDO::FETCH_COLUMN), $teamId, $subject, $lead, $details);
    } catch (Throwable $e) {
        error_log('ConstructFlow: could not queue a notification: ' . $e->getMessage());
    }
}

/** Everyone on a work order's crew: the lead and the support workers. */
function woCrewIds(int $woId): array
{
    $stmt = getDB()->prepare(
        'SELECT user_id FROM WorkOrderAssignees WHERE wo_id = ?
          UNION SELECT assigned_to FROM WorkOrders WHERE wo_id = ? AND assigned_to IS NOT NULL'
    );
    $stmt->execute([$woId, $woId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** The rows a work order email shows: what the job is and where. */
function woDetails(int $woId): array
{
    $stmt = getDB()->prepare(
        'SELECT w.wo_code, w.severity, w.deadline, r.title, u.name AS lead_name,
                COALESCE(NULLIF(r.address_text, ""), r.location_text) AS place
           FROM WorkOrders w JOIN InspectionReports r ON r.report_id = w.report_id
           LEFT JOIN Users u ON u.user_id = w.assigned_to
          WHERE w.wo_id = ?'
    );
    $stmt->execute([$woId]);
    $w = $stmt->fetch();
    if (!$w) {
        return [];
    }
    return [
        'Work order' => "{$w['wo_code']} · {$w['title']}",
        'Severity'   => ucfirst((string)$w['severity']),
        'Location'   => $w['place'],
        'Lead'       => $w['lead_name'] ?? '',
        'Deadline'   => notifyDate($w['deadline']),
    ];
}

/** A DATE column as "Oct 25, 2026"; empty when there is none. */
function notifyDate(?string $ymd): string
{
    return $ymd ? date('M j, Y', strtotime($ymd)) : '';
}

// ── THE QUEUE ────────────────────────────────────────────────

function queueMail(string $email, string $name, string $subject, string $html): void
{
    static $registered = false;
    $GLOBALS['CF_MAIL_QUEUE'][] = [$email, $name, $subject, $html];
    if (!$registered) {
        register_shutdown_function('flushMailQueue');
        $registered = true;
    }
}

/**
 * Sends the queued emails after the response. json_response() exits, which
 * runs this; finishing the request first hands the answer back to the
 * browser before the mail service is contacted (LiteSpeed on Hostinger,
 * PHP-FPM elsewhere). Where neither is available, the emails still go, just
 * before the connection closes.
 */
function flushMailQueue(): void
{
    $queue = $GLOBALS['CF_MAIL_QUEUE'] ?? [];
    $GLOBALS['CF_MAIL_QUEUE'] = [];
    if (!$queue) {
        return;
    }

    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    foreach ($queue as [$email, $name, $subject, $html]) {
        try {
            if (!sendMail($email, $name, $subject, $html)) {
                error_log("ConstructFlow: notification to $email was not accepted.");
            }
        } catch (Throwable $e) {
            error_log("ConstructFlow: notification to $email failed: " . $e->getMessage());
        }
    }
}

// ── THE EMAIL ────────────────────────────────────────────────

function handoffEmailBody(string $name, string $teamName, string $lead, array $details): string
{
    $e        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $logoUrl  = emailLogoUrl();
    $url      = $e(APP_URL . '/login.html');
    $first    = $e(explode(' ', trim($name))[0] ?: $name);

    $rows = '';
    foreach ($details as $label => $value) {
        if ((string)$value === '') continue;
        $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;font-size:13px;vertical-align:top;white-space:nowrap">'
               . $e($label) . '</td><td style="padding:6px 0;color:#1a1a2e;font-size:13.5px">'
               . nl2br($e($value)) . '</td></tr>';
    }
    $table = $rows ? "<table style=\"width:100%;border-collapse:collapse;margin:0 0 20px\">$rows</table>" : '';

    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px">
  <img src="{$logoUrl}" alt="ConstructFlow" width="56" height="56" style="display:block;margin:0 auto 20px;border-radius:12px"/>

  <div style="border:1px solid #d1d5db;border-radius:12px;padding:24px">
    <p style="margin:0 0 6px;font-size:14px;color:#374151">Hi {$first},</p>
    <p style="margin:0 0 18px;font-size:15px;color:#1a1a2e;line-height:1.5"><b>{$e($lead)}</b></p>
    {$table}
    <p style="margin:0 0 20px;text-align:center">
      <a href="{$url}"
         style="display:inline-block;border:2px solid #f59e0b;color:#f59e0b;
                background:#ffffff;font-size:16px;font-weight:800;
                text-decoration:none;padding:12px 28px;border-radius:10px">
        Open ConstructFlow
      </a>
    </p>
    <p style="margin:0;font-size:12px;color:#6b7280">
      Team: {$e($teamName)}. You are receiving this because work in this team was handed to you.
    </p>
  </div>
</div>
HTML;
}
