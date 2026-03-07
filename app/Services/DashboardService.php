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

    public function getUpcomingBirthdays(int $days = 14): array
    {
        $today    = new \DateTimeImmutable('today');
        $upcoming = [];

        // Patients with birth_date
        $patients = $this->db->fetchAll(
            "SELECT p.id, p.name, p.birth_date, p.species, 'patient' AS type,
                    CONCAT(o.first_name, ' ', o.last_name) AS owner_name, o.id AS owner_id
             FROM patients p
             LEFT JOIN owners o ON p.owner_id = o.id
             WHERE p.birth_date IS NOT NULL AND p.status != 'verstorben'"
        );

        foreach ($patients as $row) {
            $bday = $this->nextBirthday($row['birth_date'], $today);
            if ($bday === null) continue;
            $diff = (int)$today->diff($bday)->days;
            if ($diff <= $days) {
                $upcoming[] = [
                    'type'       => 'patient',
                    'id'         => $row['id'],
                    'name'       => $row['name'],
                    'sub'        => $row['species'] ? $row['species'] . ($row['owner_name'] ? ' · ' . $row['owner_name'] : '') : ($row['owner_name'] ?? ''),
                    'link'       => '/patienten/' . $row['id'],
                    'birth_date' => $row['birth_date'],
                    'next_bday'  => $bday->format('Y-m-d'),
                    'diff_days'  => $diff,
                    'age_next'   => (int)$bday->format('Y') - (int)substr($row['birth_date'], 0, 4),
                ];
            }
        }

        // Owners with birth_date
        $owners = $this->db->fetchAll(
            "SELECT id, first_name, last_name, birth_date FROM owners WHERE birth_date IS NOT NULL"
        );

        foreach ($owners as $row) {
            $bday = $this->nextBirthday($row['birth_date'], $today);
            if ($bday === null) continue;
            $diff = (int)$today->diff($bday)->days;
            if ($diff <= $days) {
                $upcoming[] = [
                    'type'       => 'owner',
                    'id'         => $row['id'],
                    'name'       => $row['first_name'] . ' ' . $row['last_name'],
                    'sub'        => 'Tierhalter',
                    'link'       => '/tierhalter/' . $row['id'],
                    'birth_date' => $row['birth_date'],
                    'next_bday'  => $bday->format('Y-m-d'),
                    'diff_days'  => $diff,
                    'age_next'   => (int)$bday->format('Y') - (int)substr($row['birth_date'], 0, 4),
                ];
            }
        }

        usort($upcoming, fn($a, $b) => $a['diff_days'] <=> $b['diff_days']);
        return $upcoming;
    }

    private function nextBirthday(string $birthDate, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        try {
            $bd   = new \DateTimeImmutable($birthDate);
            $thisYear = $today->format('Y');
            $next = new \DateTimeImmutable($thisYear . '-' . $bd->format('m-d'));
            if ($next < $today) {
                $next = $next->modify('+1 year');
            }
            return $next;
        } catch (\Throwable) {
            return null;
        }
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
