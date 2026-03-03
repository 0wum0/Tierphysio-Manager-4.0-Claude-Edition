<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\PatientRepository;
use App\Repositories\OwnerRepository;

class DashboardService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PatientRepository $patientRepository,
        private readonly OwnerRepository $ownerRepository
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
}
