<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvoiceRepository;
use App\Repositories\SettingsRepository;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly SettingsRepository $settingsRepository
    ) {}

    public function findById(int $id): array|false
    {
        return $this->invoiceRepository->findById($id);
    }

    public function getPaginated(int $page, int $perPage, string $status = '', string $search = ''): array
    {
        return $this->invoiceRepository->getPaginated($page, $perPage, $status, $search);
    }

    public function getStats(): array
    {
        return $this->invoiceRepository->getStats();
    }

    public function getChartData(string $type): array
    {
        return $this->invoiceRepository->getChartData($type);
    }

    public function getMonthlyChartData(): array
    {
        return $this->invoiceRepository->getMonthlyChartData();
    }

    public function getPositions(int $invoiceId): array
    {
        return $this->invoiceRepository->getPositions($invoiceId);
    }

    public function generateInvoiceNumber(): string
    {
        $prefix      = $this->settingsRepository->get('invoice_prefix', 'RE');
        $startNumber = (int)$this->settingsRepository->get('invoice_start_number', '1000');
        return $this->invoiceRepository->getNextInvoiceNumber($prefix, $startNumber);
    }

    public function create(array $data, array $positions): string
    {
        $totals = $this->calculateTotals($positions);

        $data['total_net']   = $totals['net'];
        $data['total_tax']   = $totals['tax'];
        $data['total_gross'] = $totals['gross'];
        $data['created_at']  = date('Y-m-d H:i:s');
        $data['updated_at']  = date('Y-m-d H:i:s');

        $id = $this->invoiceRepository->create($data);

        foreach ($positions as $i => $pos) {
            $this->invoiceRepository->addPosition((int)$id, $pos, $i + 1);
        }

        return $id;
    }

    public function update(int $id, array $data, array $positions): void
    {
        $totals = $this->calculateTotals($positions);

        $data['total_net']   = $totals['net'];
        $data['total_tax']   = $totals['tax'];
        $data['total_gross'] = $totals['gross'];
        $data['updated_at']  = date('Y-m-d H:i:s');

        $this->invoiceRepository->update($id, $data);
        $this->invoiceRepository->deletePositions($id);

        foreach ($positions as $i => $pos) {
            $this->invoiceRepository->addPosition($id, $pos, $i + 1);
        }
    }

    public function delete(int $id): void
    {
        $this->invoiceRepository->deletePositions($id);
        $this->invoiceRepository->delete($id);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->invoiceRepository->update($id, ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function markEmailSent(int $id): void
    {
        $this->invoiceRepository->markEmailSent($id);
    }

    private function calculateTotals(array $positions): array
    {
        $net = 0.0;
        $tax = 0.0;

        foreach ($positions as $pos) {
            $lineNet  = (float)$pos['quantity'] * (float)$pos['unit_price'];
            $lineTax  = $lineNet * ((float)$pos['tax_rate'] / 100);
            $net     += $lineNet;
            $tax     += $lineTax;
        }

        return [
            'net'   => round($net, 2),
            'tax'   => round($tax, 2),
            'gross' => round($net + $tax, 2),
        ];
    }
}
