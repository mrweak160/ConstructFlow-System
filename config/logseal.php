<?php
// ConstructFlow — Tamper-evident activity log
//
// Each team's log is a chain. Every entry is signed (HMAC-SHA256) together
// with the signature of the entry before it, using a key kept outside the
// database. Editing, inserting or deleting an entry straight in the database
// breaks the chain, and without the key it cannot be re-signed. LogChains
// keeps each chain's length and last signature, signed too, so entries cut
// off the end show as well.
//
// Signed: the entry's place in the chain, the previous signature, log_id,
// action, target, description, IP address and time. Not user_id: deleting
// an account clears it through the foreign key, and the description already
// names who acted.
//
// Entries are signed when the request that wrote them ends, after any
// transaction around them has been committed.

const LOG_SEAL_LOCK = 'cf_log_seal';

/**
 * The signing key: LOG_SIGNING_KEY from secrets.php if set, otherwise one
 * generated on first use into storage/log_key.php. Without the key the log
 * cannot be checked, so it belongs in the backups.
 */
function logSealKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    if (defined('LOG_SIGNING_KEY') && LOG_SIGNING_KEY !== '') {
        return $key = LOG_SIGNING_KEY;
    }

    $dir  = __DIR__ . '/../storage';
    $file = $dir . '/log_key.php';
    if (!is_file($file)) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // Two first requests must not each write a key of their own.
        $lock = fopen($dir . '/log_key.lock', 'c');
        flock($lock, LOCK_EX);
        if (!is_file($file)) {
            $new = bin2hex(random_bytes(32));
            file_put_contents($file,
                "<?php\n// Signs the activity log. Back it up with the database: without it the\n"
                . "// log cannot be checked. Never share it or commit it.\nreturn '$new';\n");
            @chmod($file, 0600);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $k = include $file;
    if (!is_string($k) || strlen($k) < 32) {
        throw new RuntimeException('The activity log key could not be read.');
    }
    return $key = $k;
}

function logSealHash(int $chainKey, int $seq, string $prev, array $r): string
{
    return hash_hmac('sha256', implode("\x1f", [
        $chainKey, $seq, $prev, $r['log_id'], $r['action'],
        $r['target_type'] ?? '', $r['target_id'] ?? '',
        $r['description'] ?? '', $r['ip_address'] ?? '', $r['logged_at'],
    ]), logSealKey());
}

function logHeadSig(int $chainKey, int $length, string $head): string
{
    return hash_hmac('sha256', "head\x1f$chainKey\x1f$length\x1f$head", logSealKey());
}

/** A chain is a team's entries; 0 holds those outside any team. */
function logChainWhere(int $chainKey): string
{
    return $chainKey ? 'team_id = ?' : 'team_id IS NULL';
}
function logChainArgs(int $chainKey): array
{
    return $chainKey ? [$chainKey] : [];
}

const LOG_SEAL_COLS = 'log_id, action, target_type, target_id, description, ip_address, logged_at';

// ── QUEUEING ─────────────────────────────────────────────────

/** Signs this entry when the request ends. Called by logActivity(). */
function queueLogSeal(int $logId): void
{
    $GLOBALS['CF_LOG_PENDING'][] = $logId;
    logSealOnShutdown();
}

/**
 * Re-signs a chain from $fromSeq on, when ConstructFlow itself changed those
 * entries — deleting an account rewrites its address out of the log text.
 */
function resealLogFrom(int $chainKey, int $fromSeq): void
{
    $cur = $GLOBALS['CF_LOG_RESEAL'][$chainKey] ?? PHP_INT_MAX;
    $GLOBALS['CF_LOG_RESEAL'][$chainKey] = min($cur, $fromSeq);
    logSealOnShutdown();
}

function logSealOnShutdown(): void
{
    static $registered = false;
    if (!$registered) {
        register_shutdown_function('sealActivityLogs');
        $registered = true;
    }
}

// ── SIGNING ──────────────────────────────────────────────────

