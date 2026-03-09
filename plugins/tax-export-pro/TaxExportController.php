<?php

declare(strict_types=1);

namespace Plugins\TaxExportPro;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Core\Application;
use App\Core\Database;
use App\Repositories\SettingsRepository;

class TaxExportController extends Controller
{
    private TaxExportService    $taxService;
    private TaxExportRepository $taxRepo;

    public function __construct(
        View       $view,
        Session    $session,
        Config     $config,
        Translator $translator
    ) {
        parent::__construct($view, $session, $config, $translator);
        $db               = Application::getInstance()->getContainer()->get(Database::class);
        $settingsRepo     = Application::getInstance()->getContainer()->get(SettingsRepository::class);
        $this->taxService = new TaxExportService($db, $settingsRepo);
        $this->taxRepo    = new TaxExportRepository($db);
    }

    /**
     * GET /steuerexport — main page
     */
    public function index(array $params = []): void
    {
        $year    = (int)$this->get('year',   (string)date('Y'));
        $month   = $this->get('month',  '');
        $dateFrom = $this->get('date_from', '');
        $dateTo   = $this->get('date_to',   '');
        $status   = $this->get('status', '');
        $previewPage = (int)$this->get('preview_page', 1);

        // Determine date range from year/month or explicit range
        [$dateFrom, $dateTo] = $this->resolveDateRange($year, $month, $dateFrom, $dateTo);

        $stats    = $this->taxService->getStats($dateFrom, $dateTo);
        $invoices = $this->taxService->getInvoices($dateFrom, $dateTo, $status);
        $logs     = $this->taxService->getRecentLogs(10);
        $settings = $this->taxService->getAllSettings();

        // Paginate preview table (25 per page)
        $perPage      = 25;
        $totalItems   = count($invoices);
        $totalPages   = max(1, (int)ceil($totalItems / $perPage));
        $previewPage  = max(1, min($previewPage, $totalPages));
        $previewSlice = array_slice($invoices, ($previewPage - 1) * $perPage, $perPage);

        // Available years (from invoices table)
        $years = $this->getAvailableYears();

        $this->render('@tax-export-pro/index.twig', [
            'page_title'    => 'Steuerexport',
            'year'          => $year,
            'month'         => $month,
            'date_from'     => $dateFrom,
            'date_to'       => $dateTo,
            'status_filter' => $status,
            'stats'         => $stats,
            'invoices'      => $previewSlice,
            'total_items'   => $totalItems,
            'preview_page'  => $previewPage,
            'total_pages'   => $totalPages,
            'per_page'      => $perPage,
            'logs'          => $logs,
            'settings'      => $settings,
            'years'         => $years,
            'query_string'  => $this->buildQueryString($year, $month, $dateFrom, $dateTo, $status),
        ]);
    }

    /**
     * GET /steuerexport/export-csv — download einnahmen.csv + rechnungsjournal.csv as ZIP
     */
    public function exportCsv(array $params = []): void
    {
        [$dateFrom, $dateTo, $status, $delimiter] = $this->resolveExportParams();
        $invoices  = $this->taxService->getInvoices($dateFrom, $dateTo, $status);

        $this->taxService->logExport('csv', $dateFrom, $dateTo, $status, count($invoices));

        // We send two CSVs in a small ZIP for convenience
        if (!class_exists('\ZipArchive')) {
            // Fallback: send only einnahmen.csv
            $csv = $this->taxService->buildEinnahmenCsv($invoices, $delimiter);
            $this->sendDownload(
                $csv,
                'text/csv; charset=UTF-8',
                'einnahmen-' . $dateFrom . '_' . $dateTo . '.csv'
            );
            return;
        }

        $tmpPath = sys_get_temp_dir() . '/tax-csv-' . date('YmdHis') . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('einnahmen.csv', $this->taxService->buildEinnahmenCsv($invoices, $delimiter));
        $zip->addFromString('rechnungsjournal.csv', $this->taxService->buildRechnungsjournalCsv($invoices, $delimiter));
        $zip->close();

        $this->sendFile(
            $tmpPath,
            'application/zip',
            'csv-export-' . $dateFrom . '_' . $dateTo . '.zip',
            true
        );
    }

