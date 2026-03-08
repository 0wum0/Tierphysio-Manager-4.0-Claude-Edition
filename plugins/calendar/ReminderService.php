<?php

declare(strict_types=1);

namespace Plugins\Calendar;

use App\Repositories\SettingsRepository;
use App\Services\MailService;

class ReminderService
{
    public function __construct(
        private readonly AppointmentRepository $appointmentRepository,
        private readonly MailService           $mailService,
        private readonly SettingsRepository    $settingsRepository
    ) {}

    public function processPending(): array
    {
        $appointments = $this->appointmentRepository->findPendingReminders();
        $sent   = 0;
        $failed = 0;

        foreach ($appointments as $a) {
            if (empty($a['owner_email'])) {
                $this->appointmentRepository->markReminderSent((int)$a['id']);
                continue;
            }

            $success = $this->mailService->sendReminder($a);

            $this->appointmentRepository->markReminderSent((int)$a['id']);
            $success ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => count($appointments)];
    }

    private function buildSubject(array $a): string
    {
        $time = date('d.m.Y \u\m H:i \U\h\r', strtotime($a['start_at']));
        return 'Terminerinnerung: ' . htmlspecialchars_decode($a['title']) . ' am ' . $time;
    }

    private function buildBody(array $a): string
    {
        $company  = htmlspecialchars($this->settingsRepository->get('company_name', 'Tierphysio Praxis'));
        $phone    = $this->settingsRepository->get('company_phone', '');
        $email    = $this->settingsRepository->get('company_email', '');
        $name     = htmlspecialchars(trim($a['first_name'] . ' ' . $a['last_name']));
        $title    = htmlspecialchars($a['title']);
        $date     = date('d.m.Y', strtotime($a['start_at']));
        $timeFrom = date('H:i', strtotime($a['start_at']));
        $timeTo   = date('H:i', strtotime($a['end_at']));

        $patientRow = '';
        if (!empty($a['patient_name'])) {
            $p = htmlspecialchars($a['patient_name']);
            $patientRow = '<tr><td style="padding:4px 0;font-size:13px;color:#888;width:110px;">&#x1F43E; Patient</td>'
                . '<td style="padding:4px 0;font-size:14px;color:#1a1a2e;font-weight:600;">' . $p . '</td></tr>';
        }

        $noteRow = '';
        if (!empty($a['description'])) {
            $n = htmlspecialchars($a['description']);
            $noteRow = '<tr><td style="padding:4px 0;font-size:13px;color:#888;">&#x1F4DD; Hinweis</td>'
                . '<td style="padding:4px 0;font-size:14px;color:#555;">' . $n . '</td></tr>';
        }

        $contactBlock = '';
        if ($phone || $email) {
            $ph = $phone ? '<br>&#x1F4DE; ' . htmlspecialchars($phone) : '';
            $em = $email ? '<br>&#x2709;&#xFE0F; ' . htmlspecialchars($email) : '';
            $contactBlock = '<table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eee;margin-top:20px;padding-top:16px;">'
                . '<tr><td style="font-size:13px;color:#888;">' . $company . $ph . $em . '</td></tr></table>';
        }

        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Terminerinnerung</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f6fb;font-family:\'Segoe UI\',Arial,sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 16px;">'
            . '<tr><td align="center">'
            . '<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.08);">'

            . '<tr><td style="background:linear-gradient(135deg,#4f7cff,#7c3aed);padding:28px 32px;text-align:center;">'
            . '<div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">&#x1F4C5; Terminerinnerung</div>'
            . '<div style="font-size:13px;color:rgba(255,255,255,0.8);margin-top:4px;">' . $company . '</div>'
            . '</td></tr>'

            . '<tr><td style="padding:32px;">'
            . '<p style="margin:0 0 20px;font-size:16px;color:#1a1a2e;">Hallo <strong>' . $name . '</strong>,</p>'
            . '<p style="margin:0 0 24px;font-size:14px;color:#555;line-height:1.6;">wir m&ouml;chten Sie an Ihren bevorstehenden Termin erinnern:</p>'

            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9ff;border:2px solid #e8ecff;border-radius:10px;margin-bottom:24px;">'
            . '<tr><td style="padding:20px 24px;">'
            . '<div style="font-size:18px;font-weight:700;color:#4f7cff;margin-bottom:12px;">' . $title . '</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="padding:4px 0;font-size:13px;color:#888;width:110px;">&#x1F4C5; Datum</td>'
            . '<td style="padding:4px 0;font-size:14px;color:#1a1a2e;font-weight:600;">' . $date . '</td></tr>'
            . '<tr><td style="padding:4px 0;font-size:13px;color:#888;">&#x23F0; Uhrzeit</td>'
            . '<td style="padding:4px 0;font-size:14px;color:#1a1a2e;font-weight:600;">' . $timeFrom . ' &ndash; ' . $timeTo . ' Uhr</td></tr>'
            . $patientRow
            . $noteRow
            . '</table></td></tr></table>'

            . '<p style="font-size:13px;color:#888;margin:0;">Falls Sie den Termin absagen oder verschieben m&ouml;chten, kontaktieren Sie uns bitte rechtzeitig.</p>'
            . $contactBlock
            . '</td></tr>'

            . '<tr><td style="background:#f8f9ff;padding:16px 32px;text-align:center;border-top:1px solid #eee;">'
            . '<span style="font-size:11px;color:#bbb;">Diese E-Mail wurde automatisch von ' . $company . ' gesendet.</span>'
            . '</td></tr>'

            . '</table></td></tr></table></body></html>';
    }
}
