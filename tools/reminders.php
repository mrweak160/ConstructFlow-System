<?php
/**
 * Daily reminder: emails whoever holds an inspection task or a work order
 * that is due within the next 3 days. Once per due date; changing the date
 * or the assignee sends a fresh reminder.
 *
 *   Task:       the assigned inspector and the team's supervisors.
 *   Work order: the whole crew and the team's supervisors.
 *
 *   XAMPP (Windows):  C:\xampp\php\php.exe tools\reminders.php
 *   Hostinger cron:   /usr/bin/php /home/<user>/domains/<site>/public_html/tools/reminders.php
 *                     every day at 7:00 AM Philippine time
 *
 * Command line only: over the web this file returns 404.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/helpers.php';

const REMIND_DAYS = 3;   // the same window as the "Due in N days" tag on screen

$db = getDB();

/** "today", "tomorrow" or "in 3 days" for a due date. */
function dueWhen(string $ymd): string
{
    $days = (int)round((strtotime($ymd) - strtotime(date('Y-m-d'))) / 86400);
    return match ($days) { 0 => 'today', 1 => 'tomorrow', default => "in $days days" };
}

// ── Inspection tasks not yet inspected ──
$tasks = $db->query(
    'SELECT task_id, team_id, task_code, title, location_text, due_date, assigned_to
       FROM InspectionTasks
      WHERE status = "assigned" AND due_date IS NOT NULL AND due_reminder_sent_at IS NULL
        AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL ' . REMIND_DAYS . ' DAY'
)->fetchAll();

foreach ($tasks as $t) {
    $when    = dueWhen($t['due_date']);
    $details = ['Task' => "{$t['task_code']} · {$t['title']}", 'Location' => $t['location_text'],
                'Due'  => notifyDate($t['due_date'])];
    notifyUsers([(int)$t['assigned_to']], (int)$t['team_id'], "Due $when: {$t['task_code']}",
        "This inspection task is due $when.", $details);
    notifyRole((int)$t['team_id'], 'supervisor', "Due $when: {$t['task_code']}",
        "An inspection task in your team is due $when and has not been inspected yet.", $details);
    $db->prepare('UPDATE InspectionTasks SET due_reminder_sent_at = NOW(), updated_at = updated_at WHERE task_id = ?')
       ->execute([$t['task_id']]);
}

// ── Work orders still being worked on ──
$wos = $db->query(
    'SELECT wo_id, team_id, wo_code, deadline
       FROM WorkOrders
      WHERE status IN ("pending", "in_progress", "on_hold", "rework")
        AND deadline IS NOT NULL AND deadline_reminder_sent_at IS NULL
        AND deadline BETWEEN CURDATE() AND CURDATE() + INTERVAL ' . REMIND_DAYS . ' DAY'
)->fetchAll();

foreach ($wos as $w) {
    $when    = dueWhen($w['deadline']);
    $details = woDetails((int)$w['wo_id']);
    notifyUsers(woCrewIds((int)$w['wo_id']), (int)$w['team_id'], "Due $when: {$w['wo_code']}",
        "This work order is due $when.", $details);
    notifyRole((int)$w['team_id'], 'supervisor', "Due $when: {$w['wo_code']}",
        "A work order in your team is due $when and is not finished yet.", $details);
    $db->prepare('UPDATE WorkOrders SET deadline_reminder_sent_at = NOW(), updated_at = updated_at WHERE wo_id = ?')
       ->execute([$w['wo_id']]);
}

flushMailQueue();
echo date('Y-m-d H:i') . ': reminders for ' . count($tasks) . ' task(s) and ' . count($wos) . " work order(s).\n";