    /**
     * GET /steuerexport/export-zip — full ZIP with PDFs + CSVs + report
     */
    public function exportZip(array $params = []): void
    {
        [$dateFrom, $dateTo, $status, $delimiter] = $this->resolveExportParams();
        $invoices = $this->taxService->getInvoices($dateFrom, $dateTo, $status);
        $stats    = $this->taxService->getStats($dateFrom, $dateTo);

        $this->taxService->logExport('zip', $dateFrom, $dateTo, $status, count($invoices));

        try {
            $zipPath = $this->taxService->buildZip($invoices, $stats, $dateFrom, $dateTo, $status, $delimiter);
            $this->sendFile(
                $zipPath,
                'application/zip',
                'steuerexport-' . $dateFrom . '_' . $dateTo . '.zip',
                true
            );
        } catch (\Throwable $e) {
            $this->session->flash('error', 'ZIP-Export fehlgeschlagen: ' . $e->getMessage());
            $this->redirect('/steuerexport');
        }
    }

    /**
     * GET /steuerexport/export-pdf — download PDF tax report
     */
    public function exportPdf(array $params = []): void
    {
        [$dateFrom, $dateTo, $status] = $this->resolveExportParams();
        $invoices = $this->taxService->getInvoices($dateFrom, $dateTo, $status);
        $stats    = $this->taxService->getStats($dateFrom, $dateTo);

        $this->taxService->logExport('pdf', $dateFrom, $dateTo, $status, count($invoices));

        $pdf = $this->taxService->buildPdfReport($invoices, $stats, $dateFrom, $dateTo, $status);

        $this->sendDownload(
            $pdf,
            'application/pdf',
            'steuerbericht-' . $dateFrom . '_' . $dateTo . '.pdf'
        );
    }

    /**
     * POST /steuerexport/settings — save plugin settings
     */
    public function saveSettings(array $params = []): void
    {
        $this->validateCsrf();

        $allowed = [
            'csv_delimiter',
            'filename_schema',
            'company_tax_info',
            'consider_paid_at',
        ];

        foreach ($allowed as $key) {
            $value = $this->post($key, '');
            $this->taxService->saveSetting($key, $this->sanitize($value));
        }

        $this->session->flash('success', 'Steuerexport-Einstellungen gespeichert.');
        $this->redirect('/steuerexport');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveDateRange(
        int    $year,
        string $month,
        string $dateFrom,
        string $dateTo
    ): array {
        // Explicit range takes priority
        if ($dateFrom && $dateTo) {
            return [$dateFrom, $dateTo];
        }
        // Month selected
        if ($month !== '' && ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12) {
            $m    = str_pad($month, 2, '0', STR_PAD_LEFT);
            $last = cal_days_in_month(CAL_GREGORIAN, (int)$month, $year);
            return ["{$year}-{$m}-01", "{$year}-{$m}-{$last}"];
        }
        // Full year
        return ["{$year}-01-01", "{$year}-12-31"];
    }

    private function resolveExportParams(): array
    {
        $year     = (int)$this->get('year',      (string)date('Y'));
        $month    = $this->get('month',      '');
        $dateFrom = $this->get('date_from',  '');
        $dateTo   = $this->get('date_to',    '');
        $status   = $this->get('status',     '');

        [$dateFrom, $dateTo] = $this->resolveDateRange($year, $month, $dateFrom, $dateTo);

        $delimiter = $this->taxRepo->getSetting('csv_delimiter', ';');
        if (!in_array($delimiter, [';', ',', "\t"], true)) {
            $delimiter = ';';
        }

        return [$dateFrom, $dateTo, $status, $delimiter];
    }

    private function buildQueryString(int $year, string $month, string $dateFrom, string $dateTo, string $status): string
    {
        $parts = ['year=' . $year];
        if ($month !== '')  $parts[] = 'month=' . urlencode($month);
        if ($dateFrom !== '') $parts[] = 'date_from=' . urlencode($dateFrom);
        if ($dateTo !== '')   $parts[] = 'date_to=' . urlencode($dateTo);
        if ($status !== '')   $parts[] = 'status=' . urlencode($status);
        return implode('&', $parts);
    }

    private function getAvailableYears(): array
    {
        try {
            $db   = Application::getInstance()->getContainer()->get(Database::class);
            $rows = $db->fetchAll(
                "SELECT DISTINCT YEAR(issue_date) AS y FROM invoices WHERE issue_date IS NOT NULL ORDER BY y DESC"
            );
            return array_column($rows, 'y');
        } catch (\Throwable) {
            return [(int)date('Y')];
        }
    }

    private function sendDownload(string $content, string $contentType, string $filename): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $content;
        exit;
    }

    private function sendFile(string $path, string $contentType, string $filename, bool $deleteAfter = false): void
    {
        if (!file_exists($path)) {
            $this->session->flash('error', 'Export-Datei konnte nicht gefunden werden.');
            $this->redirect('/steuerexport');
            return;
        }
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        readfile($path);
        if ($deleteAfter) {
            @unlink($path);
        }
        exit;
    }
}
