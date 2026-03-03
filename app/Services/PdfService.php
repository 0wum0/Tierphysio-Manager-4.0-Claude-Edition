<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use TCPDF;

class PdfService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository
    ) {}

    public function generateInvoicePdf(
        array $invoice,
        array $positions,
        ?array $owner,
        ?array $patient
    ): string {
        $settings = $this->settingsRepository->all();

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $primaryColor = [41, 98, 255];
        $darkColor    = [15, 15, 26];
        $grayColor    = [100, 100, 120];
        $lightGray    = [245, 245, 250];

        $companyName   = $settings['company_name']   ?? 'Tierphysio Praxis';
        $companyStreet = $settings['company_street'] ?? '';
        $companyZip    = $settings['company_zip']    ?? '';
        $companyCity   = $settings['company_city']   ?? '';
        $companyPhone  = $settings['company_phone']  ?? '';
        $companyEmail  = $settings['company_email']  ?? '';
        $bankName      = $settings['bank_name']      ?? '';
        $bankIban      = $settings['bank_iban']      ?? '';
        $bankBic       = $settings['bank_bic']       ?? '';
        $taxNumber     = $settings['tax_number']     ?? '';
        $logoFile      = !empty($settings['company_logo'])
            ? STORAGE_PATH . '/uploads/' . $settings['company_logo']
            : null;

        // Header
        if ($logoFile && file_exists($logoFile)) {
            $pdf->Image($logoFile, 20, 15, 40, 0, '', '', '', false, 300);
        }

        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY(110, 15);
        $pdf->Cell(80, 10, $companyName, 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetXY(110, 26);
        $pdf->Cell(80, 5, $companyStreet, 0, 1, 'R');
        $pdf->SetXY(110, 31);
        $pdf->Cell(80, 5, $companyZip . ' ' . $companyCity, 0, 1, 'R');
        if ($companyPhone) {
            $pdf->SetXY(110, 36);
            $pdf->Cell(80, 5, 'Tel: ' . $companyPhone, 0, 1, 'R');
        }
        if ($companyEmail) {
            $pdf->SetXY(110, 41);
            $pdf->Cell(80, 5, $companyEmail, 0, 1, 'R');
        }

        // Divider line
        $pdf->SetDrawColor(...$primaryColor);
        $pdf->SetLineWidth(0.8);
        $pdf->Line(20, 55, 190, 55);

        // Recipient
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetXY(20, 60);
        $pdf->Cell(80, 4, $companyName . ' · ' . $companyStreet . ' · ' . $companyZip . ' ' . $companyCity, 0, 1);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY(20, 67);

        if ($owner) {
            $pdf->Cell(80, 6, $owner['first_name'] . ' ' . $owner['last_name'], 0, 1);
            $pdf->SetX(20);
            if (!empty($owner['street'])) $pdf->Cell(80, 6, $owner['street'], 0, 1);
            $pdf->SetX(20);
            if (!empty($owner['zip'])) $pdf->Cell(80, 6, $owner['zip'] . ' ' . ($owner['city'] ?? ''), 0, 1);
        }

        // Invoice Meta
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(...$primaryColor);
        $pdf->SetXY(110, 60);
        $pdf->Cell(80, 10, 'RECHNUNG', 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY(110, 72);
        $pdf->Cell(40, 5, 'Rechnungsnummer:', 0, 0, 'R');
        $pdf->Cell(40, 5, $invoice['invoice_number'], 0, 1, 'R');

        $pdf->SetXY(110, 78);
        $pdf->Cell(40, 5, 'Rechnungsdatum:', 0, 0, 'R');
        $issueDate = $invoice['issue_date'] ? date('d.m.Y', strtotime($invoice['issue_date'])) : '-';
        $pdf->Cell(40, 5, $issueDate, 0, 1, 'R');

        if (!empty($invoice['due_date'])) {
            $pdf->SetXY(110, 84);
            $pdf->Cell(40, 5, 'Fällig am:', 0, 0, 'R');
            $pdf->Cell(40, 5, date('d.m.Y', strtotime($invoice['due_date'])), 0, 1, 'R');
        }

        if ($patient) {
            $pdf->SetXY(110, 90);
            $pdf->Cell(40, 5, 'Patient:', 0, 0, 'R');
            $pdf->Cell(40, 5, $patient['name'] . ' (' . ($patient['species'] ?? '') . ')', 0, 1, 'R');
        }

        // Positions Table
        $tableY = 110;
        $pdf->SetFillColor(...$primaryColor);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY(20, $tableY);
        $pdf->Cell(90, 7, 'Beschreibung', 1, 0, 'L', true);
        $pdf->Cell(20, 7, 'Menge', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Einzelpreis', 1, 0, 'R', true);
        $pdf->Cell(20, 7, 'MwSt.', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Gesamt', 1, 1, 'R', true);

        $pdf->SetTextColor(...$darkColor);
        $pdf->SetFont('helvetica', '', 9);
        $fill = false;

        foreach ($positions as $pos) {
            $pdf->SetFillColor(...$lightGray);
            $lineNet = (float)$pos['quantity'] * (float)$pos['unit_price'];
            $pdf->Cell(90, 6, $pos['description'], 1, 0, 'L', $fill);
            $pdf->Cell(20, 6, number_format((float)$pos['quantity'], 2, ',', '.'), 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, number_format((float)$pos['unit_price'], 2, ',', '.') . ' €', 1, 0, 'R', $fill);
            $pdf->Cell(20, 6, (float)$pos['tax_rate'] . ' %', 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, number_format($lineNet, 2, ',', '.') . ' €', 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        // Totals
        $totalY = $pdf->GetY() + 5;
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(120, $totalY);
        $pdf->Cell(40, 6, 'Nettobetrag:', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format((float)$invoice['total_net'], 2, ',', '.') . ' €', 0, 1, 'R');

        $pdf->SetXY(120, $totalY + 6);
        $pdf->Cell(40, 6, 'MwSt.:', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format((float)$invoice['total_tax'], 2, ',', '.') . ' €', 0, 1, 'R');

        $pdf->SetDrawColor(...$primaryColor);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(120, $totalY + 13, 190, $totalY + 13);

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(...$primaryColor);
        $pdf->SetXY(120, $totalY + 14);
        $pdf->Cell(40, 8, 'Gesamtbetrag:', 0, 0, 'R');
        $pdf->Cell(30, 8, number_format((float)$invoice['total_gross'], 2, ',', '.') . ' €', 0, 1, 'R');

        // Notes / Payment Terms
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(...$darkColor);
        if (!empty($invoice['notes'])) {
            $pdf->SetXY(20, $totalY + 14);
            $pdf->MultiCell(90, 5, $invoice['notes'], 0, 'L');
        }

        $paymentTerms = $invoice['payment_terms'] ?? $settings['payment_terms'] ?? '';
        if (!empty($paymentTerms)) {
            $notesY = $pdf->GetY() + 8;
            $pdf->SetXY(20, $notesY);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetTextColor(...$grayColor);
            $pdf->MultiCell(170, 4, $paymentTerms, 0, 'L');
        }

        // Footer
        $footerY = 270;
        $pdf->SetDrawColor(...$grayColor);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(20, $footerY, 190, $footerY);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetXY(20, $footerY + 2);

        $footerParts = [];
        if ($bankName) $footerParts[] = 'Bank: ' . $bankName;
        if ($bankIban) $footerParts[] = 'IBAN: ' . $bankIban;
        if ($bankBic)  $footerParts[] = 'BIC: ' . $bankBic;
        if ($taxNumber) $footerParts[] = 'St.-Nr.: ' . $taxNumber;

        $pdf->Cell(170, 4, implode('   |   ', $footerParts), 0, 0, 'C');

        return $pdf->Output('', 'S');
    }
}
