<?php
// ConstructFlow — Email delivery

require_once __DIR__ . '/mail_config.php';

// ════════════════════════════════════════════════════════════
// THE CONTRACT
// ════════════════════════════════════════════════════════════

interface MailerInterface
{
    /** Returns true when the message was accepted for delivery. */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool;

    /** Human-readable name, used in logs and the diagnostic script. */
    public function getName(): string;
}

// ════════════════════════════════════════════════════════════
// CHANNEL 1 — LOG FILE (development)
// ════════════════════════════════════════════════════════════

class LogFileMailer implements MailerInterface
{
    // private: nothing outside needs to know where the log lives,
    // and nothing outside should be able to repoint it.
    private string $logPath;

    public function __construct(string $storageDir)
    {
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $this->logPath = $storageDir . '/mail.log';
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        // The 6-character code is pulled onto its own line so you can
        // read it at a glance instead of hunting through the HTML.
        $code = $this->extractCode($htmlBody);

        $entry = str_repeat('=', 60) . "\n"
               . '[' . date('Y-m-d H:i:s') . "]\n"
               . "TO:      $toEmail ($toName)\n"
               . "SUBJECT: $subject\n"
               . ($code ? "CODE:    $code\n" : '')
               . str_repeat('-', 60) . "\n"
               . trim(strip_tags($htmlBody)) . "\n\n";

        return file_put_contents($this->logPath, $entry, FILE_APPEND) !== false;
    }

    /** Finds the styled code block in the email template. */
    private function extractCode(string $html): ?string
    {
        return preg_match('/>([A-Z0-9]{6})<\/span>/', $html, $m) ? $m[1] : null;
    }

    public function getName(): string
    {
        return 'Log file (' . $this->logPath . ')';
    }
}

// ════════════════════════════════════════════════════════════
// CHANNEL 2 — BREVO HTTP API (production)
// ════════════════════════════════════════════════════════════
class BrevoApiMailer implements MailerInterface
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    // Constructor property promotion (PHP 8): declares and assigns
    // in one step. Private because an API key must never be readable
    // from outside the object that uses it.
    public function __construct(
        private string $apiKey,
        private string $fromEmail,
        private string $fromName
    ) {}

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        if (!function_exists('curl_init')) {
            error_log('ConstructFlow: PHP cURL extension is not enabled; cannot reach the Brevo API.');
            return false;
        }

        $payload = [
            'sender'      => ['email' => $this->fromEmail, 'name' => $this->fromName],
            'to'          => [['email' => $toEmail, 'name' => $toName]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
            'textContent' => strip_tags($htmlBody),
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // 201 Created = accepted for delivery.
        if ($status === 201) {
            return true;
        }

        // Log the reason server-side. Callers only ever see false, so
        // provider details never leak to a user.
        error_log(sprintf(
            'ConstructFlow Brevo send failed. HTTP %d. curl: %s. Response: %s',
            $status, $curlErr ?: 'none', substr((string)$response, 0, 500)
        ));
        return false;
    }

    public function getName(): string
    {
        return 'Brevo API (from ' . $this->fromEmail . ')';
    }
}

// ════════════════════════════════════════════════════════════
// CHANNEL 3 — SMTP (optional, needs PHPMailer)
// ════════════════════════════════════════════════════════════

class SmtpMailer implements MailerInterface
{
    public function __construct(
        private string $host,
        private int    $port,
        private string $username,
        private string $password,
        private string $fromEmail,
        private string $fromName
    ) {}

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            error_log('ConstructFlow: SmtpMailer needs PHPMailer (composer require phpmailer/phpmailer).');
            return false;
        }
        require_once $autoload;

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->Port       = $this->port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            return $mail->send();
        } catch (Throwable $e) {
            error_log('ConstructFlow SMTP error: ' . $e->getMessage());
            return false;
        }
    }

    public function getName(): string
    {
        return 'SMTP (' . $this->host . ':' . $this->port . ')';
    }
}

// ════════════════════════════════════════════════════════════
// THE SERVICE
// ════════════════════════════════════════════════════════════

class EmailService
{
    private MailerInterface $mailer;

    // Single shared instance, so the channel is chosen once per
    // request rather than rebuilt for every email.
    private static ?EmailService $instance = null;

    public function __construct(?MailerInterface $mailer = null)
    {
        // A specific channel can be injected (useful for testing);
        // otherwise one is chosen from configuration.
        $this->mailer = $mailer ?? self::chooseMailer();
    }

