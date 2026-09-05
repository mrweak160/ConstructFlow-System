<?php
$secretsFile = __DIR__ . '/secrets.php';
if (file_exists($secretsFile)) {
    require_once $secretsFile;
}

if (!defined('BREVO_API_KEY')) define('BREVO_API_KEY', '');
if (!defined('MAIL_FROM'))     define('MAIL_FROM',     'no-reply@constructflow.local');
if (!defined('MAIL_FROM_NAME'))define('MAIL_FROM_NAME','ConstructFlow');

// ── ENVIRONMENT ──────────────────────────────────────────────
// 'local' on XAMPP, 'production' on the live server.
define('APP_ENV', getenv('APP_ENV') ?: 'local');

// ── PUBLIC URL ───────────────────────────────────────────────
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost/constructflow', '/'));

define('APP_HTTPS', APP_ENV === 'production');

// ── MAIL DRIVER ──────────────────────────────────────────────
define('MAIL_DRIVER', getenv('MAIL_DRIVER') ?: 'brevo');

define('MAIL_API_KEY', BREVO_API_KEY);

// ── SMTP (only used when MAIL_DRIVER is 'smtp') ──────────────
define('MAIL_HOST', getenv('MAIL_HOST') ?: 'smtp-relay.brevo.com');
define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
define('MAIL_USER', getenv('MAIL_USER') ?: '');
define('MAIL_PASS', getenv('MAIL_PASS') ?: '');
