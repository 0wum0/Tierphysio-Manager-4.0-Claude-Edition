<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

class InvoiceRepository extends Repository
{
    protected string $table = 'invoices';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * Automatically adds payment_method + paid_at columns if they don't exist yet.
     * Safe to call multiple times — uses IF NOT EXISTS.
     */
    public function ensurePaymentMethodColumns(): void
    {
        /* Add payment_method column if missing */
        try {
            $this->db->execute(
                "ALTER TABLE `invoices`
                    ADD COLUMN IF NOT EXISTS `payment_method` ENUM('rechnung','bar') NOT NULL DEFAULT 'rechnung',
                    ADD COLUMN IF NOT EXISTS `paid_at` DATETIME NULL"
            );
        } catch (\Throwable) {
            /* Column already exists or unsupported — safe to ignore */
        }

        /* Add index only if it doesn't exist yet (MySQL-compatible check) */
        try {
            $exists = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name = 'invoices'
                   AND index_name = 'idx_payment_method'"
            );
            if ((int)$exists === 0) {
                $this->db->execute(
                    "ALTER TABLE `invoices` ADD INDEX `idx_payment_method` (`payment_method`)"
                );
            }
        } catch (\Throwable) {
            /* Index creation failed — non-critical, ignore */
        }
    }

    public function getInvoiceStatsByPatientId(int $patientId): array
    {
        /* Find owner_id for this patient */
        $ownerId = (int)$this->db->fetchColumn(
            "SELECT owner_id FROM patients WHERE id = ?",
            [$patientId]
        );

        /* Match by patient_id directly OR by owner_id (invoices linked only to owner) */
        $openCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices
             WHERE status IN ('open','overdue','draft')
               AND (patient_id = ? OR (patient_id IS NULL AND owner_id = ?))",
            [$patientId, $ownerId]
        );
        $paidCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices
             WHERE status = 'paid'
               AND (patient_id = ? OR (patient_id IS NULL AND owner_id = ?))",
            [$patientId, $ownerId]
        );
        return [
            'open_count' => $openCount,
            'paid_count' => $paidCount,
        ];
    }

    public function getPaginated(int $page, int $perPage, string $status = '', string $search = ''): array
    {
        $conditions = [];
        $params     = [];

        if (!empty($status)) {
            $conditions[] = "i.status = ?";
            $params[]     = $status;
        }

        if (!empty($search)) {
            $conditions[] = "(i.invoice_number LIKE ? OR CONCAT(o.first_name, ' ', o.last_name) LIKE ? OR p.name LIKE ?)";
            $params = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        $where  = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $offset = ($page - 1) * $perPage;

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices i
             LEFT JOIN owners o ON i.owner_id = o.id
             LEFT JOIN patients p ON i.patient_id = p.id
             {$where}",
            $params
        );

        $items = $this->db->fetchAll(
            "SELECT i.*,
                    CONCAT(o.first_name, ' ', o.last_name) AS owner_name,
                    p.name AS patient_name
             FROM invoices i
             LEFT JOIN owners o ON i.owner_id = o.id
             LEFT JOIN patients p ON i.patient_id = p.id
             {$where}
             ORDER BY i.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int)ceil($total / $perPage),
            'has_next'  => ($page * $perPage) < $total,
            'has_prev'  => $page > 1,
        ];
    }

    public function getPositions(int $invoiceId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM invoice_positions WHERE invoice_id = ? ORDER BY sort_order ASC",
            [$invoiceId]
        );
    }

    public function deletePositions(int $invoiceId): void
    {
        $this->db->execute("DELETE FROM invoice_positions WHERE invoice_id = ?", [$invoiceId]);
    }

    public function addPosition(int $invoiceId, array $pos, int $sortOrder): void
    {
        $this->db->execute(
            "INSERT INTO invoice_positions (invoice_id, description, quantity, unit_price, tax_rate, total, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $invoiceId,
                $pos['description'],
                $pos['quantity'],
                $pos['unit_price'],
                $pos['tax_rate'],
                $pos['total'],
                $sortOrder,
            ]
        );
    }

    public function getStats(): array
    {
        $now   = date('Y-m-d');
        $week  = date('Y-m-d', strtotime('-7 days'));
        $month = date('Y-m-01');
        $year  = date('Y-01-01');
        $prevMonth = date('Y-m-d', strtotime('-1 month'));
        $prevYear  = date('Y-01-01', strtotime('-1 year'));

        $revenueWeek = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND issue_date >= ?",
            [$week]
        );

        $revenueMonth = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND issue_date >= ?",
            [$month]
        );

        $revenueYear = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND issue_date >= ?",
            [$year]
        );

        $revenueTotal = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid'"
        );

        $prevMonthRevenue = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND issue_date >= ? AND issue_date < ?",
            [$prevMonth, $month]
        );

        $prevYearRevenue = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND issue_date >= ? AND issue_date < ?",
            [$prevYear, $year]
        );

        $openCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices WHERE status = 'open'"
        );

        $overdueCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices WHERE status = 'overdue' OR (status = 'open' AND due_date < ?)",
            [$now]
        );

        $openAmount = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'open'"
        );

        $overdueAmount = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'overdue' OR (status = 'open' AND due_date < ?)",
            [$now]
        );

        $draftCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices WHERE status = 'draft'"
        );

        $paidCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM invoices WHERE status = 'paid'"
        );

        /* migration-006: split paid by payment_method */
        $paidInvoiceAmount = 0.0;
        $paidInvoiceCount  = 0;
        $cashAmount        = 0.0;
        $cashCount         = 0;
        try {
            $paidInvoiceAmount = (float)$this->db->fetchColumn(
                "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND payment_method = 'rechnung'"
            );
            $paidInvoiceCount = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM invoices WHERE status = 'paid' AND payment_method = 'rechnung'"
            );
            $cashAmount = (float)$this->db->fetchColumn(
                "SELECT COALESCE(SUM(total_gross), 0) FROM invoices WHERE status = 'paid' AND payment_method = 'bar'"
            );
            $cashCount = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM invoices WHERE status = 'paid' AND payment_method = 'bar'"
            );
        } catch (\Throwable) {
            /* migration 006 not yet run — fall back to totals */
            $paidInvoiceAmount = $revenueTotal;
            $paidInvoiceCount  = $paidCount;
        }

        return [
            'revenue_week'         => $revenueWeek,
            'revenue_month'        => $revenueMonth,
            'revenue_year'         => $revenueYear,
            'revenue_total'        => $revenueTotal,
            'prev_month_revenue'   => $prevMonthRevenue,
            'prev_year_revenue'    => $prevYearRevenue,
            'open_count'           => $openCount,
            'overdue_count'        => $overdueCount,
            'open_amount'          => $openAmount,
            'overdue_amount'       => $overdueAmount,
            'draft_count'          => $draftCount,
            'paid_count'           => $paidCount,
            'paid_invoice_amount'  => $paidInvoiceAmount,
            'paid_invoice_count'   => $paidInvoiceCount,
            'cash_amount'          => $cashAmount,
            'cash_count'           => $cashCount,
            'month_change'         => $prevMonthRevenue > 0
                ? round((($revenueMonth - $prevMonthRevenue) / $prevMonthRevenue) * 100, 1)
                : 0,
            'year_change'          => $prevYearRevenue > 0
                ? round((($revenueYear - $prevYearRevenue) / $prevYearRevenue) * 100, 1)
                : 0,
        ];
    }

    public function getChartData(string $type): array
    {
        if ($type === 'monthly') {
            $rows = $this->db->fetchAll(
                "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS period,
                        COALESCE(SUM(total_gross), 0) AS revenue
                 FROM invoices
                 WHERE status = 'paid' AND issue_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY period
                 ORDER BY period ASC"
            );
        } else {
            $rows = $this->db->fetchAll(
                "SELECT DATE_FORMAT(issue_date, '%Y-%u') AS period,
                        COALESCE(SUM(total_gross), 0) AS revenue
                 FROM invoices
                 WHERE status = 'paid' AND issue_date >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
                 GROUP BY period
                 ORDER BY period ASC"
            );
        }

        return [
            'labels' => array_column($rows, 'period'),
            'data'   => array_map('floatval', array_column($rows, 'revenue')),
        ];
    }

    public function getMonthlyChartData(): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("-{$i} months"));
        }

        $rows = $this->db->fetchAll(
            "SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_gross ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN status IN ('open','overdue') THEN total_gross ELSE 0 END), 0) AS open
             FROM invoices
             WHERE issue_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY month
             ORDER BY month ASC"
        );

        $indexed = [];
        foreach ($rows as $r) {
            $indexed[$r['month']] = $r;
        }

        $labels = [];
        $paid   = [];
        $open   = [];

        foreach ($months as $m) {
            $de = \DateTime::createFromFormat('Y-m', $m);
            $labels[] = $de ? $de->format('M y') : $m;
            $paid[]   = round((float)($indexed[$m]['paid'] ?? 0), 2);
            $open[]   = round((float)($indexed[$m]['open'] ?? 0), 2);
        }

        return ['labels' => $labels, 'paid' => $paid, 'open' => $open];
    }

    public function getNextInvoiceNumber(string $prefix = 'RE', int $startNumber = 1000): string
    {
        $lastNumber = $this->db->fetchColumn(
            "SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1"
        );

        if (!$lastNumber) {
            return $prefix . '-' . str_pad((string)$startNumber, 4, '0', STR_PAD_LEFT);
        }

        preg_match('/(\d+)$/', (string)$lastNumber, $matches);
        $next = isset($matches[1]) ? (int)$matches[1] + 1 : $startNumber;
        return $prefix . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    public function updateTotals(int $id, float $totalNet, float $totalTax, float $totalGross): void
    {
        $this->db->execute(
            "UPDATE invoices SET total_net = ?, total_tax = ?, total_gross = ? WHERE id = ?",
            [$totalNet, $totalTax, $totalGross, $id]
        );
    }

    public function markEmailSent(int $id): void
    {
        $this->db->execute(
            "UPDATE invoices SET email_sent_at = NOW() WHERE id = ?",
            [$id]
        );
    }
}
