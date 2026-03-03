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

        // Reserve space for footer (extra line if custom footer text set)
        $footerCustom = $settings['pdf_footer_text'] ?? '';
        $footerHeight = !empty($footerCustom) ? 14 : 10;
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 15, 20);
        $pdf->SetAutoPageBreak(true, $footerHeight + 8);
        $pdf->AddPage();

        $primaryColor = $this->hexToRgb($settings['pdf_primary_color'] ?? '#2962FF');
        $accentColor  = $this->hexToRgb($settings['pdf_accent_color']  ?? '#2962FF');
        $darkColor    = [15, 15, 26];
        $grayColor    = [100, 100, 120];
        $lightGray    = [245, 245, 250];
        $font         = $this->resolvePdfFont($settings['pdf_font'] ?? 'helvetica');
        $headerStyle  = $settings['pdf_header_style'] ?? 'line';
        $showLogo     = ($settings['pdf_show_logo']    ?? '1') === '1';
        $showPatient  = ($settings['pdf_show_patient'] ?? '1') === '1';

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

        // ── HEADER BLOCK (Y 15–48) ──────────────────────────────────────
        if ($showLogo && $logoFile && file_exists($logoFile)) {
            $pdf->Image($logoFile, 20, 15, 35, 0, '', '', '', false, 300);
        }

        $pdf->SetFont($font, 'B', 16);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY(105, 15);
        $pdf->Cell(85, 8, $companyName, 0, 1, 'R');

        $pdf->SetFont($font, '', 8);
        $pdf->SetTextColor(...$grayColor);
        $y = 24;
        foreach (array_filter([
            $companyStreet,
            trim($companyZip . ' ' . $companyCity),
            $companyPhone ? 'Tel: ' . $companyPhone : '',
            $companyEmail,
        ]) as $line) {
            $pdf->SetXY(105, $y);
            $pdf->Cell(85, 4, $line, 0, 1, 'R');
            $y += 4;
        }

        // Header divider at Y=48
        $divY = 48;
        if ($headerStyle === 'band') {
            $pdf->SetFillColor(...$primaryColor);
            $pdf->Rect(20, $divY, 170, 3, 'F');
        } else {
            $pdf->SetDrawColor(...$primaryColor);
            $pdf->SetLineWidth(0.6);
            $pdf->Line(20, $divY, 190, $divY);
        }

        // ── RECIPIENT + META (Y 52–90) ──────────────────────────────────
        // Small return address line
        $pdf->SetFont($font, '', 7);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetXY(20, 52);
        $pdf->Cell(85, 3.5, $companyName . ' · ' . $companyStreet . ' · ' . $companyZip . ' ' . $companyCity, 0, 1);

        // Recipient address
        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY(20, 57);
        if ($owner) {
            $pdf->Cell(85, 5.5, $owner['first_name'] . ' ' . $owner['last_name'], 0, 1);
            $pdf->SetX(20);
            if (!empty($owner['street'])) { $pdf->Cell(85, 5, $owner['street'], 0, 1); $pdf->SetX(20); }
            if (!empty($owner['zip']))    { $pdf->Cell(85, 5, $owner['zip'] . ' ' . ($owner['city'] ?? ''), 0, 1); }
        }

        // Invoice meta (right column, same height band)
        $pdf->SetFont($font, 'B', 13);
        $pdf->SetTextColor(...$accentColor);
        $pdf->SetXY(105, 52);
        $pdf->Cell(85, 8, 'RECHNUNG', 0, 1, 'R');

        $pdf->SetFont($font, '', 8.5);
        $pdf->SetTextColor(...$darkColor);
        $metaY = 62;
        $metaRows = [
            'Rechnungsnummer' => $invoice['invoice_number'],
            'Datum'           => $invoice['issue_date'] ? date('d.m.Y', strtotime($invoice['issue_date'])) : '-',
        ];
        if (!empty($invoice['due_date'])) {
            $metaRows['Fällig am'] = date('d.m.Y', strtotime($invoice['due_date']));
        }
        if ($showPatient && $patient) {
            $metaRows['Patient'] = $patient['name'] . ' (' . ($patient['species'] ?? '') . ')';
            if (!empty($patient['chip_number'])) {
                $metaRows['Chip-Nr.'] = $patient['chip_number'];
            }
        }
        foreach ($metaRows as $label => $value) {
            $pdf->SetXY(105, $metaY);
            $pdf->Cell(50, 4.5, $label . ':', 0, 0, 'R');
            $pdf->Cell(35, 4.5, $value, 0, 1, 'R');
            $metaY += 4.5;
        }

        // ── POSITIONS TABLE ─────────────────────────────────────────────
        // Start table below both columns, with a small gap
        $tableY = max($pdf->GetY(), $metaY) + 6;
        $pdf->SetFillColor(...$primaryColor);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont($font, 'B', 8.5);
        $pdf->SetXY(20, $tableY);
        $pdf->Cell(93, 6.5, 'Beschreibung', 1, 0, 'L', true);
        $pdf->Cell(18, 6.5, 'Menge', 1, 0, 'C', true);
        $pdf->Cell(27, 6.5, 'Einzelpreis', 1, 0, 'R', true);
        $pdf->Cell(15, 6.5, 'MwSt.', 1, 0, 'C', true);
        $pdf->Cell(27, 6.5, 'Gesamt', 1, 1, 'R', true);

        $pdf->SetTextColor(...$darkColor);
        $pdf->SetFont($font, '', 8.5);
        $fill = false;

        foreach ($positions as $pos) {
            $pdf->SetFillColor(...$lightGray);
            $lineNet = (float)$pos['quantity'] * (float)$pos['unit_price'];
            $pdf->Cell(93, 6, $pos['description'], 1, 0, 'L', $fill);
            $pdf->Cell(18, 6, number_format((float)$pos['quantity'], 2, ',', '.'), 1, 0, 'C', $fill);
            $pdf->Cell(27, 6, number_format((float)$pos['unit_price'], 2, ',', '.') . ' €', 1, 0, 'R', $fill);
            $pdf->Cell(15, 6, (float)$pos['tax_rate'] . ' %', 1, 0, 'C', $fill);
            $pdf->Cell(27, 6, number_format($lineNet, 2, ',', '.') . ' €', 1, 1, 'R', $fill);
            $fill = !$fill;
        }

        // ── TOTALS ───────────────────────────────────────────────────────
        $totalY = $pdf->GetY() + 4;
        $pdf->SetFont($font, '', 8.5);
        $pdf->SetTextColor(...$darkColor);

        $pdf->SetXY(125, $totalY);
        $pdf->Cell(38, 5.5, 'Nettobetrag:', 0, 0, 'R');
        $pdf->Cell(27, 5.5, number_format((float)$invoice['total_net'], 2, ',', '.') . ' €', 0, 1, 'R');

        $pdf->SetXY(125, $totalY + 5.5);
        $pdf->Cell(38, 5.5, 'MwSt.:', 0, 0, 'R');
        $pdf->Cell(27, 5.5, number_format((float)$invoice['total_tax'], 2, ',', '.') . ' €', 0, 1, 'R');

        $pdf->SetDrawColor(...$primaryColor);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(125, $totalY + 12, 190, $totalY + 12);

        $pdf->SetFont($font, 'B', 10);
        $pdf->SetTextColor(...$accentColor);
        $pdf->SetXY(125, $totalY + 13);
        $pdf->Cell(38, 7, 'Gesamtbetrag:', 0, 0, 'R');
        $pdf->Cell(27, 7, number_format((float)$invoice['total_gross'], 2, ',', '.') . ' €', 0, 1, 'R');

        // ── NOTES / PAYMENT TERMS ────────────────────────────────────────
        $pdf->SetFont($font, '', 8.5);
        $pdf->SetTextColor(...$darkColor);

        if (!empty($invoice['notes'])) {
            $pdf->SetXY(20, $totalY);
            $pdf->MultiCell(98, 5, $invoice['notes'], 0, 'L');
        }

        $paymentTerms = $invoice['payment_terms'] ?? $settings['payment_terms'] ?? '';
        if (!empty($paymentTerms)) {
            $afterTotals = max($pdf->GetY(), $totalY + 22) + 5;
            $pdf->SetXY(20, $afterTotals);
            $pdf->SetFont($font, 'I', 7.5);
            $pdf->SetTextColor(...$grayColor);
            $pdf->MultiCell(170, 3.5, $paymentTerms, 0, 'L');
        }

        // ── FOOTER (pinned to bottom of last page) ───────────────────────
        $pdf->SetY(-($footerHeight + 2));
        $pdf->SetDrawColor(...$grayColor);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->SetFont($font, '', 6.5);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetX(20);

        $footerParts = [];
        if ($bankName)  $footerParts[] = 'Bank: ' . $bankName;
        if ($bankIban)  $footerParts[] = 'IBAN: ' . $bankIban;
        if ($bankBic)   $footerParts[] = 'BIC: ' . $bankBic;
        if ($taxNumber) $footerParts[] = 'St.-Nr.: ' . $taxNumber;

        $pdf->Cell(170, 3.5, implode('   |   ', $footerParts), 0, 1, 'C');

        if (!empty($footerCustom)) {
            $pdf->SetX(20);
            $pdf->Cell(170, 3.5, $footerCustom, 0, 0, 'C');
        }

        return $pdf->Output('', 'S');
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }

    private function resolvePdfFont(string $font): string
    {
        return match($font) {
            'times'    => 'times',
            'courier'  => 'courier',
            'dejavusans' => 'dejavusans',
            default    => 'helvetica',
        };
    }
}
