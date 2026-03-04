<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Translator;
use App\Repositories\SettingsRepository;
use TCPDF;

class PdfService
{
    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly Translator $translator
    ) {}

    public function generateInvoicePdf(
        array $invoice,
        array $positions,
        ?array $owner,
        ?array $patient
    ): string {
        $settings = $this->settingsRepository->all();

        // ── Settings ─────────────────────────────────────────────────────
        $sidebarColor  = $this->hexToRgb($settings['pdf_primary_color'] ?? '#8B9E8B');
        $accentColor   = $this->hexToRgb($settings['pdf_accent_color']  ?? '#6B7F6B');
        $darkColor     = [30, 30, 30];
        $grayColor     = [110, 110, 110];
        $lightGray     = [180, 180, 180];
        $font          = $this->resolvePdfFont($settings['pdf_font'] ?? 'helvetica');
        $fontSize      = (float)($settings['pdf_font_size'] ?? 9);
        $showPatient   = ($settings['pdf_show_patient']     ?? '1') === '1';
        $showChip      = ($settings['pdf_show_chip']        ?? '1') === '1';
        $showIban      = ($settings['pdf_show_iban']        ?? '1') === '1';
        $showTaxNum    = ($settings['pdf_show_tax_number']  ?? '1') === '1';
        $showWebsite   = ($settings['pdf_show_website']     ?? '0') === '1';
        $watermark     = trim($settings['pdf_watermark']    ?? '');
        $closingText   = trim($settings['pdf_closing_text'] ?? '');

        $companyName    = $settings['company_name']    ?? '';
        $companyStreet  = $settings['company_street']  ?? '';
        $companyZip     = $settings['company_zip']     ?? '';
        $companyCity    = $settings['company_city']    ?? '';
        $companyPhone   = $settings['company_phone']   ?? '';
        $companyEmail   = $settings['company_email']   ?? '';
        $companyWebsite = $settings['company_website'] ?? '';
        $bankName       = $settings['bank_name']       ?? '';
        $bankIban       = $settings['bank_iban']       ?? '';
        $bankBic        = $settings['bank_bic']        ?? '';
        $taxNumber      = $settings['tax_number']      ?? '';
        $logoFile       = !empty($settings['company_logo'])
            ? STORAGE_PATH . '/uploads/' . $settings['company_logo']
            : null;

        // ── Layout constants ─────────────────────────────────────────────
        // Left sidebar: 0..sidebarW, Content: sidebarW+gap .. 210-rightMargin
        $sidebarW    = 42;   // mm — the coloured left strip
        $contentX    = 50;   // mm — where main content starts
        $contentW    = 145;  // mm — width of main content area (contentX + contentW = 195)
        $rightEdge   = 195;  // mm
        $pageH       = 297;  // mm A4

        // ── TCPDF setup ──────────────────────────────────────────────────
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        // ── WATERMARK ────────────────────────────────────────────────────
        if ($watermark !== '') {
            $pdf->SetFont($font, 'B', 55);
            $pdf->SetTextColor(220, 220, 220);
            $pdf->StartTransform();
            $pdf->Rotate(35, 105, 148);
            $pdf->SetXY(35, 133);
            $pdf->Cell(140, 30, mb_strtoupper($watermark), 0, 0, 'C');
            $pdf->StopTransform();
        }

        // ── LEFT SIDEBAR (full page height coloured band) ────────────────
        $pdf->SetFillColor(...$sidebarColor);
        $pdf->Rect(0, 0, $sidebarW, $pageH, 'F');

        // Logo inside sidebar — centred horizontally, near top
        $logoY = 14;
        if ($logoFile && file_exists($logoFile)) {
            $logoMaxW = $sidebarW - 10;
            $pdf->Image($logoFile, 5, $logoY, $logoMaxW, 0, '', '', '', false, 300);
            $logoY += 26;
        } else {
            // Placeholder circle with text
            $cx = $sidebarW / 2;
            $cy = $logoY + 12;
            $pdf->SetDrawColor(255, 255, 255);
            $pdf->SetLineWidth(0.5);
            $pdf->Circle($cx, $cy, 11, 0, 360, 'D');
            $pdf->SetFont($font, 'B', 7);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(3, $cy - 4);
            $pdf->Cell($sidebarW - 6, 8, 'LOGO', 0, 0, 'C');
            $logoY += 28;
        }

        // Sidebar: Rechnungsnummer label + value
        $sideY = $logoY + 12;
        $pdf->SetFont($font, '', $fontSize - 2);
        $pdf->SetTextColor(220, 235, 220);
        $pdf->SetXY(3, $sideY);
        $pdf->Cell($sidebarW - 6, 4, 'Rechnungsnummer', 0, 1, 'C');
        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(3, $sideY + 4);
        $pdf->Cell($sidebarW - 6, 5, $invoice['invoice_number'], 0, 1, 'C');

        // Sidebar: Rechnungsdatum label + value
        $sideY += 22;
        $pdf->SetFont($font, '', $fontSize - 2);
        $pdf->SetTextColor(220, 235, 220);
        $pdf->SetXY(3, $sideY);
        $pdf->Cell($sidebarW - 6, 4, 'Rechnungsdatum', 0, 1, 'C');
        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(3, $sideY + 4);
        $pdf->Cell($sidebarW - 6, 5, $invoice['issue_date'] ? date('d.m.Y', strtotime($invoice['issue_date'])) : '-', 0, 1, 'C');

        // Sidebar: Fälligkeitsdatum (if set)
        if (!empty($invoice['due_date'])) {
            $sideY += 22;
            $pdf->SetFont($font, '', $fontSize - 2);
            $pdf->SetTextColor(220, 235, 220);
            $pdf->SetXY(3, $sideY);
            $pdf->Cell($sidebarW - 6, 4, 'Fällig am', 0, 1, 'C');
            $pdf->SetFont($font, 'B', $fontSize - 1);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(3, $sideY + 4);
            $pdf->Cell($sidebarW - 6, 5, date('d.m.Y', strtotime($invoice['due_date'])), 0, 1, 'C');
        }

        // ── MAIN CONTENT ─────────────────────────────────────────────────

        // "Rechnung" script image — top right, height ~28mm
        $rechnungImg = ROOT_PATH . '/public/assets/img/rechnung-script.png';
        $rechnungImgH = 28; // rendered height in mm
        if (file_exists($rechnungImg)) {
            $imgW = 72;
            $pdf->Image($rechnungImg, $rightEdge - $imgW, 5, $imgW, 0, 'PNG');
        } else {
            $rechnungImgH = 18;
            $pdf->SetFont($font, 'BI', 34);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX, 5);
            $pdf->Cell($contentW, $rechnungImgH, 'Rechnung', 0, 1, 'R');
        }

        // Company info block — top right, BELOW the Rechnung image
        $companyInfoTopY = 5 + $rechnungImgH + 1;
        $pdf->SetFont($font, 'B', $fontSize);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY($contentX + ($contentW / 2), $companyInfoTopY);
        $pdf->Cell($contentW / 2, 5, $companyName, 0, 1, 'R');

        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$grayColor);
        $infoLines = array_filter([
            $companyStreet,
            trim($companyZip . ' ' . $companyCity),
            $companyPhone  ? 'Tel: ' . $companyPhone  : '',
            $companyEmail,
            ($showWebsite && $companyWebsite) ? $companyWebsite : '',
        ]);
        $infoY = $companyInfoTopY + 6;
        foreach ($infoLines as $line) {
            $pdf->SetXY($contentX + ($contentW / 2), $infoY);
            $pdf->Cell($contentW / 2, 4, $line, 0, 1, 'R');
            $infoY += 4;
        }
        // Address block starts below company info
        $headerBottomY = $infoY + 4;

        // ── ADDRESS BLOCK: Recipient left ────────────────────────────────
        $addrTopY = max($headerBottomY, 58);

        // Recipient (left column of content area)
        $colW = $contentW / 2 - 4;
        if ($owner) {
            $pdf->SetFont($font, 'B', $fontSize);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX, $addrTopY);
            $pdf->Cell($colW, 5.5, $owner['first_name'] . ' ' . $owner['last_name'], 0, 1);

            $pdf->SetFont($font, '', $fontSize - 0.5);
            $pdf->SetXY($contentX, $addrTopY + 6);
            if (!empty($owner['street'])) {
                $pdf->Cell($colW, 4.5, $owner['street'], 0, 1);
                $pdf->SetX($contentX);
            }
            if (!empty($owner['zip'])) {
                $pdf->Cell($colW, 4.5, $owner['zip'] . ' ' . ($owner['city'] ?? ''), 0, 1);
                $pdf->SetX($contentX);
            }
            // Kundennummer
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX, $addrTopY + 21);
            $pdf->Cell($colW, 4, 'Kunden-Nr. ' . str_pad((string)($owner['id'] ?? 1), 4, '0', STR_PAD_LEFT), 0, 1);
        }

        // Patient info (if enabled) — shown below recipient
        if ($showPatient && $patient) {
            $patY = $addrTopY + 30;
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX, $patY);
            $label = 'Patient: ' . $patient['name'];
            if (!empty($patient['species'])) $label .= ' (' . $patient['species'] . ')';
            $pdf->Cell($colW, 4, $label, 0, 1);
            if ($showChip && !empty($patient['chip_number'])) {
                $pdf->SetXY($contentX, $patY + 4);
                $pdf->Cell($colW, 4, 'Chip-Nr.: ' . $patient['chip_number'], 0, 1);
            }
        }

        // ── POSITIONS TABLE ───────────────────────────────────────────────
        $tableTopY = max($addrTopY + 38, 100);

        // Table header — thin line above/below, grey labels
        $pdf->SetDrawColor(...$lightGray);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $tableTopY, $rightEdge, $tableTopY);

        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$grayColor);

        $cQty   = 16;
        $cPrice = 26;
        $cTotal = 26;
        $cDesc  = $contentW - $cQty - $cPrice - $cTotal;

        $pdf->SetXY($contentX, $tableTopY + 1.5);
        $pdf->Cell($cQty,  4, 'Anzahl', 0, 0, 'L');
        $pdf->Cell($cDesc, 4, 'Produkt & Service', 0, 0, 'L');
        $pdf->Cell($cPrice,4, 'Preis', 0, 0, 'R');
        $pdf->Cell($cTotal,4, 'Total', 0, 1, 'R');

        $pdf->Line($contentX, $tableTopY + 7, $rightEdge, $tableTopY + 7);

        // Table rows
        $rowY = $tableTopY + 9;
        $pdf->SetTextColor(...$darkColor);

        foreach ($positions as $pos) {
            $lineNet  = (float)$pos['quantity'] * (float)$pos['unit_price'];
            $qtyStr   = number_format((float)$pos['quantity'], 0, ',', '.');
            $priceStr = number_format((float)$pos['unit_price'], 2, ',', '.') . ' €';
            $totalStr = number_format($lineNet, 2, ',', '.') . ' €';

            // Check for page overflow — leave space for totals (~40mm) + footer (~50mm)
            if ($rowY > 190) {
                $pdf->AddPage();
                $pdf->SetFillColor(...$sidebarColor);
                $pdf->Rect(0, 0, $sidebarW, $pageH, 'F');
                // Redraw sidebar labels on continuation page
                $pdf->SetFont($font, '', $fontSize - 2);
                $pdf->SetTextColor(220, 235, 220);
                $pdf->SetXY(3, 20);
                $pdf->Cell($sidebarW - 6, 4, 'Rechnungsnummer', 0, 1, 'C');
                $pdf->SetFont($font, 'B', $fontSize - 1);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY(3, 24);
                $pdf->Cell($sidebarW - 6, 5, $invoice['invoice_number'], 0, 1, 'C');
                $rowY = 15;
            }

            // Main description line
            $pdf->SetFont($font, '', $fontSize - 0.5);
            $pdf->SetXY($contentX, $rowY);
            $pdf->Cell($cQty,  5.5, $qtyStr, 0, 0, 'L');
            $pdf->Cell($cDesc, 5.5, $pos['description'], 0, 0, 'L');
            $pdf->Cell($cPrice,5.5, $priceStr, 0, 0, 'R');
            $pdf->Cell($cTotal,5.5, $totalStr, 0, 1, 'R');

            // Thin divider between rows
            $pdf->SetDrawColor(...$lightGray);
            $pdf->SetLineWidth(0.15);
            $pdf->Line($contentX, $rowY + 6, $rightEdge, $rowY + 6);

            $rowY += 7.5;
        }

        // ── TOTALS ────────────────────────────────────────────────────────
        $totY = $rowY + 5;

        // Thick accent line above totals
        $pdf->SetDrawColor(...$accentColor);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($contentX, $totY, $rightEdge, $totY);

        $totLabelX = $contentX + $cQty + $cDesc - 10;
        $totLabelW = $cPrice + 10;

        $pdf->SetFont($font, '', $fontSize);
        $pdf->SetTextColor(...$darkColor);

        // Net
        $pdf->SetXY($totLabelX, $totY + 3);
        $pdf->Cell($totLabelW, 6, 'Total exkl. MwSt.', 0, 0, 'L');
        $pdf->Cell($cTotal, 6, number_format((float)$invoice['total_net'], 2, ',', '.') . ' €', 0, 1, 'R');

        // Tax rows — group by rate
        $taxGroups = [];
        foreach ($positions as $pos) {
            $rate = (float)$pos['tax_rate'];
            $taxGroups[$rate] = ($taxGroups[$rate] ?? 0) + ((float)$pos['quantity'] * (float)$pos['unit_price'] * $rate / 100);
        }
        $taxOffsetY = $totY + 10;
        foreach ($taxGroups as $rate => $taxAmt) {
            $pdf->SetXY($totLabelX, $taxOffsetY);
            $pdf->Cell($totLabelW, 6, 'MwSt. ' . number_format($rate, 0) . '%', 0, 0, 'L');
            $pdf->Cell($cTotal, 6, number_format($taxAmt, 2, ',', '.') . ' €', 0, 1, 'R');
            $taxOffsetY += 7;
        }

        // Gross total
        $grossY = $taxOffsetY + 2;
        $pdf->SetDrawColor(...$lightGray);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($totLabelX, $grossY, $rightEdge, $grossY);

        $pdf->SetFont($font, 'B', $fontSize + 0.5);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY($totLabelX, $grossY + 3);
        $pdf->Cell($totLabelW, 7, 'Total', 0, 0, 'L');
        $pdf->Cell($cTotal, 7, number_format((float)$invoice['total_gross'], 2, ',', '.') . ' €', 0, 1, 'R');

        // ── NOTES ─────────────────────────────────────────────────────────
        if (!empty($invoice['notes'])) {
            $notesY = $grossY + 18;
            $pdf->SetFont($font, '', $fontSize - 1);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX, $notesY);
            $pdf->MultiCell($contentW, 4, $invoice['notes'], 0, 'L');
        }

        // ── "Vielen Dank!" — placed above footer with enough room ─────────
        // Image height approx 20mm, then closing text, then footer at 248
        $vielenDankImgH = 22;
        $paymentTerms   = $invoice['payment_terms'] ?? $settings['payment_terms'] ?? '';
        $closingFull    = trim(($closingText ? $closingText . "\n" : '') . $paymentTerms);
        $closingLines   = $closingFull !== '' ? (substr_count($closingFull, "\n") + 1) : 0;
        $closingH       = $closingLines * 5;
        // Place "Vielen Dank!" so that text + footer fits: footer starts at 248
        $thankY = 248 - 50 - $vielenDankImgH - $closingH; // ~176 with no closing
        // But never above the totals block
        $thankY = max($thankY, $grossY + 18);

        $vielenDankImg = ROOT_PATH . '/public/assets/img/vielen-dank-script.png';
        if (file_exists($vielenDankImg)) {
            $imgW = 68;
            $pdf->Image($vielenDankImg, $contentX, $thankY, $imgW, 0, 'PNG');
        } else {
            $pdf->SetFont($font, 'BI', 24);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX, $thankY);
            $pdf->Cell($contentW, $vielenDankImgH, 'Vielen Dank!', 0, 1, 'L');
        }

        // Closing / payment terms BELOW the image
        if ($closingFull !== '') {
            $closingY = $thankY + $vielenDankImgH + 2;
            $pdf->SetFont($font, '', $fontSize - 1);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX, $closingY);
            $pdf->MultiCell($contentW, 4.5, $closingFull, 0, 'L');
        }

        // ── FOOTER (bank details + contact, bottom of page) ───────────────
        $footerTopY = 248;

        $pdf->SetDrawColor(...$lightGray);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $footerTopY, $rightEdge, $footerTopY);

        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY($contentX, $footerTopY + 3);
        $pdf->Cell(60, 4.5, 'Bankverbindung', 0, 1);

        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$grayColor);
        $bankY = $footerTopY + 8;
        if ($bankName) {
            $pdf->SetXY($contentX, $bankY);
            $pdf->Cell(60, 4, $bankName, 0, 1);
            $bankY += 4;
        }
        if ($showIban && $bankIban) {
            $pdf->SetXY($contentX, $bankY);
            $pdf->Cell(12, 4, 'IBAN', 0, 0);
            $pdf->Cell(48, 4, $bankIban, 0, 1);
            $bankY += 4;
        }
        if ($bankBic) {
            $pdf->SetXY($contentX, $bankY);
            $pdf->Cell(12, 4, 'BIC', 0, 0);
            $pdf->Cell(48, 4, $bankBic, 0, 1);
            $bankY += 4;
        }
        if ($showTaxNum && $taxNumber) {
            $pdf->SetXY($contentX, $bankY);
            $pdf->Cell(12, 4, 'St.-Nr.', 0, 0);
            $pdf->Cell(48, 4, $taxNumber, 0, 1);
        }

        // Contact line bottom-right of footer
        $contactParts = array_filter([$companyEmail, ($showWebsite ? $companyWebsite : '')]);
        if ($contactParts) {
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX + 70, $footerTopY + 10);
            $pdf->Cell($contentW - 70, 4, implode('   ', $contactParts), 0, 1, 'R');
        }

        return $pdf->Output('', 'S');
    }

    public function getSettings(): array
    {
        return $this->settingsRepository->all();
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
