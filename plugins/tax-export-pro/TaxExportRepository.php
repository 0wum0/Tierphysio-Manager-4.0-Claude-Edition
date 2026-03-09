<?php

declare(strict_types=1);

namespace Plugins\TaxExportPro;

use App\Core\Database;

class TaxExportRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * Load invoices for a given period with optional status filter.
     * Joins owners + patients for full context — read-only, no mutation.
     */
    public function findForPeriod(
        string $dateFrom,
        string $dateTo,
        string $statusFilter = ''
    ): array {
        $conditions = ["i.issue_date >= ?", "i.issue_date <= ?"];
        $params     = [$dateFrom, $dateTo];

        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $conditions[] = "i.status = ?";
            $params[]     = $statusFilter;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        return $this->db->fetchAll(
            "SELECT
                i.id,
                i.invoice_number,
                i.issue_date,
                i.due_date,
                i.status,
                i.total_net,
                i.total_tax,
                i.total_gross,
                i.notes,
                i.payment_terms,
                i.created_at,
                i.updated_at,
                i.owner_id,
                i.patient_id,
                COALESCE(i.payment_method, 'rechnung') AS payment_method,
                i.paid_at,
                i.email_sent_at,
                CONCAT(o.first_name, ' ', o.last_name) AS owner_name,
                o.email  AS owner_email,
                o.street AS owner_street,
                o.zip    AS owner_zip,
                o.city   AS owner_city,
                p.name   AS patient_name,
                p.species AS patient_species
             FROM invoices i
             LEFT JOIN owners o  ON i.owner_id  = o.id
             LEFT JOIN patients p ON i.patient_id = p.id
             {$where}
             ORDER BY i.issue_date ASC, i.id ASC",
            $params
        );
    }

    /**
     * Aggregate stats for a period.
     */
    public function getStatsForPeriod(string $dateFrom, string $dateTo): array
    {
        $base = "FROM invoices WHERE issue_date >= ? AND issue_date <= ?";
        $p    = [$dateFrom, $dateTo];

        $total     = (int)$this->db->fetchColumn("SELECT COUNT(*) {$base}", $p);
        $paid      = (int)$this->db->fetchColumn("SELECT COUNT(*) {$base} AND status = 'paid'", $p);
        $open      = (int)$this->db->fetchColumn("SELECT COUNT(*) {$base} AND status = 'open'", $p);
        $draft     = (int)$this->db->fetchColumn("SELECT COUNT(*) {$base} AND status = 'draft'", $p);
        $overdue   = (int)$this->db->fetchColumn("SELECT COUNT(*) {$base} AND status = 'overdue'", $p);
        $cancelled = (int)$this->db->fetchColumn("SELECT COUNT(*) {$base} AND status = 'cancelled'", $p);

        $sumPaid   = (float)$this->db->fetchColumn("SELECT COALESCE(SUM(total_gross),0) {$base} AND status = 'paid'", $p);
        $sumOpen   = (float)$this->db->fetchColumn("SELECT COALESCE(SUM(total_gross),0) {$base} AND status IN ('open','overdue')", $p);
        $sumAll    = (float)$this->db->fetchColumn("SELECT COALESCE(SUM(total_gross),0) {$base}", $p);
        $sumNet    = (float)$this->db->fetchColumn("SELECT COALESCE(SUM(total_net),0)   {$base} AND status = 'paid'", $p);
        $sumTax    = (float)$this->db->fetchColumn("SELECT COALESCE(SUM(total_tax),0)   {$base} AND status = 'paid'", $p);

        return compact(
            'total', 'paid', 'open', 'draft', 'overdue', 'cancelled',
            'sumPaid', 'sumOpen', 'sumAll', 'sumNet', 'sumTax'
        );
    }

    /**
     * Load positions for a specific invoice.
     */
    public function getPositions(int $invoiceId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM invoice_positions WHERE invoice_id = ? ORDER BY sort_order ASC",
            [$invoiceId]
        );
    }

    /**
     * Read a single tax_export_settings value.
     */
    public function getSetting(string $key, string $default = ''): string
    {
        try {
            $val = $this->db->fetchColumn(
                "SELECT setting_value FROM tax_export_settings WHERE setting_key = ? LIMIT 1",
                [$key]
            );
            return ($val !== false && $val !== null) ? (string)$val : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Write a tax_export_settings value.
     */
    public function setSetting(string $key, string $value): void
    {
        try {
            $exists = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM tax_export_settings WHERE setting_key = ?",
                [$key]
            );
            if ($exists) {
                $this->db->execute(
                    "UPDATE tax_export_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?",
                    [$value, $key]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO tax_export_settings (setting_key, setting_value, created_at, updated_at)
                     VALUES (?, ?, NOW(), NOW())",
                    [$key, $value]
                );
            }
        } catch (\Throwable) {}
    }

    /**
     * Log an export run.
     */
    public function logExport(string $type, string $dateFrom, string $dateTo, string $statusFilter, int $rowCount): void
    {
        try {
            $this->db->execute(
                "INSERT INTO tax_export_logs (export_type, date_from, date_to, status_filter, row_count, exported_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$type, $dateFrom, $dateTo, $statusFilter, $rowCount]
            );
        } catch (\Throwable) {}
    }

    /**
     * Get recent export log entries.
     */
    public function getRecentLogs(int $limit = 20): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT * FROM tax_export_logs ORDER BY exported_at DESC LIMIT ?",
                [$limit]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get all settings as key => value map.
     */
    public function getAllSettings(): array
    {
        try {
            $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM tax_export_settings");
            $map  = [];
            foreach ($rows as $row) {
                $map[$row['setting_key']] = $row['setting_value'];
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }
}