    public static function getInstance(): EmailService
    {
        return self::$instance ??= new EmailService();
    }

    /** Reads config and returns the appropriate channel object. */
    private static function chooseMailer(): MailerInterface
    {
        $storage = __DIR__ . '/../storage';

        if (MAIL_DRIVER === 'brevo') {
            // Misconfiguration falls back to the log file rather than
            // throwing: a missing key should not take registration
            // down entirely, and the log makes the cause obvious.
            if (MAIL_API_KEY === '') {
                error_log('ConstructFlow: MAIL_DRIVER is brevo but the API key is empty. Falling back to log file.');
                return new LogFileMailer($storage);
            }
            return new BrevoApiMailer(MAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME);
        }

        if (MAIL_DRIVER === 'smtp') {
            return new SmtpMailer(MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASS, MAIL_FROM, MAIL_FROM_NAME);
        }

        if (MAIL_DRIVER !== 'log') {
            error_log('ConstructFlow: unknown MAIL_DRIVER "' . MAIL_DRIVER . '". Falling back to log file.');
        }
        return new LogFileMailer($storage);
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        return $this->mailer->send($toEmail, $toName, $subject, $htmlBody);
    }

    /** Which channel is active — used by the diagnostic script. */
    public function getDriverName(): string
    {
        return $this->mailer->getName();
    }
}

// ════════════════════════════════════════════════════════════
// BACKWARD-COMPATIBLE WRAPPER
// ════════════════════════════════════════════════════════════

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    return EmailService::getInstance()->send($toEmail, $toName, $subject, $htmlBody);
}

// ════════════════════════════════════════════════════════════
// TEMPLATES (unchanged)
// ════════════════════════════════════════════════════════════

function codeEmailBody(string $name, string $code, string $purposeLine): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px">
  <h2 style="color:#f59e0b;margin:0 0 16px">ConstructFlow</h2>
  <p>Hello {$safeName},</p>
  <p>{$purposeLine}</p>
  <p style="margin:28px 0;text-align:center">
    <span style="display:inline-block;background:#0f1117;color:#f59e0b;
                 font-size:28px;font-weight:bold;letter-spacing:6px;
                 padding:16px 24px;border-radius:8px;font-family:monospace">{$safeCode}</span>
  </p>
  <p style="font-size:13px;color:#555">
    This code expires in 10 minutes. If you didn't request this, you can ignore this email.
  </p>
</div>
HTML;
}

function registrationCodeEmailBody(string $name, string $code): string
{
    return codeEmailBody($name, $code, 'Enter this code to verify your email address and finish creating your account.');
}

function passwordResetCodeEmailBody(string $name, string $code): string
{
    return codeEmailBody($name, $code, 'Enter this code to reset your ConstructFlow password.');
}

function inviteEmailBody(
    string $inviterName,
    string $teamName,
    string $roleLabel,
    string $url,
    bool   $hasAccount
): string {
    $safeInviter = htmlspecialchars($inviterName, ENT_QUOTES, 'UTF-8');
    $safeTeam    = htmlspecialchars($teamName,    ENT_QUOTES, 'UTF-8');
    $safeRole    = htmlspecialchars($roleLabel,   ENT_QUOTES, 'UTF-8');
    $safeUrl     = htmlspecialchars($url,         ENT_QUOTES, 'UTF-8');
    $hours        = INVITE_TTL_HOURS;

    $whatHappens = $hasAccount
        ? 'You already have a ConstructFlow account, so opening the link will ask you to log in and then add you to the team.'
        : 'Opening the link lets you set your name and a password. You will not need a verification code — this link is your proof of address.';

    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px">
  <h2 style="color:#f59e0b;margin:0 0 16px">ConstructFlow</h2>
  <p>{$safeInviter} has invited you to join <b>{$safeTeam}</b> as a <b>{$safeRole}</b>.</p>
  <p>{$whatHappens}</p>
  <p style="margin:28px 0;text-align:center">
    <a href="{$safeUrl}"
       style="display:inline-block;background:#1a1a2e;color:#f59e0b;
              font-size:15px;font-weight:bold;text-decoration:none;
              padding:14px 28px;border-radius:8px">Accept invitation</a>
  </p>
  <p style="font-size:12px;color:#555;word-break:break-all">
    If the button doesn't work, paste this into your browser:<br>{$safeUrl}
  </p>
  <p style="font-size:13px;color:#555">
    This invitation expires in {$hours} hours. If you weren't expecting it, you can ignore this email.
  </p>
</div>
HTML;
}
