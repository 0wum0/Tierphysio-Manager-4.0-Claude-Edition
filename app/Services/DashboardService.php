<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\InvoiceRepository;
use App\Repositories\PatientRepository;
use App\Repositories\OwnerRepository;

class DashboardService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PatientRepository $patientRepository,
        private readonly OwnerRepository $ownerRepository,
        private readonly Database $db
    ) {}

    public function getStats(): array
    {
        $invoiceStats = $this->invoiceRepository->getStats();
        $newPatients  = $this->patientRepository->countNew('30 days ago');
        $totalPatients = $this->patientRepository->count();
        $totalOwners   = $this->ownerRepository->count();

        return array_merge($invoiceStats, [
            'new_patients'    => $newPatients,
            'total_patients'  => $totalPatients,
            'total_owners'    => $totalOwners,
        ]);
    }

    public function getChartData(string $type): array
    {
        return $this->invoiceRepository->getChartData($type);
    }

    public function saveLayout(int $userId, array $layout): void
    {
        $this->db->execute(
            "INSERT INTO user_preferences (user_id, pref_key, pref_value)
             VALUES (?, 'dashboard_layout', ?)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = NOW()",
            [$userId, json_encode($layout, JSON_UNESCAPED_UNICODE)]
        );
    }

    public function loadLayout(int $userId): ?array
    {
        $row = $this->db->fetchColumn(
            "SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = 'dashboard_layout'",
            [$userId]
        );
        if (!$row) return null;
        $decoded = json_decode((string)$row, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function deleteLayout(int $userId): void
    {
        $this->db->execute(
            "DELETE FROM user_preferences WHERE user_id = ? AND pref_key = 'dashboard_layout'",
            [$userId]
        );
    }
}
