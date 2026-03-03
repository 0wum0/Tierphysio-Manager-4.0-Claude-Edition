<?php

declare(strict_types=1);

namespace Plugins\PatientInvite;

use App\Core\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class InviteMailService
{
    public function __construct(private readonly Config $config) {}

    public function sendInviteEmail(string $toEmail, string $inviteUrl, string $note = ''): bool
    {
        $fromAddress = $this->config->get('mail.from_address', '');
        $fromName    = $this->config->get('mail.from_name', 'Tierphysio Manager');
        $appName     = $this->config->get('app.name', 'Tierphysio Manager');

        $subject = "Ihre Einladung zur Anmeldung — {$appName}";
        $html    = $this->buildEmailHtml($inviteUrl, $note, $appName, $fromName);
        $plain   = $this->buildEmailPlain($inviteUrl, $note, $appName);

        if ($this->config->get('mail.driver') === 'smtp' && !empty($fromAddress)) {
            return $this->sendViaSMTP($toEmail, $subject, $html, $plain);
        }

        if (empty($fromAddress)) return false;
        $headers  = "From: {$fromName} <{$fromAddress}>\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        return mail($toEmail, $subject, $html, $headers);
    }

    public function buildWhatsAppUrl(string $phone, string $inviteUrl, string $appName): string
    {
        $phone   = preg_replace('/[^0-9+]/', '', $phone);
        $message = "Hallo! Sie wurden eingeladen, sich bei {$appName} anzumelden.\n\nKlicken Sie hier, um Ihr Tier und sich selbst direkt zu registrieren:\n{$inviteUrl}\n\nDer Link ist 7 Tage gültig.";
        return 'https://wa.me/' . ltrim($phone, '+') . '?text=' . rawurlencode($message);
    }

    private function sendViaSMTP(string $to, string $subject, string $html, string $plain): bool
    {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->config->get('mail.host');
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config->get('mail.username');
            $mail->Password   = $this->config->get('mail.password');
            $mail->SMTPSecure = $this->config->get('mail.encryption', 'tls');
            $mail->Port       = (int)$this->config->get('mail.port', 587);
            $mail->CharSet    = 'UTF-8';

            $fromAddress = $this->config->get('mail.from_address');
            $fromName    = $this->config->get('mail.from_name', 'Tierphysio Manager');
            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $plain;
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('[PatientInvite] SMTP error: ' . $e->getMessage());
            return false;
        }
    }

    private function buildEmailHtml(string $url, string $note, string $appName, string $fromName): string
    {
        $noteHtml = $note ? '<p style="background:#f0f4ff;border-left:4px solid #4f7cff;padding:12px 16px;border-radius:4px;margin:20px 0;color:#333;">' . nl2br(htmlspecialchars($note)) . '</p>' : '';
        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 20px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
        <tr><td style="background:linear-gradient(135deg,#4f7cff,#8b5cf6);padding:32px 40px;text-align:center;">
          <div style="font-size:2rem;margin-bottom:8px;">🐾</div>
          <h1 style="margin:0;color:#fff;font-size:1.4rem;font-weight:700;">{$appName}</h1>
          <p style="margin:4px 0 0;color:rgba(255,255,255,0.8);font-size:0.9rem;">Einladung zur Patientenanmeldung</p>
        </td></tr>
        <tr><td style="padding:36px 40px;">
          <h2 style="margin:0 0 12px;font-size:1.2rem;color:#1a1a2e;">Sie wurden eingeladen!</h2>
          <p style="color:#555;line-height:1.7;margin:0 0 20px;">{$fromName} lädt Sie ein, Ihr Tier und sich als Besitzer direkt in unserem System zu registrieren. Der Vorgang dauert nur wenige Minuten.</p>
          {$noteHtml}
          <div style="text-align:center;margin:32px 0;">
            <a href="{$url}" style="display:inline-block;background:linear-gradient(135deg,#4f7cff,#8b5cf6);color:#fff;text-decoration:none;padding:14px 36px;border-radius:100px;font-size:1rem;font-weight:700;letter-spacing:0.02em;">Jetzt registrieren →</a>
          </div>
          <p style="color:#999;font-size:0.8rem;text-align:center;margin:0;">Dieser Link ist 7 Tage gültig. Falls Sie diese E-Mail nicht angefordert haben, können Sie sie ignorieren.</p>
          <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
          <p style="color:#bbb;font-size:0.75rem;text-align:center;margin:0;">Link: <a href="{$url}" style="color:#4f7cff;">{$url}</a></p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function buildEmailPlain(string $url, string $note, string $appName): string
    {
        $notePart = $note ? "\nNachricht: {$note}\n" : '';
        return "Sie wurden zur Anmeldung bei {$appName} eingeladen.\n{$notePart}\nJetzt registrieren:\n{$url}\n\nDieser Link ist 7 Tage gültig.";
    }
}
