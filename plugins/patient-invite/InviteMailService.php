<?php

declare(strict_types=1);

namespace Plugins\PatientInvite;

use App\Repositories\SettingsRepository;
use App\Services\MailService;

class InviteMailService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly MailService $mailService
    ) {}

    public function sendInviteEmail(string $toEmail, string $inviteUrl, string $note = ''): bool
    {
        return $this->mailService->sendInvite($toEmail, $inviteUrl, $note);
    }

    public function buildWhatsAppUrl(string $phone, string $inviteUrl, string $appName): string
    {
        $phone   = preg_replace('/[^0-9+]/', '', $phone);
        $message = "Hallo! Sie wurden eingeladen, sich bei {$appName} anzumelden.\n\nKlicken Sie hier, um Ihr Tier und sich selbst direkt zu registrieren:\n{$inviteUrl}\n\nDer Link ist 7 Tage gültig.";
        return 'https://wa.me/' . ltrim($phone, '+') . '?text=' . rawurlencode($message);
    }

}
