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
        $sidebarColor      = $this->hexToRgb($settings['pdf_primary_color']              ?? '#8B9E8B');
        $accentColor       = $this->hexToRgb($settings['pdf_accent_color']               ?? '#6B7F6B');
        $colorCompanyName  = $this->hexToRgb($settings['pdf_color_company_name']         ?? '#1E1E1E');
        $colorCompanyInfo  = $this->hexToRgb($settings['pdf_color_company_info']         ?? '#6E6E6E');
        $colorRecipient    = $this->hexToRgb($settings['pdf_color_recipient']            ?? '#1E1E1E');
        $colorTableHdrBg   = $this->hexToRgb($settings['pdf_color_table_header_bg']      ?? '#8B9E8B');
        $colorTableHdrText = $this->hexToRgb($settings['pdf_color_table_header_text']    ?? '#FFFFFF');
        $colorTableText    = $this->hexToRgb($settings['pdf_color_table_text']           ?? '#1E1E1E');
        $colorLine         = $this->hexToRgb($settings['pdf_color_line']                 ?? '#B4B4B4');
        $colorTotalLabel   = $this->hexToRgb($settings['pdf_color_total_label']          ?? '#1E1E1E');
        $colorTotalGross   = $this->hexToRgb($settings['pdf_color_total_gross']          ?? '#1E1E1E');
        $colorFooter       = $this->hexToRgb($settings['pdf_color_footer']               ?? '#6E6E6E');
        $darkColor         = $colorCompanyName;
        $grayColor         = $colorCompanyInfo;
        $lightGray         = $colorLine;
        $font          = $this->resolvePdfFont($settings['pdf_font'] ?? 'helvetica');
        $fontSize      = (float)($settings['pdf_font_size'] ?? 9);
        $showPatient   = ($settings['pdf_show_patient']     ?? '1') === '1';
        $showChip      = ($settings['pdf_show_chip']        ?? '1') === '1';
        $showIban      = ($settings['pdf_show_iban']        ?? '1') === '1';
        $showTaxNum    = ($settings['pdf_show_tax_number']  ?? '1') === '1';
        $showWebsite   = ($settings['pdf_show_website']     ?? '0') === '1';
        $watermark        = trim($settings['pdf_watermark']    ?? '');
        $closingText      = trim($settings['pdf_closing_text'] ?? '');
        $kleinunternehmer = ($settings['kleinunternehmer']   ?? '0') === '1';

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

        // Sidebar: Kundennummer (if owner given)
        if ($owner) {
            $sideY += 22;
            $pdf->SetFont($font, '', $fontSize - 2);
            $pdf->SetTextColor(220, 235, 220);
            $pdf->SetXY(3, $sideY);
            $pdf->Cell($sidebarW - 6, 4, 'Kunden-Nr.', 0, 1, 'C');
            $pdf->SetFont($font, 'B', $fontSize - 1);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(3, $sideY + 4);
            $pdf->Cell($sidebarW - 6, 5, str_pad((string)($owner['id'] ?? 1), 4, '0', STR_PAD_LEFT), 0, 1, 'C');
        }

        // ── MAIN CONTENT ─────────────────────────────────────────────────

        // ── Company info top right FIRST ────────────────────────────────
        $companyInfoTopY = 8;
        $pdf->SetFont($font, 'B', $fontSize + 1);
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

        // "Rechnung" script image — BELOW company info, right-aligned
        $rechnungImg  = $this->resolveAssetImg($settings['pdf_rechnung_bild'] ?? 'rechnung-script.png');
        $rechnungImgY = $infoY + 4;
        if (file_exists($rechnungImg)) {
            $imgW = 72;
            $pdf->Image($rechnungImg, $rightEdge - $imgW, $rechnungImgY, $imgW, 0, '');
            // Calculate actual rendered height from image aspect ratio
            [$pw, $ph] = @getimagesize($rechnungImg) ?: [300, 120];
            $rechnungImgH = ($ph > 0 && $pw > 0) ? ($ph / $pw) * $imgW : 28;
        } else {
            $rechnungImgH = 18;
            $pdf->SetFont($font, 'BI', 34);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX, $rechnungImgY);
            $pdf->Cell($contentW, $rechnungImgH, 'Rechnung', 0, 1, 'R');
        }

        // Address block starts below company info + rechnung image
        $headerBottomY = $rechnungImgY + $rechnungImgH + 4;

        // ── ADDRESS BLOCK: Recipient left ────────────────────────────────
        $addrTopY = max($headerBottomY, 58);

        // Recipient (left column of content area)
        $colW = $contentW / 2 - 4;
        if ($owner) {
            $pdf->SetFont($font, 'B', $fontSize);
            $pdf->SetTextColor(...$colorRecipient);
            $pdf->SetXY($contentX, $addrTopY);
            $pdf->Cell($colW, 5.5, $owner['first_name'] . ' ' . $owner['last_name'], 0, 1);

            $pdf->SetFont($font, '', $fontSize - 0.5);
            $pdf->SetTextColor(...$colorRecipient);
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
            $pdf->SetTextColor(...$colorCompanyInfo);
            $pdf->SetXY($contentX, $addrTopY + 21);
            $pdf->Cell($colW, 4, 'Kunden-Nr. ' . str_pad((string)($owner['id'] ?? 1), 4, '0', STR_PAD_LEFT), 0, 1);
        }

        // Patient info (if enabled) — shown below recipient
        if ($showPatient && $patient) {
            $patY = $addrTopY + 30;
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$colorCompanyInfo);
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

        // Table header — thin line above/below, colored labels
        $pdf->SetDrawColor(...$colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $tableTopY, $rightEdge, $tableTopY);

        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$colorTableHdrText);

        $cQty   = 16;
        $cPrice = 26;
        $cTotal = 26;
        $cDesc  = $contentW - $cQty - $cPrice - $cTotal;

        // Header background
        $pdf->SetFillColor(...$colorTableHdrBg);
        $pdf->Rect($contentX, $tableTopY, $contentW, 7.5, 'F');

        $pdf->SetXY($contentX, $tableTopY + 1.5);
        $pdf->Cell($cQty,  4, 'Anzahl', 0, 0, 'L');
        $pdf->Cell($cDesc, 4, 'Produkt & Service', 0, 0, 'L');
        $pdf->Cell($cPrice,4, 'Preis', 0, 0, 'R');
        $pdf->Cell($cTotal,4, 'Total', 0, 1, 'R');

        $pdf->SetDrawColor(...$colorLine);
        $pdf->Line($contentX, $tableTopY + 7.5, $rightEdge, $tableTopY + 7.5);

        // Table rows
        $rowY = $tableTopY + 10;
        $pdf->SetTextColor(...$colorTableText);

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
            $pdf->SetTextColor(...$colorTableText);
            $pdf->SetXY($contentX, $rowY);
            $pdf->Cell($cQty,  5.5, $qtyStr, 0, 0, 'L');
            $pdf->Cell($cDesc, 5.5, $pos['description'], 0, 0, 'L');
            $pdf->Cell($cPrice,5.5, $priceStr, 0, 0, 'R');
            $pdf->Cell($cTotal,5.5, $totalStr, 0, 1, 'R');

            // Thin divider between rows
            $pdf->SetDrawColor(...$colorLine);
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
        $pdf->SetTextColor(...$colorTotalLabel);

        if ($kleinunternehmer) {
            // Kleinunternehmer: only show total (no MwSt. rows)
            $pdf->SetXY($totLabelX, $totY + 3);
            $pdf->Cell($totLabelW, 6, 'Gesamtbetrag (netto)', 0, 0, 'L');
            $pdf->Cell($cTotal, 6, number_format((float)$invoice['total_net'], 2, ',', '.') . ' €', 0, 1, 'R');
            $grossY = $totY + 12;
        } else {
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
                $pdf->SetTextColor(...$colorTotalLabel);
                $pdf->Cell($totLabelW, 6, 'MwSt. ' . number_format($rate, 0) . '%', 0, 0, 'L');
                $pdf->Cell($cTotal, 6, number_format($taxAmt, 2, ',', '.') . ' €', 0, 1, 'R');
                $taxOffsetY += 7;
            }
            $grossY = $taxOffsetY + 2;
        }

        // Gross total line
        $pdf->SetDrawColor(...$colorLine);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($totLabelX, $grossY, $rightEdge, $grossY);

        $pdf->SetFont($font, 'B', $fontSize + 0.5);
        $pdf->SetTextColor(...$colorTotalGross);
        $pdf->SetXY($totLabelX, $grossY + 3);
        $pdf->Cell($totLabelW, 7, 'Total', 0, 0, 'L');
        $pdf->Cell($cTotal, 7, number_format((float)$invoice['total_gross'], 2, ',', '.') . ' €', 0, 1, 'R');

        // ── NOTES ─────────────────────────────────────────────────────────
        $afterContentY = $grossY + 8; // track Y position after all content above Vielen Dank
        if (!empty($invoice['notes'])) {
            $notesY = $afterContentY;
            $pdf->SetFont($font, '', $fontSize - 1);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX, $notesY);
            $pdf->MultiCell($contentW, 4, $invoice['notes'], 0, 'L');
            $afterContentY = $pdf->GetY() + 4;
        }

        // ── "Vielen Dank!" — always AFTER notes, never overlapping ────────
        $vielenDankImgH = 22;
        $paymentTerms   = $invoice['payment_terms'] ?? $settings['payment_terms'] ?? '';
        $closingFull    = trim(($closingText ? $closingText . "\n" : '') . $paymentTerms);
        $closingLines   = $closingFull !== '' ? (substr_count($closingFull, "\n") + 1) : 0;
        $closingH       = $closingLines * 5;

        // Ideal position: push up from footer so everything fits
        $footerTopY  = 248;
        $thankYIdeal = $footerTopY - 14 - $closingH - $vielenDankImgH;
        // Never above actual content end
        $thankY = max($thankYIdeal, $afterContentY + 4);
        // If no room before footer, just place directly after content
        if ($thankY + $vielenDankImgH + $closingH + 14 > $footerTopY) {
            $thankY = $afterContentY + 4;
        }

        $vielenDankImg = $this->resolveAssetImg($settings['pdf_vielen_dank_bild'] ?? 'vielen-dank-script.png');
        if (file_exists($vielenDankImg)) {
            $imgW = 68;
            $pdf->Image($vielenDankImg, $contentX, $thankY, $imgW, 0, '');
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

        $pdf->SetDrawColor(...$colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $footerTopY, $rightEdge, $footerTopY);

        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(...$colorFooter);
        $pdf->SetXY($contentX, $footerTopY + 3);
        $pdf->Cell(60, 4.5, 'Bankverbindung', 0, 1);

        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$colorFooter);
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
            $pdf->SetTextColor(...$colorFooter);
            $pdf->SetXY($contentX + 70, $footerTopY + 10);
            $pdf->Cell($contentW - 70, 4, implode('   ', $contactParts), 0, 1, 'R');
        }

        // §19 UStG notice for Kleinunternehmer
        if ($kleinunternehmer) {
            $pdf->SetFont($font, 'I', $fontSize - 2);
            $pdf->SetTextColor(...$colorFooter);
            $pdf->SetXY($contentX, $footerTopY + 26);
            $pdf->Cell($contentW, 4, 'Gemäß §19 UStG wird keine Umsatzsteuer berechnet.', 0, 1, 'C');
        }

        return $pdf->Output('', 'S');
    }

    public function generateReceiptPdf(
        array $invoice,
        array $positions,
        ?array $owner,
        ?array $patient
    ): string {
        $settings = $this->settingsRepository->all();

        $sidebarColor      = $this->hexToRgb($settings['pdf_primary_color']              ?? '#8B9E8B');
        $accentColor       = $this->hexToRgb($settings['pdf_accent_color']               ?? '#6B7F6B');
        $colorCompanyName  = $this->hexToRgb($settings['pdf_color_company_name']         ?? '#1E1E1E');
        $colorCompanyInfo  = $this->hexToRgb($settings['pdf_color_company_info']         ?? '#6E6E6E');
        $colorRecipient    = $this->hexToRgb($settings['pdf_color_recipient']            ?? '#1E1E1E');
        $colorTableHdrBg   = $this->hexToRgb($settings['pdf_color_table_header_bg']      ?? '#8B9E8B');
        $colorTableHdrText = $this->hexToRgb($settings['pdf_color_table_header_text']    ?? '#FFFFFF');
        $colorTableText    = $this->hexToRgb($settings['pdf_color_table_text']           ?? '#1E1E1E');
        $colorLine         = $this->hexToRgb($settings['pdf_color_line']                 ?? '#B4B4B4');
        $colorTotalLabel   = $this->hexToRgb($settings['pdf_color_total_label']          ?? '#1E1E1E');
        $colorTotalGross   = $this->hexToRgb($settings['pdf_color_total_gross']          ?? '#1E1E1E');
        $colorFooter       = $this->hexToRgb($settings['pdf_color_footer']               ?? '#6E6E6E');
        $darkColor         = $colorCompanyName;
        $grayColor         = $colorCompanyInfo;

        $font          = $this->resolvePdfFont($settings['pdf_font'] ?? 'helvetica');
        $fontSize      = (float)($settings['pdf_font_size'] ?? 9);
        $showPatient   = ($settings['pdf_show_patient']    ?? '1') === '1';
        $showChip      = ($settings['pdf_show_chip']       ?? '1') === '1';
        $showIban      = ($settings['pdf_show_iban']       ?? '1') === '1';
        $showTaxNum    = ($settings['pdf_show_tax_number'] ?? '1') === '1';
        $showWebsite   = ($settings['pdf_show_website']    ?? '0') === '1';
        $kleinunternehmer = ($settings['kleinunternehmer'] ?? '0') === '1';

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

        $sidebarW  = 42;
        $contentX  = 50;
        $contentW  = 145;
        $rightEdge = 195;
        $pageH     = 297;

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        // ── LEFT SIDEBAR ─────────────────────────────────────────────────
        $pdf->SetFillColor(...$sidebarColor);
        $pdf->Rect(0, 0, $sidebarW, $pageH, 'F');

        // Logo
        $logoY = 14;
        if ($logoFile && file_exists($logoFile)) {
            $logoMaxW = $sidebarW - 10;
            $pdf->Image($logoFile, 5, $logoY, $logoMaxW, 0, '', '', '', false, 300);
            $logoY += 26;
        } else {
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

        // Quittungsnummer in sidebar
        $sideY = $logoY + 12;
        $pdf->SetFont($font, '', $fontSize - 2);
        $pdf->SetTextColor(220, 235, 220);
        $pdf->SetXY(3, $sideY);
        $pdf->Cell($sidebarW - 6, 4, 'Quittungs-Nr.', 0, 1, 'C');
        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(3, $sideY + 4);
        $pdf->Cell($sidebarW - 6, 5, $invoice['invoice_number'], 0, 1, 'C');

        // Datum
        $sideY += 22;
        $pdf->SetFont($font, '', $fontSize - 2);
        $pdf->SetTextColor(220, 235, 220);
        $pdf->SetXY(3, $sideY);
        $pdf->Cell($sidebarW - 6, 4, 'Datum', 0, 1, 'C');
        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(3, $sideY + 4);
        $pdf->Cell($sidebarW - 6, 5, $invoice['issue_date'] ? date('d.m.Y', strtotime($invoice['issue_date'])) : date('d.m.Y'), 0, 1, 'C');

        // Sidebar: Kundennummer
        if ($owner) {
            $sideY += 22;
            $pdf->SetFont($font, '', $fontSize - 2);
            $pdf->SetTextColor(220, 235, 220);
            $pdf->SetXY(3, $sideY);
            $pdf->Cell($sidebarW - 6, 4, 'Kunden-Nr.', 0, 1, 'C');
            $pdf->SetFont($font, 'B', $fontSize - 1);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(3, $sideY + 4);
            $pdf->Cell($sidebarW - 6, 5, str_pad((string)($owner['id'] ?? 1), 4, '0', STR_PAD_LEFT), 0, 1, 'C');
        }

        // ── BEZAHLT Stempel in Sidebar ───────────────────────────────────
        $stampY = $sideY + 26;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(...$accentColor);
        $pdf->SetLineWidth(0.6);
        $pdf->RoundedRect(4, $stampY, $sidebarW - 8, 16, 2.5, '1111', 'DF');
        $pdf->SetFont($font, 'B', $fontSize + 2);
        $pdf->SetTextColor(...$accentColor);
        $pdf->SetXY(4, $stampY + 5);
        $pdf->Cell($sidebarW - 8, 6, 'BEZAHLT', 0, 1, 'C');

        // ── MAIN CONTENT ─────────────────────────────────────────────────

        // Company info top right
        $companyInfoTopY = 8;
        $pdf->SetFont($font, 'B', $fontSize + 1);
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

        // "Quittung" script heading — use uploaded image or fallback text
        $quittungImgY = $infoY + 8;   // more breathing room after company info
        $quittungImgFile = !empty($settings['pdf_quittung_bild'])
            ? ROOT_PATH . '/public/assets/img/' . $settings['pdf_quittung_bild']
            : $this->resolveAssetImg('quittung-script.png');
        if (file_exists($quittungImgFile)) {
            $imgW = 90;   // bigger heading
            $pdf->Image($quittungImgFile, $rightEdge - $imgW, $quittungImgY, $imgW, 0, '');
            [$pw, $ph] = @getimagesize($quittungImgFile) ?: [300, 120];
            $quittungImgH = ($ph > 0 && $pw > 0) ? ($ph / $pw) * $imgW : 32;
        } else {
            $quittungImgH = 22;
            $pdf->SetFont($font, 'BI', 44);
            $pdf->SetTextColor(...$accentColor);
            $pdf->SetXY($contentX, $quittungImgY);
            $pdf->Cell($contentW, $quittungImgH, 'Quittung', 0, 1, 'R');
        }

        $headerBottomY = $quittungImgY + $quittungImgH + 4;
        $addrTopY = max($headerBottomY, 58);

        // ── ADDRESS BLOCK ────────────────────────────────────────────────
        $colW = $contentW / 2 - 4;
        if ($owner) {
            $pdf->SetFont($font, 'B', $fontSize);
            $pdf->SetTextColor(...$colorRecipient);
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
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$colorCompanyInfo);
            $pdf->SetXY($contentX, $addrTopY + 21);
            $pdf->Cell($colW, 4, 'Kunden-Nr. ' . str_pad((string)($owner['id'] ?? 1), 4, '0', STR_PAD_LEFT), 0, 1);
        }

        if ($showPatient && $patient) {
            $patY = $addrTopY + 30;
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$colorCompanyInfo);
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

        $pdf->SetDrawColor(...$colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $tableTopY, $rightEdge, $tableTopY);

        $cQty   = 16;
        $cPrice = 26;
        $cTotal = 26;
        $cDesc  = $contentW - $cQty - $cPrice - $cTotal;

        $pdf->SetFillColor(...$colorTableHdrBg);
        $pdf->Rect($contentX, $tableTopY, $contentW, 7.5, 'F');
        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$colorTableHdrText);
        $pdf->SetXY($contentX, $tableTopY + 1.5);
        $pdf->Cell($cQty,  4, 'Anzahl', 0, 0, 'L');
        $pdf->Cell($cDesc, 4, 'Produkt & Service', 0, 0, 'L');
        $pdf->Cell($cPrice,4, 'Preis', 0, 0, 'R');
        $pdf->Cell($cTotal,4, 'Total', 0, 1, 'R');

        $pdf->SetDrawColor(...$colorLine);
        $pdf->Line($contentX, $tableTopY + 7.5, $rightEdge, $tableTopY + 7.5);

        $rowY = $tableTopY + 10;
        $pdf->SetTextColor(...$colorTableText);

        foreach ($positions as $pos) {
            $lineNet  = (float)$pos['quantity'] * (float)$pos['unit_price'];
            $qtyStr   = number_format((float)$pos['quantity'], 0, ',', '.');
            $priceStr = number_format((float)$pos['unit_price'], 2, ',', '.') . ' €';
            $totalStr = number_format($lineNet, 2, ',', '.') . ' €';

            if ($rowY > 190) {
                $pdf->AddPage();
                $pdf->SetFillColor(...$sidebarColor);
                $pdf->Rect(0, 0, $sidebarW, $pageH, 'F');
                $pdf->SetFont($font, '', $fontSize - 2);
                $pdf->SetTextColor(220, 235, 220);
                $pdf->SetXY(3, 20);
                $pdf->Cell($sidebarW - 6, 4, 'Quittungs-Nr.', 0, 1, 'C');
                $pdf->SetFont($font, 'B', $fontSize - 1);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY(3, 24);
                $pdf->Cell($sidebarW - 6, 5, $invoice['invoice_number'], 0, 1, 'C');
                $rowY = 15;
            }

            $pdf->SetFont($font, '', $fontSize - 0.5);
            $pdf->SetTextColor(...$colorTableText);
            $pdf->SetXY($contentX, $rowY);
            $pdf->Cell($cQty,  5.5, $qtyStr, 0, 0, 'L');
            $pdf->Cell($cDesc, 5.5, $pos['description'], 0, 0, 'L');
            $pdf->Cell($cPrice,5.5, $priceStr, 0, 0, 'R');
            $pdf->Cell($cTotal,5.5, $totalStr, 0, 1, 'R');

            $pdf->SetDrawColor(...$colorLine);
            $pdf->SetLineWidth(0.15);
            $pdf->Line($contentX, $rowY + 6, $rightEdge, $rowY + 6);
            $rowY += 7.5;
        }

        // ── TOTALS ────────────────────────────────────────────────────────
        $totY = $rowY + 5;
        $pdf->SetDrawColor(...$accentColor);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($contentX, $totY, $rightEdge, $totY);

        $totLabelX = $contentX + $cQty + $cDesc - 10;
        $totLabelW = $cPrice + 10;

        $pdf->SetFont($font, '', $fontSize);
        $pdf->SetTextColor(...$colorTotalLabel);

        if ($kleinunternehmer) {
            $pdf->SetXY($totLabelX, $totY + 3);
            $pdf->Cell($totLabelW, 6, 'Gesamtbetrag (netto)', 0, 0, 'L');
            $pdf->Cell($cTotal, 6, number_format((float)$invoice['total_net'], 2, ',', '.') . ' €', 0, 1, 'R');
            $grossY = $totY + 12;
        } else {
            $pdf->SetXY($totLabelX, $totY + 3);
            $pdf->Cell($totLabelW, 6, 'Total exkl. MwSt.', 0, 0, 'L');
            $pdf->Cell($cTotal, 6, number_format((float)$invoice['total_net'], 2, ',', '.') . ' €', 0, 1, 'R');

            $taxGroups = [];
            foreach ($positions as $pos) {
                $rate = (float)$pos['tax_rate'];
                $taxGroups[$rate] = ($taxGroups[$rate] ?? 0) + ((float)$pos['quantity'] * (float)$pos['unit_price'] * $rate / 100);
            }
            $taxOffsetY = $totY + 10;
            foreach ($taxGroups as $rate => $taxAmt) {
                $pdf->SetXY($totLabelX, $taxOffsetY);
                $pdf->SetTextColor(...$colorTotalLabel);
                $pdf->Cell($totLabelW, 6, 'MwSt. ' . number_format($rate, 0) . '%', 0, 0, 'L');
                $pdf->Cell($cTotal, 6, number_format($taxAmt, 2, ',', '.') . ' €', 0, 1, 'R');
                $taxOffsetY += 7;
            }
            $grossY = $taxOffsetY + 2;
        }

        // Gross total
        $pdf->SetDrawColor(...$colorLine);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($totLabelX, $grossY, $rightEdge, $grossY);

        $pdf->SetFont($font, 'B', $fontSize + 0.5);
        $pdf->SetTextColor(...$colorTotalGross);
        $pdf->SetXY($totLabelX, $grossY + 3);
        $pdf->Cell($totLabelW, 7, 'Bezahlter Betrag', 0, 0, 'L');
        $pdf->Cell($cTotal, 7, number_format((float)$invoice['total_gross'], 2, ',', '.') . ' €', 0, 1, 'R');

        // ── POST-TOTALS BLOCK — positions calculated FROM BOTTOM UP ─────
        // Zone map (all mm from top of page):
        //   Footer line   : 248
        //   Vielen Dank   : 248 - 4 - 16  = 228
        //   Box bottom    : 228 - 4        = 224
        //   Box top       : 224 - 26       = 198  ($receiptBoxY)
        //   Barzahlung    : left of box, same top row

        $footerTopY  = 248;
        $receiptBoxH = 26;
        $receiptBoxW = $contentW;
        $receiptBoxX = $contentX;
        $receiptBoxY = $footerTopY - 4 - 16 - 4 - $receiptBoxH;  // = 198mm

        // Safety: never draw over the totals
        if ($receiptBoxY < $grossY + 14) {
            $receiptBoxY = $grossY + 14;
        }

        // ── BARZAHLUNG — left column, top-aligned with receipt box ──────
        $barzahlungImgFile = !empty($settings['pdf_barzahlung_bild'])
            ? ROOT_PATH . '/public/assets/img/' . $settings['pdf_barzahlung_bild']
            : $this->resolveAssetImg('barzahlung-script.png');
        $bzColW = 60;
        if (file_exists($barzahlungImgFile)) {
            $bzImgW = 52;
            [$bw, $bh] = @getimagesize($barzahlungImgFile) ?: [200, 80];
            $bzImgH    = ($bh > 0 && $bw > 0) ? ($bh / $bw) * $bzImgW : 14;
            // Center vertically within box height
            $bzImgY    = $receiptBoxY + ($receiptBoxH / 2) - ($bzImgH / 2);
            $pdf->Image($barzahlungImgFile, $contentX, $bzImgY, $bzImgW, 0, '');
        } else {
            $pdf->SetFont($font, 'I', 18);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX, $receiptBoxY + ($receiptBoxH / 2) - 4);
            $pdf->Cell($bzColW, 10, 'Barzahlung', 0, 0, 'L');
        }

        // ── BESTÄTIGUNGSBOX — right of Barzahlung ───────────────────────
        $receiptBoxX = $contentX + $bzColW + 4;
        $receiptBoxW = $contentW - $bzColW - 4;

        $pdf->SetFillColor(235, 247, 235);
        $pdf->SetDrawColor(...$accentColor);
        $pdf->SetLineWidth(0.6);
        $pdf->RoundedRect($receiptBoxX, $receiptBoxY, $receiptBoxW, $receiptBoxH, 3, '1111', 'DF');

        $pdf->SetFont($font, 'B', $fontSize + 1);
        $pdf->SetTextColor(...$accentColor);
        $pdf->SetXY($receiptBoxX + 3, $receiptBoxY + 3);
        $pdf->Cell($receiptBoxW - 6, 6, 'Hiermit wird der Zahlungseingang bestätigt', 0, 1, 'C');

        $issueDate      = !empty($invoice['issue_date']) ? date('d.m.Y', strtotime($invoice['issue_date'])) : date('d.m.Y');
        $grossFormatted = number_format((float)$invoice['total_gross'], 2, ',', '.') . ' €';
        $pdf->SetFont($font, '', $fontSize);
        $pdf->SetTextColor(...$colorTableText);
        $pdf->SetXY($receiptBoxX + 3, $receiptBoxY + 11);
        $pdf->Cell($receiptBoxW - 6, 5,
            'Betrag von ' . $grossFormatted . ' am ' . $issueDate . ' in bar erhalten.',
            0, 1, 'C');

        $pdf->SetFont($font, 'I', $fontSize - 1);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetXY($receiptBoxX + 3, $receiptBoxY + 19);
        $pdf->Cell($receiptBoxW - 6, 4, 'Rechnung-Nr.: ' . $invoice['invoice_number'], 0, 1, 'C');

        // ── VIELEN DANK — fixed at 228mm (between box bottom and footer) ─
        $vdY   = $footerTopY - 4 - 16;  // 228mm
        $vdImg = !empty($settings['pdf_vielen_dank_bild'])
            ? ROOT_PATH . '/public/assets/img/' . $settings['pdf_vielen_dank_bild']
            : $this->resolveAssetImg('vielen-dank-script.png');
        if (file_exists($vdImg)) {
            $pdf->Image($vdImg, $contentX, $vdY, 68, 0, '');
        } else {
            $pdf->SetFont($font, 'BI', 22);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX, $vdY);
            $pdf->Cell($contentW, 16, 'Vielen Dank!', 0, 1, 'L');
        }

        // ── FOOTER ────────────────────────────────────────────────────────
        $footerTopY = 248;
        $pdf->SetDrawColor(...$colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $footerTopY, $rightEdge, $footerTopY);

        $pdf->SetFont($font, 'B', $fontSize - 1);
        $pdf->SetTextColor(...$colorFooter);
        $pdf->SetXY($contentX, $footerTopY + 3);
        $pdf->Cell(60, 4.5, 'Bankverbindung', 0, 1);

        $pdf->SetFont($font, '', $fontSize - 1.5);
        $pdf->SetTextColor(...$colorFooter);
        $bankY = $footerTopY + 8;
        if ($bankName) { $pdf->SetXY($contentX, $bankY); $pdf->Cell(60, 4, $bankName, 0, 1); $bankY += 4; }
        if ($showIban && $bankIban) { $pdf->SetXY($contentX, $bankY); $pdf->Cell(12, 4, 'IBAN', 0, 0); $pdf->Cell(48, 4, $bankIban, 0, 1); $bankY += 4; }
        if ($bankBic)  { $pdf->SetXY($contentX, $bankY); $pdf->Cell(12, 4, 'BIC',  0, 0); $pdf->Cell(48, 4, $bankBic, 0, 1); $bankY += 4; }
        if ($showTaxNum && $taxNumber) { $pdf->SetXY($contentX, $bankY); $pdf->Cell(12, 4, 'St.-Nr.', 0, 0); $pdf->Cell(48, 4, $taxNumber, 0, 1); }

        $contactParts = array_filter([$companyEmail, ($showWebsite ? $companyWebsite : '')]);
        if ($contactParts) {
            $pdf->SetFont($font, '', $fontSize - 1.5);
            $pdf->SetTextColor(...$colorFooter);
            $pdf->SetXY($contentX + 70, $footerTopY + 10);
            $pdf->Cell($contentW - 70, 4, implode('   ', $contactParts), 0, 1, 'R');
        }

        if ($kleinunternehmer) {
            $pdf->SetFont($font, 'I', $fontSize - 2);
            $pdf->SetTextColor(...$colorFooter);
            $pdf->SetXY($contentX, $footerTopY + 26);
            $pdf->Cell($contentW, 4, 'Gemäß §19 UStG wird keine Umsatzsteuer berechnet.', 0, 1, 'C');
        }

        return $pdf->Output('', 'S');
    }

    public function generatePatientPdf(
        array $patient,
        ?array $owner,
        array $timeline
    ): string {
        $settings = $this->settingsRepository->all();

        $sidebarColor = $this->hexToRgb($settings['pdf_primary_color'] ?? '#8B9E8B');
        $accentColor  = $this->hexToRgb($settings['pdf_accent_color']  ?? '#6B7F6B');
        $font         = $this->resolvePdfFont($settings['pdf_font'] ?? 'helvetica');
        $fontSize     = (float)($settings['pdf_font_size'] ?? 9);
        $companyName  = $settings['company_name']   ?? '';
        $companyEmail = $settings['company_email']  ?? '';
        $companyPhone = $settings['company_phone']  ?? '';
        $logoFile     = !empty($settings['company_logo'])
            ? STORAGE_PATH . '/uploads/' . $settings['company_logo']
            : null;

        $darkColor = $this->hexToRgb($settings['pdf_color_company_name'] ?? '#1E1E1E');
        $grayColor = $this->hexToRgb($settings['pdf_color_company_info'] ?? '#6E6E6E');
        $lineColor = $this->hexToRgb($settings['pdf_color_line']         ?? '#B4B4B4');

        $sidebarW = 42;
        $contentX = 50;
        $contentW = 145;
        $pageH    = 297;

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // ── Sidebar ──────────────────────────────────────────────────────
        $this->drawPatientSidebar($pdf, $sidebarColor, $sidebarW, $pageH, $font, $fontSize, $logoFile, $patient, $owner);

        // ── Header: company name + title ─────────────────────────────────
        $pdf->SetFont($font, 'B', $fontSize + 3);
        $pdf->SetTextColor(...$darkColor);
        $pdf->SetXY($contentX, 10);
        $pdf->Cell($contentW, 7, 'Patientenakte', 0, 1, 'L');

        $pdf->SetFont($font, '', $fontSize - 1);
        $pdf->SetTextColor(...$grayColor);
        $pdf->SetXY($contentX, 17);
        $pdf->Cell($contentW, 5, 'Erstellt am ' . date('d.m.Y') . ($companyName ? ' · ' . $companyName : ''), 0, 1, 'L');

        // ── Patient info table ────────────────────────────────────────────
        $y = 26;
        $pdf->SetDrawColor(...$lineColor);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $y, $contentX + $contentW, $y);

        $pdf->SetFillColor(...$sidebarColor);
        $pdf->Rect($contentX, $y, $contentW, 7, 'F');
        $pdf->SetFont($font, 'B', $fontSize - 0.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($contentX + 2, $y + 1.5);
        $pdf->Cell($contentW - 4, 4, 'Patientendaten', 0, 1, 'L');
        $y += 9;

        $colW = $contentW / 2;
        $fields = [
            ['Name',         $patient['name']        ?? '—'],
            ['Tierart',      $patient['species']     ?? '—'],
            ['Rasse',        $patient['breed']       ?? '—'],
            ['Geschlecht',   $patient['gender']      ?? '—'],
            ['Farbe',        $patient['color']       ?? '—'],
            ['Chip-Nr.',     $patient['chip_number'] ?? '—'],
            ['Geburtsdatum', !empty($patient['birth_date']) ? date('d.m.Y', strtotime($patient['birth_date'])) : '—'],
            ['Status',       $patient['status']      ?? '—'],
        ];

        $pdf->SetFont($font, '', $fontSize - 1);
        $col = 0;
        $rowY = $y;
        foreach ($fields as $i => $field) {
            $xPos = $contentX + ($col * $colW);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($xPos + 2, $rowY);
            $pdf->Cell($colW - 4, 4, $field[0], 0, 0, 'L');
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($xPos + 2, $rowY + 4);
            $pdf->SetFont($font, 'B', $fontSize - 0.5);
            $pdf->Cell($colW - 4, 4.5, $field[1], 0, 0, 'L');
            $pdf->SetFont($font, '', $fontSize - 1);
            $col++;
            if ($col >= 2) {
                $col = 0;
                $rowY += 10;
            }
        }
        if ($col === 1) $rowY += 10;
        $y = $rowY + 2;

        // Owner info
        if ($owner) {
            $pdf->SetDrawColor(...$lineColor);
            $pdf->SetLineWidth(0.2);
            $pdf->Line($contentX, $y, $contentX + $contentW, $y);
            $y += 2;

            $pdf->SetFillColor(...$accentColor);
            $pdf->Rect($contentX, $y, $contentW, 7, 'F');
            $pdf->SetFont($font, 'B', $fontSize - 0.5);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($contentX + 2, $y + 1.5);
            $pdf->Cell($contentW - 4, 4, 'Tierhalter', 0, 1, 'L');
            $y += 9;

            $ownerName = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
            $kundenNr  = 'Kunden-Nr. ' . str_pad((string)($owner['id'] ?? 0), 4, '0', STR_PAD_LEFT);
            $ownerInfo = array_filter([
                $ownerName . '  (' . $kundenNr . ')',
                $owner['street'] ?? '',
                trim(($owner['zip'] ?? '') . ' ' . ($owner['city'] ?? '')),
                $owner['email'] ?? '',
                $owner['phone'] ?? '',
            ]);
            $pdf->SetFont($font, '', $fontSize - 0.5);
            $pdf->SetTextColor(...$darkColor);
            foreach ($ownerInfo as $line) {
                $pdf->SetXY($contentX + 2, $y);
                $pdf->Cell($contentW - 4, 5, $line, 0, 1, 'L');
                $y += 5;
            }
            $y += 2;
        }

        // Notes
        if (!empty($patient['notes'])) {
            $pdf->SetDrawColor(...$lineColor);
            $pdf->SetLineWidth(0.2);
            $pdf->Line($contentX, $y, $contentX + $contentW, $y);
            $y += 3;
            $pdf->SetFont($font, 'B', $fontSize - 1);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX + 2, $y);
            $pdf->Cell($contentW - 4, 4, 'Notizen', 0, 1);
            $y += 5;
            $pdf->SetFont($font, '', $fontSize - 0.5);
            $pdf->SetTextColor(...$darkColor);
            $pdf->SetXY($contentX + 2, $y);
            $pdf->MultiCell($contentW - 4, 4.5, strip_tags($patient['notes']), 0, 'L');
            $y = $pdf->GetY() + 3;
        }

        // ── Timeline ─────────────────────────────────────────────────────
        $y += 3;
        if ($y > 260) { $pdf->AddPage(); $y = 15; $this->drawPatientSidebar($pdf, $sidebarColor, $sidebarW, $pageH, $font, $fontSize, $logoFile, $patient, $owner); }

        $pdf->SetDrawColor(...$lineColor);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($contentX, $y, $contentX + $contentW, $y);
        $pdf->SetFillColor(...$sidebarColor);
        $pdf->Rect($contentX, $y, $contentW, 7, 'F');
        $pdf->SetFont($font, 'B', $fontSize - 0.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($contentX + 2, $y + 1.5);
        $pdf->Cell($contentW - 4, 4, 'Timeline (' . count($timeline) . ' Einträge)', 0, 1, 'L');
        $y += 10;

        $typeLabels = ['note' => 'Notiz', 'treatment' => 'Behandlung', 'photo' => 'Foto', 'document' => 'Dokument', 'other' => 'Sonstiges'];

        foreach ($timeline as $entry) {
            // Estimate height needed: header ~8, content variable, min 12
            $contentText = strip_tags(html_entity_decode($entry['content'] ?? '', ENT_QUOTES, 'UTF-8'));
            $contentLines = max(1, (int)ceil(mb_strlen($contentText) / 80));
            $entryH = 8 + ($contentLines * 4.5) + (empty($entry['title']) ? 0 : 5) + 4;

            if ($y + $entryH > 268) {
                $pdf->AddPage();
                $y = 15;
                $this->drawPatientSidebar($pdf, $sidebarColor, $sidebarW, $pageH, $font, $fontSize, $logoFile, $patient, $owner);
            }

            // Entry card background
            $pdf->SetFillColor(245, 246, 248);
            $pdf->RoundedRect($contentX, $y, $contentW, $entryH, 2, '1111', 'F');

            // Colored left dot bar
            $dotColors = [
                'note'      => $this->hexToRgb('#4f7cff'),
                'treatment' => $this->hexToRgb('#22c55e'),
                'photo'     => $this->hexToRgb('#a855f7'),
                'document'  => $this->hexToRgb('#f59e0b'),
                'other'     => $this->hexToRgb('#64748b'),
            ];
            $dc = $dotColors[$entry['type'] ?? 'other'] ?? $dotColors['other'];
            $pdf->SetFillColor(...$dc);
            $pdf->Rect($contentX, $y, 2, $entryH, 'F');

            // Meta line: type + date + status badge + user
            $pdf->SetFont($font, 'B', $fontSize - 2);
            $pdf->SetTextColor(...$dc);
            $typeLabel = $typeLabels[$entry['type'] ?? 'other'] ?? 'Sonstiges';
            $pdf->SetXY($contentX + 4, $y + 2);
            $pdf->Cell(28, 3.5, strtoupper($typeLabel), 0, 0, 'L');

            $pdf->SetFont($font, '', $fontSize - 2);
            $pdf->SetTextColor(...$grayColor);
            $dateStr = '';
            if (!empty($entry['entry_date'])) {
                $d = new \DateTime($entry['entry_date']);
                $dateStr = $d->format('d.m.Y H:i');
            }
            $pdf->SetXY($contentX + 32, $y + 2);
            $pdf->Cell(45, 3.5, $dateStr, 0, 0, 'L');

            if (!empty($entry['status_badge'])) {
                $pdf->SetFont($font, 'B', $fontSize - 2.5);
                $pdf->SetTextColor(...$accentColor);
                $pdf->SetXY($contentX + 78, $y + 2);
                $pdf->Cell(35, 3.5, $entry['status_badge'], 0, 0, 'L');
            }

            if (!empty($entry['user_name'])) {
                $pdf->SetFont($font, '', $fontSize - 2);
                $pdf->SetTextColor(...$grayColor);
                $pdf->SetXY($contentX + $contentW - 42, $y + 2);
                $pdf->Cell(40, 3.5, $entry['user_name'], 0, 0, 'R');
            }

            $innerY = $y + 7;

            // Title
            if (!empty($entry['title'])) {
                $pdf->SetFont($font, 'B', $fontSize);
                $pdf->SetTextColor(...$darkColor);
                $pdf->SetXY($contentX + 4, $innerY);
                $pdf->Cell($contentW - 8, 4.5, $entry['title'], 0, 1, 'L');
                $innerY += 5;
            }

            // Content (strip HTML tags)
            if (!empty($entry['content'])) {
                $clean = strip_tags(html_entity_decode($entry['content'], ENT_QUOTES, 'UTF-8'));
                $clean = preg_replace('/\s+/', ' ', trim($clean));
                if ($clean !== '') {
                    $pdf->SetFont($font, '', $fontSize - 0.5);
                    $pdf->SetTextColor(...$darkColor);
                    $pdf->SetXY($contentX + 4, $innerY);
                    $pdf->MultiCell($contentW - 8, 4.5, $clean, 0, 'L');
                }
            }

            // Treatment type tag
            if (!empty($entry['treatment_type_name'])) {
                $tagY = $y + $entryH - 5;
                $pdf->SetFont($font, 'B', $fontSize - 2.5);
                $pdf->SetTextColor(...$accentColor);
                $pdf->SetXY($contentX + $contentW - 55, $tagY);
                $pdf->Cell(52, 3.5, $entry['treatment_type_name'], 0, 0, 'R');
            }

            $y += $entryH + 3;
        }

        if (empty($timeline)) {
            $pdf->SetFont($font, 'I', $fontSize - 0.5);
            $pdf->SetTextColor(...$grayColor);
            $pdf->SetXY($contentX + 2, $y);
            $pdf->Cell($contentW - 4, 6, 'Noch keine Einträge vorhanden.', 0, 1, 'C');
        }

        return $pdf->Output('', 'S');
    }

    private function drawPatientSidebar(
        TCPDF $pdf,
        array $sidebarColor,
        float $sidebarW,
        float $pageH,
        string $font,
        float $fontSize,
        ?string $logoFile,
        array $patient,
        ?array $owner
    ): void {
        $pdf->SetFillColor(...$sidebarColor);
        $pdf->Rect(0, 0, $sidebarW, $pageH, 'F');

        $logoY = 14;
        if ($logoFile && file_exists($logoFile)) {
            $logoMaxW = $sidebarW - 10;
            $pdf->Image($logoFile, 5, $logoY, $logoMaxW, 0, '', '', '', false, 300);
            $logoY += 26;
        } else {
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

        // Patient name in sidebar
        $sideY = $logoY + 8;
        $pdf->SetFont($font, '', $fontSize - 2.5);
        $pdf->SetTextColor(200, 225, 200);
        $pdf->SetXY(3, $sideY);
        $pdf->Cell($sidebarW - 6, 4, 'PATIENT', 0, 1, 'C');
        $pdf->SetFont($font, 'B', $fontSize - 0.5);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(3, $sideY + 5);
        $pdf->MultiCell($sidebarW - 6, 5, $patient['name'] ?? '', 0, 'C');

        if (!empty($patient['species'])) {
            $pdf->SetFont($font, '', $fontSize - 2);
            $pdf->SetTextColor(200, 225, 200);
            $sideY2 = $pdf->GetY() + 2;
            $pdf->SetXY(3, $sideY2);
            $pdf->Cell($sidebarW - 6, 4, $patient['species'], 0, 1, 'C');
        }

        // Owner in sidebar
        if ($owner) {
            $owY = $pdf->GetY() + 8;
            $pdf->SetFont($font, '', $fontSize - 2.5);
            $pdf->SetTextColor(200, 225, 200);
            $pdf->SetXY(3, $owY);
            $pdf->Cell($sidebarW - 6, 4, 'TIERHALTER', 0, 1, 'C');
            $ownerName = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
            $pdf->SetFont($font, 'B', $fontSize - 1.5);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(3, $owY + 5);
            $pdf->MultiCell($sidebarW - 6, 4.5, $ownerName, 0, 'C');
            $pdf->SetFont($font, '', $fontSize - 2.5);
            $pdf->SetTextColor(200, 225, 200);
            $pdf->SetXY(3, $pdf->GetY() + 1);
            $pdf->Cell($sidebarW - 6, 4, 'Kunden-Nr. ' . str_pad((string)($owner['id'] ?? 0), 4, '0', STR_PAD_LEFT), 0, 1, 'C');
        }
    }

    public function getSettings(): array
    {
        return $this->settingsRepository->all();
    }

    private function resolveAssetImg(string $filename): string
    {
        return ROOT_PATH . '/public/assets/img/' . $filename;
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
