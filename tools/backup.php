<?php
/**
 * Back up the database to a compressed SQL file.
 *
 *   XAMPP (Windows):  C:\xampp\php\php.exe tools\backup.php
 *   Hostinger cron:   /usr/bin/php /home/<user>/domains/<site>/public_html/tools/backup.php
 *
 * Writes cf-backup-YYYY-MM-DD-HHMMSS.sql.gz into a "backups" folder beside
 * the site's folder — outside public_html, so it can never be downloaded —
 * and keeps the newest 14. Optional arguments: a different folder, and how
 * many to keep:  php tools/backup.php /home/<user>/backups 30
 *
 * The activity log's signing key (storage/log_key.php) is copied there too:
 * a restored database can only be checked with the key it was sealed with.
 *
 * Restore: unzip the file and import it in phpMyAdmin, or
 *   gunzip -c cf-backup-....sql.gz | mysql -u <user> -p <database>
 *
 * Command line only: over the web this file returns 404.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
date_default_timezone_set('Asia/Manila');   // file names and the header in Philippine time

$dir  = rtrim($argv[1] ?? dirname(__DIR__, 2) . '/backups', '/\\');
$keep = max(1, (int)($argv[2] ?? 14));

try {
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException("Cannot create $dir");
    }
    // In case the folder ever ends up inside a web root.
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\n");
    }

    $db     = getDB();
    $dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $file   = "$dir/cf-backup-" . date('Y-m-d-His') . '.sql.gz';
    $gz     = gzopen($file, 'wb6');
    if (!$gz) {
        throw new RuntimeException("Cannot write $file");
    }

    gzwrite($gz, "-- ConstructFlow backup of `$dbName`, " . date('Y-m-d H:i:s') . "\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $rows   = 0;
    foreach ($tables as $table) {
        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

        $stmt  = $db->query("SELECT * FROM `$table`");
        $batch = [];
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(
                fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $r)) . ')';
            if (count($batch) === 200) {
                gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
            $rows++;
        }
        if ($batch) {
            gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
    gzclose($gz);

    // The log key, so the restored log can still be checked.
    $key = __DIR__ . '/../storage/log_key.php';
    if (is_file($key)) {
        copy($key, "$dir/log_key.php");
        @chmod("$dir/log_key.php", 0600);
    }

    // Keep the newest $keep.
    $all = glob("$dir/cf-backup-*.sql.gz") ?: [];
    rsort($all);
    foreach (array_slice($all, $keep) as $old) {
        unlink($old);
    }

    printf("Backed up %d tables, %d rows to %s (%s KB). Keeping %d of %d.\n",
        count($tables), $rows, $file, number_format(filesize($file) / 1024, 1),
        min($keep, count($all)), count($all));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