function sealActivityLogs(): void
{
    $ids    = array_values(array_unique($GLOBALS['CF_LOG_PENDING'] ?? []));
    $reseal = $GLOBALS['CF_LOG_RESEAL'] ?? [];
    $GLOBALS['CF_LOG_PENDING'] = $GLOBALS['CF_LOG_RESEAL'] = [];
    if (!$ids && !$reseal) {
        return;
    }

    try {
        $db = getDB();
        if (!logSealLock($db)) {
            error_log('ConstructFlow: activity log entries left unsigned; the seal lock timed out.');
            return;
        }
        try {
            foreach ($reseal as $chainKey => $fromSeq) {
                logResealChain($db, (int)$chainKey, (int)$fromSeq);
            }
            if ($ids) {
                $in   = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare(
                    "SELECT DISTINCT COALESCE(team_id, 0) FROM ActivityLogs
                      WHERE log_id IN ($in) AND row_hash IS NULL"
                );
                $stmt->execute($ids);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $chainKey) {
                    logSealChain($db, (int)$chainKey, $ids);
                }
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('" . LOG_SEAL_LOCK . "')");
        }
    } catch (Throwable $e) {
        error_log('ConstructFlow: could not sign the activity log: ' . $e->getMessage());
    }
}

function logSealLock(PDO $db): bool
{
    return (int)$db->query("SELECT GET_LOCK('" . LOG_SEAL_LOCK . "', 10)")->fetchColumn() === 1;
}

/** [length, last signature] of a chain, or null if it was never sealed. */
function logChainHead(PDO $db, int $chainKey): ?array
{
    $stmt = $db->prepare('SELECT length, head_hash, head_sig FROM LogChains WHERE chain_key = ?');
    $stmt->execute([$chainKey]);
    $h = $stmt->fetch();
    return $h ? [(int)$h['length'], (string)$h['head_hash'], (string)$h['head_sig']] : null;
}

function logSaveHead(PDO $db, int $chainKey, int $length, string $head): void
{
    $db->prepare(
        'INSERT INTO LogChains (chain_key, length, head_hash, head_sig) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE length = VALUES(length), head_hash = VALUES(head_hash), head_sig = VALUES(head_sig)'
    )->execute([$chainKey, $length, $head, logHeadSig($chainKey, $length, $head)]);
}

/**
 * Appends unsigned entries to a chain. The first time a chain is sealed, it
 * takes in every entry already there (written before the log was sealed);
 * after that, only the ones this request wrote — an unsigned entry from
 * anywhere else was not written by ConstructFlow.
 */
function logSealChain(PDO $db, int $chainKey, array $ids): void
{
    $head  = logChainHead($db, $chainKey);
    $where = logChainWhere($chainKey) . ' AND row_hash IS NULL';
    $args  = logChainArgs($chainKey);
    if ($head) {
        $where .= ' AND log_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args   = array_merge($args, $ids);
    }
    $stmt = $db->prepare('SELECT ' . LOG_SEAL_COLS . " FROM ActivityLogs WHERE $where ORDER BY log_id");
    $stmt->execute($args);

    [$len, $prev] = $head ?? [0, ''];
    $upd = $db->prepare('UPDATE ActivityLogs SET chain_seq = ?, prev_hash = ?, row_hash = ? WHERE log_id = ?');
    $any = false;
    foreach ($stmt->fetchAll() as $r) {
        $len++;
        $hash = logSealHash($chainKey, $len, $prev, $r);
        $upd->execute([$len, $prev === '' ? null : $prev, $hash, $r['log_id']]);
        $prev = $hash;
        $any  = true;
    }
    if ($any) {
        logSaveHead($db, $chainKey, $len, $prev);
    }
}

function logResealChain(PDO $db, int $chainKey, int $fromSeq): void
{
    $prev = '';
    if ($fromSeq > 1) {
        $p = $db->prepare('SELECT row_hash FROM ActivityLogs WHERE ' . logChainWhere($chainKey) . ' AND chain_seq = ?');
        $p->execute(array_merge(logChainArgs($chainKey), [$fromSeq - 1]));
        $prev = (string)$p->fetchColumn();
    }
    $stmt = $db->prepare(
        'SELECT chain_seq, ' . LOG_SEAL_COLS . ' FROM ActivityLogs
          WHERE ' . logChainWhere($chainKey) . ' AND chain_seq >= ? ORDER BY chain_seq'
    );
    $stmt->execute(array_merge(logChainArgs($chainKey), [$fromSeq]));
    $upd  = $db->prepare('UPDATE ActivityLogs SET prev_hash = ?, row_hash = ? WHERE log_id = ?');
    $last = null;
    foreach ($stmt->fetchAll() as $r) {
        $hash = logSealHash($chainKey, (int)$r['chain_seq'], $prev, $r);
        $upd->execute([$prev === '' ? null : $prev, $hash, $r['log_id']]);
        $prev = $hash;
        $last = (int)$r['chain_seq'];
    }
    if ($last !== null) {
        logSaveHead($db, $chainKey, $last, $prev);
    }
}

