<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository
    ) {}

    public function sendInvoice(array $invoice, array $owner, string $pdfContent): bool
    {
        try {
            $mailer = $this->createMailer();
            $mailer->addAddress($owner['email'], $owner['first_name'] . ' ' . $owner['last_name']);
            $mailer->Subject = 'Ihre Rechnung ' . $invoice['invoice_number'];

            $companyName = $this->settingsRepository->get('company_name', 'Tierphysio Praxis');
            $mailer->Body = "Sehr geehrte/r " . $owner['first_name'] . " " . $owner['last_name'] . ",\n\n"
                . "anbei erhalten Sie Ihre Rechnung " . $invoice['invoice_number'] . ".\n\n"
                . "Mit freundlichen Grüßen\n" . $companyName;

            $mailer->addStringAttachment(
                $pdfContent,
                'Rechnung-' . $invoice['invoice_number'] . '.pdf',
                PHPMailer::ENCODING_BASE64,
                'application/pdf'
            );

            return $mailer->send();
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            return false;
        }
    }

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
        $mailer->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $fromAddress = $this->settingsRepository->get('mail_from_address', 'noreply@tierphysio.local');
        $fromName    = $this->settingsRepository->get('mail_from_name', 'Tierphysio Manager');
        $mailer->setFrom($fromAddress, $fromName);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        return $mailer;
    }
}
