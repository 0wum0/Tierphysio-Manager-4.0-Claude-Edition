<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Config;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Services\InvoiceService;
use App\Services\PatientService;
use App\Services\OwnerService;
use App\Services\PdfService;
use App\Services\MailService;
use App\Repositories\TreatmentTypeRepository;
use App\Repositories\SettingsRepository;

class InvoiceController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Config $config,
        Translator $translator,
        private readonly InvoiceService $invoiceService,
        private readonly PatientService $patientService,
        private readonly OwnerService $ownerService,
        private readonly PdfService $pdfService,
        private readonly MailService $mailService,
        private readonly TreatmentTypeRepository $treatmentTypeRepository,
        private readonly SettingsRepository $settingsRepository
    ) {
        parent::__construct($view, $session, $config, $translator);
    }

    public function index(array $params = []): void
    {
        $status = $this->get('status', '');
        $search = $this->get('search', '');
        $page   = (int)$this->get('page', 1);
        $result     = $this->invoiceService->getPaginated($page, 15, $status, $search);
        $stats      = $this->invoiceService->getStats();
        $chartData  = $this->invoiceService->getMonthlyChartData();

        $this->render('invoices/index.twig', [
            'page_title' => $this->translator->trans('nav.invoices'),
            'invoices'   => $result['items'],
            'pagination' => $result,
            'stats'      => $stats,
            'chart_data' => $chartData,
            'status'     => $status,
            'search'     => $search,
        ]);
    }

    public function create(array $params = []): void
    {
        $patients = $this->patientService->findAll();
        $owners   = $this->ownerService->findAll();

        $preselected_patient = $this->get('patient_id');
        $preselected_owner   = $this->get('owner_id');

        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}

        $settings = $this->settingsRepository->all();
        $this->render('invoices/create.twig', [
            'page_title'          => $this->translator->trans('invoices.create'),
            'patients'            => $patients,
            'owners'              => $owners,
            'preselected_patient' => $preselected_patient,
            'preselected_owner'   => $preselected_owner,
            'next_number'         => $this->invoiceService->generateInvoiceNumber(),
            'treatment_types'     => $treatmentTypes,
            'kleinunternehmer'    => ($settings['kleinunternehmer'] ?? '0') === '1',
            'default_tax_rate'    => $settings['default_tax_rate'] ?? '19',
        ]);
    }

    public function store(array $params = []): void
    {
        $this->validateCsrf();

        $paymentMethod = $this->sanitize($this->post('payment_method', 'rechnung'));
        if (!in_array($paymentMethod, ['rechnung', 'bar'], true)) {
            $paymentMethod = 'rechnung';
        }

        $isCash = ($paymentMethod === 'bar');

        $data = [
            'invoice_number' => $this->sanitize($this->post('invoice_number', '')),
            'patient_id'     => (int)$this->post('patient_id', 0) ?: null,
            'owner_id'       => (int)$this->post('owner_id', 0),
            'status'         => $isCash ? 'paid' : $this->sanitize($this->post('status', 'draft')),
            'issue_date'     => $this->post('issue_date') ?: date('Y-m-d'),
            'due_date'       => $isCash ? null : ($this->post('due_date', null) ?: null),
            'notes'          => $this->post('notes', ''),
            'payment_terms'  => $this->post('payment_terms', ''),
            'payment_method' => $paymentMethod,
            'paid_at'        => $isCash ? date('Y-m-d H:i:s') : null,
        ];

        $positions = $this->parsePositions();

        if (empty($data['owner_id']) || empty($positions)) {
            $this->session->flash('error', $this->translator->trans('invoices.fill_required'));
            $this->redirect('/rechnungen/erstellen');
            return;
        }

        $id = $this->invoiceService->create($data, $positions);

        /* ── Automatischer Timeline-Eintrag bei Barzahlung ── */
        if ($isCash && !empty($data['patient_id'])) {
            $paidAtFormatted = date('d.m.Y \u\m H:i \U\h\r');
            try {
                $this->patientService->addTimelineEntry([
                    'patient_id'   => (int)$data['patient_id'],
                    'type'         => 'payment',
                    'title'        => 'Quittung ' . ($data['invoice_number'] ?? '') . ' bezahlt',
                    'content'      => 'Barzahlung am ' . $paidAtFormatted . ' verbucht.',
                    'status_badge' => 'bar',
                    'entry_date'   => date('Y-m-d H:i:s'),
                    'user_id'      => (int)$this->session->get('user_id'),
                ]);
            } catch (\Throwable) {}
        }

        $msg = $isCash
            ? 'Quittung erstellt und als Barzahlung verbucht.'
            : $this->translator->trans('invoices.created');
        $this->session->flash('success', $msg);
        $this->redirect("/rechnungen/{$id}");
    }

    public function show(array $params = []): void
    {
        $invoice   = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $owner     = $invoice['owner_id'] ? $this->ownerService->findById((int)$invoice['owner_id']) : null;
        $patient   = $invoice['patient_id'] ? $this->patientService->findById((int)$invoice['patient_id']) : null;

        $this->render('invoices/show.twig', [
            'page_title' => $this->translator->trans('invoices.invoice') . ' ' . $invoice['invoice_number'],
            'invoice'    => $invoice,
            'positions'  => $positions,
            'owner'      => $owner,
            'patient'    => $patient,
        ]);
    }

    public function edit(array $params = []): void
    {
        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        if ($invoice['status'] === 'paid') {
            $this->session->flash('error', $this->translator->trans('invoices.cannot_edit_paid'));
            $this->redirect("/rechnungen/{$params['id']}");
            return;
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $patients  = $this->patientService->findAll();
        $owners    = $this->ownerService->findAll();

        $settings = $this->settingsRepository->all();
        $this->render('invoices/edit.twig', [
            'page_title'       => $this->translator->trans('invoices.edit'),
            'invoice'          => $invoice,
            'positions'        => $positions,
            'patients'         => $patients,
            'owners'           => $owners,
            'kleinunternehmer' => ($settings['kleinunternehmer'] ?? '0') === '1',
            'default_tax_rate' => $settings['default_tax_rate'] ?? '19',
        ]);
    }

    public function update(array $params = []): void
    {
        $this->validateCsrf();

        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $data = [
            'invoice_number' => $this->sanitize($this->post('invoice_number', '')),
            'patient_id'     => (int)$this->post('patient_id', 0) ?: null,
            'owner_id'       => (int)$this->post('owner_id', 0),
            'status'         => $this->sanitize($this->post('status', 'draft')),
            'issue_date'     => $this->post('issue_date') ?: date('Y-m-d'),
            'due_date'       => $this->post('due_date', null),
            'notes'          => $this->post('notes', ''),
            'payment_terms'  => $this->post('payment_terms', ''),
        ];

        $positions = $this->parsePositions();
        $this->invoiceService->update((int)$params['id'], $data, $positions);
        $this->session->flash('success', $this->translator->trans('invoices.updated'));
        $this->redirect("/rechnungen/{$params['id']}");
    }

    public function delete(array $params = []): void
    {
        $this->validateCsrf();

        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $this->invoiceService->delete((int)$params['id']);
        $this->session->flash('success', $this->translator->trans('invoices.deleted'));
        $this->redirect('/rechnungen');
    }

    public function updateStatus(array $params = []): void
    {
        $this->validateCsrf();

        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $status = $this->sanitize($this->post('status', ''));
        $allowed = ['draft', 'open', 'paid', 'overdue'];

        if (!in_array($status, $allowed, true)) {
            $this->session->flash('error', $this->translator->trans('invoices.invalid_status'));
            $this->redirect("/rechnungen/{$params['id']}");
            return;
        }

        $paidAt = ($status === 'paid') ? date('Y-m-d H:i:s') : null;
        $this->invoiceService->updateStatus((int)$params['id'], $status, $paidAt);

        /* ── Automatischer Timeline-Eintrag bei Bezahlung ── */
        if ($status === 'paid' && $invoice['patient_id']) {
            $paidAtFormatted = date('d.m.Y \u\m H:i \U\h\r');
            try {
                $this->patientService->addTimelineEntry([
                    'patient_id'   => (int)$invoice['patient_id'],
                    'type'         => 'payment',
                    'title'        => 'Rechnung ' . ($invoice['invoice_number'] ?? '') . ' bezahlt',
                    'content'      => 'Rechnung am ' . $paidAtFormatted . ' als bezahlt markiert.',
                    'status_badge' => 'bezahlt',
                    'entry_date'   => date('Y-m-d H:i:s'),
                    'user_id'      => (int)$this->session->get('user_id'),
                ]);
            } catch (\Throwable) {}
        }

        $msg = match($status) {
            'paid'    => '✅ Rechnung als bezahlt markiert.',
            'open'    => 'Rechnung auf "Offen" gesetzt.',
            'draft'   => 'Rechnung als Entwurf gespeichert.',
            'overdue' => '⚠️ Rechnung als überfällig markiert.',
            default   => $this->translator->trans('invoices.status_updated'),
        };
        $this->session->flash($status === 'paid' ? 'paid' : 'success', $msg);
        $this->redirect("/rechnungen/{$params['id']}");
    }

    public function positionsJson(array $params = []): void
    {
        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not found']);
            exit;
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);

        header('Content-Type: application/json');
        echo json_encode(['positions' => $positions]);
        exit;
    }

    public function preview(array $params = []): void
    {
        // Allow embedding in same-origin iframes (overrides server-level X-Frame-Options/CSP)
        header('X-Frame-Options: SAMEORIGIN', true);
        header("Content-Security-Policy: frame-ancestors 'self'", true);

        $invoice   = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $owner     = $invoice['owner_id'] ? $this->ownerService->findById((int)$invoice['owner_id']) : null;
        $patient   = $invoice['patient_id'] ? $this->patientService->findById((int)$invoice['patient_id']) : null;
        $settings  = $this->pdfService->getSettings();

        $this->render('invoices/preview.twig', [
            'page_title' => 'Rechnung ' . $invoice['invoice_number'],
            'invoice'    => $invoice,
            'positions'  => $positions,
            'owner'      => $owner,
            'patient'    => $patient,
            'settings'   => $settings,
        ]);
    }

    public function downloadPdf(array $params = []): void
    {
        $invoice   = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $owner     = $invoice['owner_id'] ? $this->ownerService->findById((int)$invoice['owner_id']) : null;
        $patient   = $invoice['patient_id'] ? $this->patientService->findById((int)$invoice['patient_id']) : null;

        $pdf = $this->pdfService->generateInvoicePdf($invoice, $positions, $owner, $patient);

        header('Content-Type: application/pdf');
        // Use inline so iframes/modals can display it, still downloadable via browser save
        header('Content-Disposition: inline; filename="Rechnung-' . $invoice['invoice_number'] . '.pdf"');
        echo $pdf;
        exit;
    }

    public function sendEmail(array $params = []): void
    {
        $this->validateCsrf();

        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $owner = $invoice['owner_id'] ? $this->ownerService->findById((int)$invoice['owner_id']) : null;
        if (!$owner || empty($owner['email'])) {
            $this->session->flash('error', $this->translator->trans('invoices.no_email'));
            $this->redirect("/rechnungen/{$params['id']}");
            return;
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $patient   = $invoice['patient_id'] ? $this->patientService->findById((int)$invoice['patient_id']) : null;
        $pdf       = $this->pdfService->generateInvoicePdf($invoice, $positions, $owner, $patient);

        $sent = $this->mailService->sendInvoice($invoice, $owner, $pdf);

        if ($sent) {
            $this->invoiceService->markEmailSent((int)$params['id']);
            $this->session->flash('success', $this->translator->trans('invoices.email_sent'));
        } else {
            $err = $this->mailService->getLastError();
            $this->session->flash('error', $this->translator->trans('invoices.email_failed') . ($err ? ': ' . $err : ''));
        }

        $this->redirect("/rechnungen/{$params['id']}");
    }

    public function downloadReceipt(array $params = []): void
    {
        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $owner     = $invoice['owner_id'] ? $this->ownerService->findById((int)$invoice['owner_id']) : null;
        $patient   = $invoice['patient_id'] ? $this->patientService->findById((int)$invoice['patient_id']) : null;

        $pdf = $this->pdfService->generateReceiptPdf($invoice, $positions, $owner, $patient);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Quittung-' . $invoice['invoice_number'] . '.pdf"');
        echo $pdf;
        exit;
    }

    public function formData(array $params = []): void
    {
        $settings = $this->settingsRepository->all();
        $owners   = $this->ownerService->findAll();
        $patients = $this->patientService->findAll();

        $treatmentTypes = [];
        try { $treatmentTypes = $this->treatmentTypeRepository->findActive(); } catch (\Throwable) {}

        $this->json([
            'next_number'      => $this->invoiceService->generateInvoiceNumber(),
            'kleinunternehmer' => ($settings['kleinunternehmer'] ?? '0') === '1',
            'default_tax_rate' => $settings['default_tax_rate'] ?? '19',
            'issue_date'       => date('Y-m-d'),
            'due_date'         => date('Y-m-d', strtotime('+14 days')),
            'owners'           => array_values($owners),
            'patients'         => array_values($patients),
            'treatment_types'  => array_values($treatmentTypes),
        ]);
    }

    public function sendReceiptEmail(array $params = []): void
    {
        $this->validateCsrf();

        $invoice = $this->invoiceService->findById((int)$params['id']);
        if (!$invoice) {
            $this->abort(404);
        }

        $owner = $invoice['owner_id'] ? $this->ownerService->findById((int)$invoice['owner_id']) : null;
        if (!$owner || empty($owner['email'])) {
            $this->session->flash('error', $this->translator->trans('invoices.no_email'));
            $this->redirect("/rechnungen/{$params['id']}");
            return;
        }

        $positions = $this->invoiceService->getPositions((int)$params['id']);
        $patient   = $invoice['patient_id'] ? $this->patientService->findById((int)$invoice['patient_id']) : null;
        $pdf       = $this->pdfService->generateReceiptPdf($invoice, $positions, $owner, $patient);

        $sent = $this->mailService->sendReceipt($invoice, $owner, $pdf);

        if ($sent) {
            $this->session->flash('success', 'Quittung erfolgreich per E-Mail gesendet.');
        } else {
            $err = $this->mailService->getLastError();
            $this->session->flash('error', 'Quittung konnte nicht gesendet werden' . ($err ? ': ' . $err : ''));
        }

        $this->redirect("/rechnungen/{$params['id']}");
    }

    private function parsePositions(): array
    {
        $descriptions = $_POST['position_description'] ?? [];
        $quantities   = $_POST['position_quantity']    ?? [];
        $prices       = $_POST['position_price']       ?? [];
        $taxRates     = $_POST['position_tax_rate']    ?? [];

        $positions = [];
        foreach ($descriptions as $i => $description) {
            if (empty(trim($description))) continue;
            $quantity  = (float)str_replace(',', '.', $quantities[$i] ?? 1);
            $price     = (float)str_replace(',', '.', $prices[$i] ?? 0);
            $taxRate   = (float)str_replace(',', '.', $taxRates[$i] ?? 19);
            $positions[] = [
                'description' => htmlspecialchars(trim($description), ENT_QUOTES, 'UTF-8'),
                'quantity'    => $quantity,
                'unit_price'  => $price,
                'tax_rate'    => $taxRate,
                'total'       => round($quantity * $price, 2),
            ];
        }

        return $positions;
    }
}
