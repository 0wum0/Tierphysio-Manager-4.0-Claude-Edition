<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    private string $lastError = '';

    public function __construct(
        private readonly SettingsRepository $settingsRepository
    ) {}

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /* ══════════════════════════════════════════════════════════
       PUBLIC SEND METHODS
    ══════════════════════════════════════════════════════════ */

    public function sendInvoice(array $invoice, array $owner, string $pdfContent): bool
    {
        try {
            $placeholders = $this->buildInvoicePlaceholders($invoice, $owner);
            $subject = $this->applyPlaceholders(
                $this->settingsRepository->get('email_invoice_subject', 'Ihre Rechnung {{invoice_number}}'),
                $placeholders
            );
            $bodyText = $this->applyPlaceholders(
                $this->settingsRepository->get('email_invoice_body',
                    "Sehr geehrte/r {{owner_name}},\n\nanbei erhalten Sie Ihre Rechnung {{invoice_number}} vom {{issue_date}}.\n\nGesamtbetrag: {{total_gross}}\nBitte überweisen Sie den Betrag bis zum {{due_date}}.\n\nMit freundlichen Grüßen\n{{company_name}}"
                ),
                $placeholders
            );

            $mailer = $this->createMailer();
            $mailer->addAddress($owner['email'], ($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $this->wrapInEmailLayout($subject, $bodyText, '📄');
            $mailer->AltBody = $bodyText;
            $mailer->addStringAttachment($pdfContent, 'Rechnung-' . $invoice['invoice_number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');

            return $mailer->send();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[MailService::sendInvoice] ' . $e->getMessage());
            return false;
        }
    }

    public function sendReceipt(array $invoice, array $owner, string $pdfContent): bool
    {
        try {
            $placeholders = $this->buildInvoicePlaceholders($invoice, $owner);
            $subject = $this->applyPlaceholders(
                $this->settingsRepository->get('email_receipt_subject', 'Ihre Quittung {{invoice_number}}'),
                $placeholders
            );
            $bodyText = $this->applyPlaceholders(
                $this->settingsRepository->get('email_receipt_body',
                    "Sehr geehrte/r {{owner_name}},\n\nvielen Dank für Ihre Zahlung. Anbei erhalten Sie Ihre Quittung für Rechnung {{invoice_number}} vom {{issue_date}}.\n\nBezahlter Betrag: {{total_gross}}\n\nMit freundlichen Grüßen\n{{company_name}}"
                ),
                $placeholders
            );

            $mailer = $this->createMailer();
            $mailer->addAddress($owner['email'], ($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $this->wrapInEmailLayout($subject, $bodyText, '✅');
            $mailer->AltBody = $bodyText;
            $mailer->addStringAttachment($pdfContent, 'Quittung-' . $invoice['invoice_number'] . '.pdf', PHPMailer::ENCODING_BASE64, 'application/pdf');

            return $mailer->send();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[MailService::sendReceipt] ' . $e->getMessage());
            return false;
        }
    }

    public function sendReminder(array $appointment): bool
    {
        try {
            $placeholders = $this->buildReminderPlaceholders($appointment);
            $subject = $this->applyPlaceholders(
                $this->settingsRepository->get('email_reminder_subject', 'Terminerinnerung: {{appointment_title}} am {{appointment_date}}'),
                $placeholders
            );
            $bodyText = $this->applyPlaceholders(
                $this->settingsRepository->get('email_reminder_body',
                    "Hallo {{owner_name}},\n\nwir möchten Sie an Ihren bevorstehenden Termin erinnern:\n\n📅 {{appointment_title}}\nDatum: {{appointment_date}}\nUhrzeit: {{appointment_time}}\n{{appointment_patient}}\n\nFalls Sie den Termin absagen oder verschieben möchten, kontaktieren Sie uns bitte rechtzeitig.\n\nMit freundlichen Grüßen\n{{company_name}}"
                ),
                $placeholders
            );

            $mailer = $this->createMailer();
            $mailer->addAddress($appointment['owner_email'], trim(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '')));
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $this->wrapInEmailLayout($subject, $bodyText, '📅');
            $mailer->AltBody = $bodyText;

            return $mailer->send();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[MailService::sendReminder] ' . $e->getMessage());
            return false;
        }
    }

    public function sendInvite(string $toEmail, string $inviteUrl, string $note = ''): bool
    {
        try {
            $companyName = $this->settingsRepository->get('company_name', 'Tierphysio Manager');
            $fromName    = $this->settingsRepository->get('mail_from_name', $companyName);

            $placeholders = [
                '{{invite_url}}'    => $inviteUrl,
                '{{note}}'          => $note,
                '{{company_name}}'  => $companyName,
                '{{from_name}}'     => $fromName,
            ];

            $subject = $this->applyPlaceholders(
                $this->settingsRepository->get('email_invite_subject', 'Ihre Einladung zur Anmeldung — {{company_name}}'),
                $placeholders
            );
            $bodyText = $this->applyPlaceholders(
                $this->settingsRepository->get('email_invite_body',
                    "Sie wurden eingeladen!\n\n{{from_name}} lädt Sie ein, Ihr Tier und sich als Besitzer direkt in unserem System zu registrieren.\n\n{{note}}\n\nJetzt registrieren:\n{{invite_url}}\n\nDieser Link ist 7 Tage gültig.\n\nMit freundlichen Grüßen\n{{company_name}}"
                ),
                $placeholders
            );

            $extraHtml = $inviteUrl ? '<div style="text-align:center;margin:28px 0;"><a href="' . htmlspecialchars($inviteUrl) . '" style="display:inline-block;background:linear-gradient(135deg,#4f7cff,#8b5cf6);color:#fff;text-decoration:none;padding:14px 36px;border-radius:100px;font-size:0.95rem;font-weight:700;">Jetzt registrieren →</a></div><p style="font-size:0.78rem;color:rgba(255,255,255,0.35);text-align:center;">Dieser Link ist 7 Tage gültig.</p>' : '';

            $mailer = $this->createMailer();
            $mailer->addAddress($toEmail);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = $this->wrapInEmailLayout($subject, $bodyText, '🐾', $extraHtml);
            $mailer->AltBody = $bodyText;

            return $mailer->send();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[MailService::sendInvite] ' . $e->getMessage());
            return false;
        }
    }

    public function sendRaw(string $to, string $toName, string $subject, string $body, array $attachments = []): bool
    {
        try {
            $mailer = $this->createMailer();
            $mailer->addAddress($to, $toName);
            $mailer->Subject = $subject;
            $mailer->Body    = $body;
            $mailer->isHTML(true);

            foreach ($attachments as $attachment) {
                if (isset($attachment['content'], $attachment['name'])) {
                    $mailer->addStringAttachment(
                        $attachment['content'],
                        $attachment['name'],
                        PHPMailer::ENCODING_BASE64,
                        $attachment['mime'] ?? 'application/octet-stream'
                    );
                }
            }

            return $mailer->send();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('[MailService::sendRaw] ' . $e->getMessage());
            return false;
        }
    }

    /* ══════════════════════════════════════════════════════════
       CENTRAL HTML EMAIL LAYOUT
       All mails share the same visual wrapper. Only the icon,
       title (= subject) and content block differ.
    ══════════════════════════════════════════════════════════ */

    public function wrapInEmailLayout(string $title, string $bodyText, string $icon = '🐾', string $extraHtml = ''): string
    {
        $company    = htmlspecialchars($this->settingsRepository->get('company_name', 'Tierphysio Manager'));
        $titleHtml  = htmlspecialchars($title);
        $contentHtml = nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{$titleHtml}</title>
</head>
<body style="margin:0;padding:0;background:#0f0f1a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f0f1a;padding:32px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:16px;overflow:hidden;">

      <!-- HEADER -->
      <tr><td style="background:linear-gradient(135deg,rgba(79,124,255,0.35),rgba(139,92,246,0.35));padding:32px 40px;text-align:center;">
        <div style="font-size:2.2rem;margin-bottom:10px;">{$icon}</div>
        <h1 style="margin:0;color:#ffffff;font-size:1.35rem;font-weight:700;line-height:1.3;">{$titleHtml}</h1>
        <p style="margin:6px 0 0;color:rgba(255,255,255,0.55);font-size:0.8rem;">{$company}</p>
      </td></tr>

      <!-- BODY -->
      <tr><td style="padding:36px 40px;">
        <div style="color:rgba(255,255,255,0.82);font-size:0.95rem;line-height:1.8;">
          {$contentHtml}
        </div>
        {$extraHtml}
      </td></tr>

      <!-- FOOTER -->
      <tr><td style="padding:16px 40px 24px;text-align:center;border-top:1px solid rgba(255,255,255,0.08);">
        <p style="margin:0;color:rgba(255,255,255,0.25);font-size:0.75rem;">{$company} &middot; Automatisch generierte Nachricht</p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    /* ══════════════════════════════════════════════════════════
       PLACEHOLDER BUILDERS
    ══════════════════════════════════════════════════════════ */

    private function buildInvoicePlaceholders(array $invoice, array $owner): array
    {
        $companyName = $this->settingsRepository->get('company_name', 'Tierphysio Praxis');
        $ownerName   = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));

        $issueDate = '';
        if (!empty($invoice['issue_date'])) {
            try { $issueDate = (new \DateTime($invoice['issue_date']))->format('d.m.Y'); } catch (\Throwable) { $issueDate = $invoice['issue_date']; }
        }
        $dueDate = '';
        if (!empty($invoice['due_date'])) {
            try { $dueDate = (new \DateTime($invoice['due_date']))->format('d.m.Y'); } catch (\Throwable) { $dueDate = $invoice['due_date']; }
        }

        $gross = number_format((float)($invoice['total_gross'] ?? 0), 2, ',', '.') . ' €';

        return [
            '{{invoice_number}}' => $invoice['invoice_number'] ?? '',
            '{{owner_name}}'     => $ownerName,
            '{{owner_first}}'    => $owner['first_name'] ?? '',
            '{{owner_last}}'     => $owner['last_name'] ?? '',
            '{{owner_email}}'    => $owner['email'] ?? '',
            '{{issue_date}}'     => $issueDate,
            '{{due_date}}'       => $dueDate,
            '{{total_gross}}'    => $gross,
            '{{company_name}}'   => $companyName,
        ];
    }

    private function buildReminderPlaceholders(array $a): array
    {
        $company  = $this->settingsRepository->get('company_name', 'Tierphysio Praxis');
        $ownerName = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        $date     = !empty($a['start_at']) ? date('d.m.Y', strtotime($a['start_at'])) : '';
        $timeFrom = !empty($a['start_at']) ? date('H:i', strtotime($a['start_at'])) : '';
        $timeTo   = !empty($a['end_at'])   ? date('H:i', strtotime($a['end_at']))   : '';
        $time     = $timeFrom . ($timeTo ? ' – ' . $timeTo : '') . ' Uhr';
        $patient  = !empty($a['patient_name']) ? '🐾 Patient: ' . $a['patient_name'] : '';

        return [
            '{{owner_name}}'          => $ownerName,
            '{{owner_first}}'         => $a['first_name'] ?? '',
            '{{appointment_title}}'   => $a['title'] ?? '',
            '{{appointment_date}}'    => $date,
            '{{appointment_time}}'    => $time,
            '{{appointment_patient}}' => $patient,
            '{{appointment_note}}'    => $a['description'] ?? '',
            '{{company_name}}'        => $company,
            '{{company_phone}}'       => $this->settingsRepository->get('company_phone', ''),
            '{{company_email}}'       => $this->settingsRepository->get('company_email', ''),
        ];
    }

    private function applyPlaceholders(string $template, array $placeholders): string
    {
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    /* ══════════════════════════════════════════════════════════
       MAILER FACTORY
    ══════════════════════════════════════════════════════════ */

    private function createMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $this->settingsRepository->get('smtp_host', 'localhost');
        $mailer->Port       = (int)$this->settingsRepository->get('smtp_port', '587');
        $mailer->Username   = $this->settingsRepository->get('smtp_username', '');
        $mailer->Password   = $this->settingsRepository->get('smtp_password', '');
        $mailer->SMTPAuth   = !empty($mailer->Username);
        $enc = $this->settingsRepository->get('smtp_encryption', 'tls');
        if ($enc === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'none') {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $fromAddress = $this->settingsRepository->get('mail_from_address', 'noreply@tierphysio.local');
        $fromName    = $this->settingsRepository->get('mail_from_name', 'Tierphysio Manager');
        $mailer->setFrom($fromAddress, $fromName);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        return $mailer;
    }
}