// ── CHECKING ─────────────────────────────────────────────────

/**
 * Walks a team's chain and reports whether it is exactly as ConstructFlow
 * signed it. Returns ['intact' => bool, 'checked' => n, 'problems' => [...]].
 */
function verifyActivityLog(int $teamId): array
{
    $db = getDB();

    // A chain never sealed yet (the log predates sealing) is sealed now, as
    // it stands, so the check has something to stand on from here on.
    if (!logChainHead($db, $teamId)) {
        if (logSealLock($db)) {
            try {
                if (!logChainHead($db, $teamId)) {
                    logSealChain($db, $teamId, []);
                }
            } finally {
                $db->query("SELECT RELEASE_LOCK('" . LOG_SEAL_LOCK . "')");
            }
        }
    }

    $stmt = $db->prepare(
        'SELECT chain_seq, prev_hash, row_hash, logged_at < NOW() - INTERVAL 1 MINUTE AS settled, ' . LOG_SEAL_COLS . '
           FROM ActivityLogs WHERE team_id = ?
          ORDER BY chain_seq IS NULL, chain_seq, log_id'
    );
    $stmt->execute([$teamId]);

    $problems = [];
    $flag = function (string $msg) use (&$problems) {
        if (count($problems) < 5) $problems[] = $msg;
    };
    $when = fn($r) => date('M j, Y g:i A', strtotime($r['logged_at']));

    $expect = 1; $prev = ''; $checked = 0; $unsigned = 0;
    foreach ($stmt->fetchAll() as $r) {
        if ($r['chain_seq'] === null) {
            // One written in the last minute may still be being signed.
            if ($r['settled']) $unsigned++;
            continue;
        }
        $seq = (int)$r['chain_seq'];
        if ($seq !== $expect) {
            $flag($seq > $expect
                ? ($seq - $expect) . " entr" . ($seq - $expect === 1 ? 'y was' : 'ies were') . " deleted before the one at {$when($r)}."
                : "The entry at {$when($r)} is out of place in the chain.");
        } elseif ((string)$r['prev_hash'] !== $prev) {
            $flag("The link before the entry at {$when($r)} was changed.");
        }
        if (!hash_equals(logSealHash($teamId, $seq, (string)$r['prev_hash'], $r), (string)$r['row_hash'])) {
            $flag("The entry at {$when($r)} (" . $r['action'] . ") was changed.");
        }
        $prev   = (string)$r['row_hash'];
        $expect = $seq + 1;
        $checked++;
    }

    $head = logChainHead($db, $teamId);
    $len  = $expect - 1;
    if ($head) {
        [$hLen, $hHash, $hSig] = $head;
        if (!hash_equals(logHeadSig($teamId, $hLen, $hHash), $hSig)) {
            $flag('The record of how long the log is was changed.');
        } elseif ($hLen > $len) {
            $flag(($hLen - $len) . ' entr' . ($hLen - $len === 1 ? 'y was' : 'ies were') . ' deleted from the end of the log.');
        } elseif ($hLen !== $len || $hHash !== $prev) {
            $flag('The end of the log does not match its signed record.');
        }
    } elseif ($checked > 0) {
        $flag('The record of how long the log is was deleted.');
    }
    if ($unsigned > 0) {
        $flag("$unsigned entr" . ($unsigned === 1 ? 'y was' : 'ies were') . ' added without ConstructFlow signing ' . ($unsigned === 1 ? 'it.' : 'them.'));
    }

    return ['intact' => !$problems, 'checked' => $checked, 'problems' => $problems];
}
