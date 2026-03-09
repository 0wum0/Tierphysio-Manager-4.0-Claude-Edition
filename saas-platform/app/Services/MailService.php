<?php

declare(strict_types=1);

namespace Saas\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Saas\Core\Config;

class MailService
{
    public function __construct(private Config $config) {}

    private function mailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $this->config->get('mail.host', 'localhost');
        $mail->SMTPAuth   = !empty($this->config->get('mail.username'));
        $mail->Username   = $this->config->get('mail.username', '');
        $mail->Password   = $this->config->get('mail.password', '');
        $mail->SMTPSecure = $this->config->get('mail.encryption', 'tls');
        $mail->Port       = (int)$this->config->get('mail.port', 587);
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(
            $this->config->get('mail.from.address', 'noreply@tierphysio.de'),
            $this->config->get('mail.from.name', 'Tierphysio SaaS')
        );
        return $mail;
    }

    public function sendWelcome(
        string $email,
        string $name,
        string $practiceName,
        string $loginEmail,
        string $password,
        string $licenseToken,
        string $loginUrl = ''
    ): void {
        if ($loginUrl === '') {
            $loginUrl = rtrim($this->config->get('app.url', ''), '/');
        }
        $mail = $this->mailer();
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = '🐾 Willkommen bei Tierphysio Manager – Ihre Zugangsdaten';
        $mail->Body    = $this->welcomeHtml($name, $practiceName, $loginEmail, $password, $loginUrl);
        $mail->AltBody = "Willkommen {$name},\n\nIhre Praxis '{$practiceName}' wurde erfolgreich eingerichtet.\n\nIhr persönlicher Login:\n{$loginUrl}\n\nE-Mail: {$loginEmail}\nPasswort: {$password}\n\nBitte ändern Sie Ihr Passwort nach dem ersten Login.\n\nMit freundlichen Grüßen\nDas Tierphysio Team";
        $mail->send();
    }

    public function sendPasswordReset(string $email, string $name, string $resetUrl): void
    {
        $mail = $this->mailer();
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Passwort zurücksetzen – Tierphysio SaaS';
        $mail->Body    = $this->resetHtml($name, $resetUrl);
        $mail->AltBody = "Hallo {$name},\n\nPasswort zurücksetzen: {$resetUrl}\n\nDieser Link ist 2 Stunden gültig.";
        $mail->send();
    }

    public function sendStatusNotification(string $email, string $name, string $status): void
    {
        $messages = [
            'suspended' => 'Ihr Konto wurde gesperrt. Bitte kontaktieren Sie den Support.',
            'cancelled' => 'Ihr Abonnement wurde gekündigt. Wir bedauern Ihren Abgang.',
            'active'    => 'Ihr Konto wurde reaktiviert. Willkommen zurück!',
        ];
        $mail = $this->mailer();
        $mail->addAddress($email, $name);
        $mail->Subject = 'Kontostatusänderung – Tierphysio SaaS';
        $mail->Body    = '<p>Hallo ' . htmlspecialchars($name) . ',</p><p>' . ($messages[$status] ?? 'Ihr Kontostatus hat sich geändert.') . '</p>';
        $mail->AltBody = 'Hallo ' . $name . ', ' . ($messages[$status] ?? 'Ihr Kontostatus hat sich geändert.');
        $mail->send();
    }

    private function welcomeHtml(string $name, string $practice, string $email, string $password, string $loginUrl): string
    {
        $nameHtml     = htmlspecialchars($name,     ENT_QUOTES);
        $practiceHtml = htmlspecialchars($practice, ENT_QUOTES);
        $emailHtml    = htmlspecialchars($email,    ENT_QUOTES);
        $loginUrlHtml = htmlspecialchars($loginUrl, ENT_QUOTES);
        $year         = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="de" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Willkommen bei Tierphysio Manager</title>
</head>
<body style="margin:0;padding:0;background-color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

  <!-- Outer wrapper -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0f172a;min-height:100vh;">
    <tr>
      <td align="center" style="padding:40px 16px;">

        <!-- Card -->
        <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#1e293b;border-radius:16px;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,0.5);">

          <!-- Header with gradient -->
          <tr>
            <td style="background:linear-gradient(135deg,#1d4ed8 0%,#7c3aed 100%);padding:48px 40px 40px;text-align:center;">
              <!-- Paw icon -->
              <div style="display:inline-block;background:rgba(255,255,255,0.15);border-radius:50%;width:72px;height:72px;line-height:72px;font-size:36px;margin-bottom:20px;">🐾</div>
              <h1 style="margin:0;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:-0.5px;">Willkommen bei<br>Tierphysio Manager!</h1>
              <p style="margin:12px 0 0;color:rgba(255,255,255,0.8);font-size:16px;">Ihre Praxis ist startklar.</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:40px;">
              <p style="margin:0 0 8px;color:#94a3b8;font-size:14px;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Hallo,</p>
              <p style="margin:0 0 24px;color:#f1f5f9;font-size:22px;font-weight:700;">{$nameHtml} 👋</p>

              <p style="margin:0 0 28px;color:#94a3b8;font-size:15px;line-height:1.7;">
                Ihre Praxis <strong style="color:#e2e8f0;">{$practiceHtml}</strong> wurde erfolgreich eingerichtet.
                Ab sofort haben Sie Zugriff auf Ihre persönliche Praxisverwaltung.
              </p>

              <!-- Login URL button -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:32px;">
                <tr>
                  <td align="center">
                    <a href="{$loginUrlHtml}" style="display:inline-block;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;padding:16px 40px;border-radius:50px;letter-spacing:0.3px;box-shadow:0 4px 15px rgba(37,99,235,0.4);">Jetzt einloggen →</a>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding-top:12px;">
                    <span style="color:#475569;font-size:13px;">{$loginUrlHtml}</span>
                  </td>
                </tr>
              </table>

              <!-- Credentials box -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0f172a;border-radius:12px;border:1px solid #334155;overflow:hidden;margin-bottom:28px;">
                <tr>
                  <td style="padding:20px 24px 12px;">
                    <p style="margin:0 0 16px;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:1px;font-weight:600;">🔑 Ihre Zugangsdaten</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:0 24px 8px;">
                    <table width="100%" cellpadding="0" cellspacing="0" border="0">
                      <tr>
                        <td style="padding:10px 0;border-bottom:1px solid #1e293b;">
                          <span style="color:#64748b;font-size:13px;display:block;margin-bottom:4px;">E-Mail</span>
                          <span style="color:#f1f5f9;font-size:15px;font-weight:600;font-family:'Courier New',monospace;">{$emailHtml}</span>
                        </td>
                      </tr>
                      <tr>
                        <td style="padding:10px 0;">
                          <span style="color:#64748b;font-size:13px;display:block;margin-bottom:4px;">Passwort (temporär)</span>
                          <span style="color:#a78bfa;font-size:15px;font-weight:600;font-family:'Courier New',monospace;background:#1e293b;padding:6px 12px;border-radius:6px;display:inline-block;">{$password}</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td style="padding:12px 24px 20px;">
                    <p style="margin:0;color:#f59e0b;font-size:13px;">⚠️ Bitte ändern Sie Ihr Passwort nach dem ersten Login.</p>
                  </td>
                </tr>
              </table>

              <!-- Feature highlights -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                <tr>
                  <td width="50%" style="padding:0 6px 12px 0;vertical-align:top;">
                    <table width="100%" cellpadding="16" cellspacing="0" border="0" style="background:#1e3a5f;border-radius:10px;border:1px solid #1d4ed8;">
                      <tr><td>
                        <div style="font-size:22px;margin-bottom:6px;">🐕</div>
                        <div style="color:#93c5fd;font-size:13px;font-weight:600;">Patienten & Besitzer</div>
                        <div style="color:#64748b;font-size:12px;margin-top:4px;">Vollständige Kartei­verwaltung</div>
                      </td></tr>
                    </table>
                  </td>
                  <td width="50%" style="padding:0 0 12px 6px;vertical-align:top;">
                    <table width="100%" cellpadding="16" cellspacing="0" border="0" style="background:#1e3a2f;border-radius:10px;border:1px solid #16a34a;">
                      <tr><td>
                        <div style="font-size:22px;margin-bottom:6px;">📅</div>
                        <div style="color:#86efac;font-size:13px;font-weight:600;">Terminkalender</div>
                        <div style="color:#64748b;font-size:12px;margin-top:4px;">Termine planen & verwalten</div>
                      </td></tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td width="50%" style="padding:0 6px 0 0;vertical-align:top;">
                    <table width="100%" cellpadding="16" cellspacing="0" border="0" style="background:#2d1b69;border-radius:10px;border:1px solid #7c3aed;">
                      <tr><td>
                        <div style="font-size:22px;margin-bottom:6px;">🧾</div>
                        <div style="color:#c4b5fd;font-size:13px;font-weight:600;">Rechnungen</div>
                        <div style="color:#64748b;font-size:12px;margin-top:4px;">PDF-Rechnungen erstellen</div>
                      </td></tr>
                    </table>
                  </td>
                  <td width="50%" style="padding:0 0 0 6px;vertical-align:top;">
                    <table width="100%" cellpadding="16" cellspacing="0" border="0" style="background:#1e293b;border-radius:10px;border:1px solid #475569;">
                      <tr><td>
                        <div style="font-size:22px;margin-bottom:6px;">📊</div>
                        <div style="color:#cbd5e1;font-size:13px;font-weight:600;">Dashboard</div>
                        <div style="color:#64748b;font-size:12px;margin-top:4px;">Umsatz & Statistiken</div>
                      </td></tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- Support note -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#1e293b;border-radius:10px;border-left:4px solid #2563eb;">
                <tr>
                  <td style="padding:16px 20px;">
                    <p style="margin:0;color:#94a3b8;font-size:14px;line-height:1.6;">
                      💬 <strong style="color:#e2e8f0;">Fragen oder Probleme?</strong><br>
                      Wir helfen gerne weiter – schreiben Sie uns einfach an<br>
                      <a href="mailto:support@tierphysio-manager.de" style="color:#60a5fa;text-decoration:none;">support@tierphysio-manager.de</a>
                    </p>
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#0f172a;padding:24px 40px;text-align:center;border-top:1px solid #1e293b;">
              <p style="margin:0 0 8px;color:#475569;font-size:12px;">Tierphysio Manager SaaS &bull; DSGVO-konform &bull; Hosted in der EU</p>
              <p style="margin:0;color:#334155;font-size:11px;">© {$year} Tierphysio Manager. Alle Rechte vorbehalten.</p>
            </td>
          </tr>

        </table>
        <!-- /Card -->

      </td>
    </tr>
  </table>

</body>
</html>
HTML;
    }

    private function resetHtml(string $name, string $resetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
.wrap{max-width:600px;margin:40px auto;background:#fff;border-radius:8px;overflow:hidden}
.header{background:#2563eb;color:#fff;padding:30px;text-align:center}
.body{padding:30px}
.btn{display:inline-block;background:#2563eb;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;margin:20px 0}
.footer{background:#f8fafc;padding:15px;text-align:center;font-size:12px;color:#64748b}
</style></head>
<body>
<div class="wrap">
  <div class="header"><h1>Passwort zurücksetzen</h1></div>
  <div class="body">
    <p>Hallo <strong>{$name}</strong>,</p>
    <p>Sie haben eine Anfrage zum Zurücksetzen Ihres Passworts gestellt.</p>
    <a href="{$resetUrl}" class="btn">Passwort jetzt zurücksetzen</a>
    <p>Dieser Link ist <strong>2 Stunden</strong> gültig.</p>
    <p>Falls Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese E-Mail.</p>
  </div>
  <div class="footer">Tierphysio Manager SaaS &bull; DSGVO-konform &bull; EU-Hosting</div>
</div>
</body></html>
HTML;
    }
}
