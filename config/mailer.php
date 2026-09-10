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
// EMAIL
// ════════════════════════════════════════════════════════════

function emailLogoUrl(): string
{
    return APP_URL . '/logo/icon-512.png';
}

function codeEmailBody(string $name, string $code, string $heading, string $purposeLine): string
{
    $safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode    = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $logoUrl     = emailLogoUrl();

    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px;text-align:center">
  <img src="{$logoUrl}" alt="ConstructFlow" width="56" height="56" style="display:block;margin:0 auto 20px;border-radius:12px"/>

  <p style="font-size:16px;color:#1a1a2e;margin:0 0 24px">
    {$safeHeading}<br><b>{$safeName}</b>
  </p>

  <div style="border:1px solid #d1d5db;border-radius:12px;padding:24px;text-align:left">
    <p style="margin:0 0 16px;font-size:14px;color:#1a1a2e">{$purposeLine}</p>

    <p style="margin:0 0 20px;text-align:center">
      <span style="display:inline-block;color:#f59e0b;font-size:34px;
                   font-weight:800;letter-spacing:8px">{$safeCode}</span>
    </p>

    <p style="margin:0 0 10px;font-size:13.5px;color:#374151">
      This code expires in <b>10 minutes</b> and can only be used once.
    </p>
    <p style="margin:0 0 20px;font-size:13.5px;font-weight:700;color:#1a1a2e">
      Don't share your code with anyone.
    </p>

    <p style="margin:0;font-size:13.5px;color:#374151">
      Thank you,<br>The ConstructFlow Team
    </p>
  </div>
</div>
HTML;
}

function registrationCodeEmailBody(string $name, string $code): string
{
    return codeEmailBody(
        $name, $code,
        'Please verify your account,',
        'Here is your ConstructFlow verification code:'
    );
}

function passwordResetCodeEmailBody(string $name, string $code): string
{
    return codeEmailBody(
        $name, $code,
        'Reset your password,',
        'Here is your ConstructFlow password reset code:'
    );
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
    $hours       = INVITE_TTL_HOURS;
    $logoUrl     = emailLogoUrl();

    $whatHappens = $hasAccount
        ? 'You already have a ConstructFlow account. Opening the link will ask you to log in, then add you to the team.'
        : 'Opening the link will direct you to ConstructFlow to set up your account.';

    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px;text-align:center">
  <img src="{$logoUrl}" alt="ConstructFlow" width="56" height="56" style="display:block;margin:0 auto 20px;border-radius:12px"/>

  <p style="font-size:16px;color:#1a1a2e;margin:0 0 24px">
    <b>{$safeInviter}</b> has invited you to join <b>{$safeTeam}</b> as a <b>{$safeRole}</b>.
  </p>

  <div style="border:1px solid #d1d5db;border-radius:12px;padding:24px;text-align:left">
    <p style="margin:0 0 20px;font-size:14px;color:#1a1a2e">{$whatHappens}</p>

    <p style="margin:0 0 20px;text-align:center">
      <a href="{$safeUrl}"
         style="display:inline-block;border:2px solid #f59e0b;color:#f59e0b;
                background:#ffffff;font-size:18px;font-weight:800;
                text-decoration:none;padding:16px 32px;border-radius:10px">
        Accept Invitation
      </a>
    </p>

    <p style="margin:0 0 10px;font-size:13.5px;color:#374151">
      This invitation expires in <b>{$hours} hours</b> and can only be used once.
    </p>
    <p style="margin:0 0 14px;font-size:12.5px;color:#374151;word-break:break-all">
      If the button doesn't work, paste this into your browser:<br><b>{$safeUrl}</b>
    </p>
    <p style="margin:0 0 20px;font-size:13.5px;font-weight:700;color:#1a1a2e">
      Don't share your link with anyone.
    </p>

    <p style="margin:0;font-size:13.5px;color:#374151">
      Thank you,<br>The ConstructFlow Team
    </p>
  </div>
</div>
HTML;
}
