<?php
/**
 * Run the severity engine test set from the command line.
 *
 *   XAMPP (Windows):  C:\xampp\php\php.exe tools\run_severity_tests.php "added tile debonding"
 *   Linux / macOS:    php tools/run_severity_tests.php "added tile debonding"
 *
 * The optional argument is stored as the run's trigger note.
 * Command line only: over the web this file returns 404.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/SeverityTestRunner.php';

$result = SeverityTestRunner::run(getDB(), $argv[1] ?? '');
echo SeverityTestRunner::format($result);
exit($result['failed'] === 0 ? 0 : 1);
