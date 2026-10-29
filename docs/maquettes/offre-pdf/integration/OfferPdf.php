<?php

namespace App\Api\Repositories\Utils;

use App\Api\Core\Language;
use IntlDateFormatter;
use TCPDF_FONTS;

/**
 * Le devis (« offre commerciale ») aux couleurs de L'Atelier by — version C2,
 * « une colonne » : une grande carte beige pour la date et le titre, trois
 * cartes (client, livraison, magasin), la sélection dans une carte, la
 * remarque du client à côté du total à payer, puis les conditions et le bon
 * pour accord. Tout en rectangles aux coins arrondis.
 *
 * Remplace les méthodes render*() d'OfferService :
 *
 *   $pdf = new OfferPdf($offerData, $shopData, $currencySymbol);
 *   $pdf->render();
 *   $pdf->Output($filename . '.pdf', 'S');
 *
 * TCPDF compte en millimètres et en points. L'en-tête et le pied sont dessinés
 * dans Header()/Footer() : une offre longue passe à la page suivante avec son
 * en-tête, et la sélection y reprend dans une nouvelle carte.
 *
 * Polices : Gotham Light / Medium / Black (convertis en TrueType, Gotham n'étant
 * livrée qu'en OpenType CFF que TCPDF ne lit pas) et Vank, dans $fontsDir.
 * TCPDF en tire ses définitions au premier passage, dans $fontsCacheDir. Si
 * elles manquent, le devis part quand même, en DejaVu, et le journal le dit.
 */
class OfferPdf extends TCPDFModel
{
    // La charte (design system L'Atelier by, global.css)
    private const RUBY = [141, 29, 44];         // #8D1D2C
    private const APRICOT = [242, 201, 160];    // #F2C9A0
    private const BEIGE = [234, 228, 220];      // #EAE4DC
    private const BEIGE_SOFT = [244, 239, 232]; // #F4EFE8
    private const INK = [34, 34, 34];           // #222222
    private const MUTED = [102, 102, 102];      // #666666
    private const ON_APRICOT = [107, 68, 32];   // #6B4420
    private const LINE = [221, 214, 205];       // filet chaud
    private const ROSE = [244, 226, 228];       // fond des pastilles de remise
    private const WHITE = [255, 255, 255];

    // La page (mm)
    private const LEFT = 15.0, RIGHT = 195.0, WIDTH = 180.0, PAD = 5.0;

    /** Les TrueType de la charte. */
    public static string $fontsDir = __DIR__ . '/offer-pdf-fonts/';
    /** Où TCPDF écrit ses définitions de polices (null : le dossier temporaire du système). */
    public static ?string $fontsCacheDir = null;
    /** Horodatage du document (null : maintenant). */
    public static ?int $now = null;

    private const FONT_FILES = [
        'gothamlight' => 'Gotham-Light.ttf',
        'gothammedium' => 'Gotham-Medium.ttf',
        'gothamblack' => 'Gotham-Black.ttf',
        'gc_vank' => 'GC_Vank.ttf',
    ];

    /** Les libellés que les traductions n'ont pas encore (groupe « offer »), par langue. */
    private const DEFAULT_LABELS = [
        'fr' => [
            'agreement' => 'Bon pour accord', 'agreement_date' => 'Date', 'agreement_signature' => 'Nom et signature',
            'attention_of' => "À l'attention de", 'continued' => 'suite', 'excl_vat' => 'HTVA', 'for' => 'Pour',
            'incl_vat' => 'TVAC', 'issued_on' => 'Émise le', 'prepared_by' => 'Préparé par', 'the_day' => 'Le jour J',
            'total_to_pay' => 'Total à payer', 'vat' => 'TVA', 'your_notes' => 'Vos remarques', 'your_selection' => 'Votre sélection',
        ],
        'nl' => [
            'agreement' => 'Voor akkoord', 'agreement_date' => 'Datum', 'agreement_signature' => 'Naam en handtekening',
            'attention_of' => 'Ter attentie van', 'continued' => 'vervolg', 'excl_vat' => 'excl. btw', 'for' => 'Voor',
            'incl_vat' => 'incl. btw', 'issued_on' => 'Opgemaakt op', 'prepared_by' => 'Bereid door', 'the_day' => 'De grote dag',
            'total_to_pay' => 'Te betalen', 'vat' => 'Btw', 'your_notes' => 'Uw opmerkingen', 'your_selection' => 'Uw selectie',
        ],
        'en' => [
            'agreement' => 'Approval', 'agreement_date' => 'Date', 'agreement_signature' => 'Name and signature',
            'attention_of' => 'For the attention of', 'continued' => 'continued', 'excl_vat' => 'excl. VAT', 'for' => 'For',
            'incl_vat' => 'incl. VAT', 'issued_on' => 'Issued on', 'prepared_by' => 'Prepared by', 'the_day' => 'The big day',
            'total_to_pay' => 'Total due', 'vat' => 'VAT', 'your_notes' => 'Your notes', 'your_selection' => 'Your selection',
        ],
    ];

    /** [police, style] par rôle ; DejaVu si les polices de la charte manquent. */
    private array $fontRoles = [
        'light' => ['gothamlight', ''], 'medium' => ['gothammedium', ''],
        'black' => ['gothamblack', ''], 'display' => ['gc_vank', ''],
    ];

    private array $offer;
    private array $shop;
    private array $client;
    private string $currency;

    public function __construct(array $offerData, array $shopData, string $currency)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->offer = $offerData;
        $this->shop = $shopData;
        $this->client = $offerData['client'];
        $this->currency = $currency;
        $this->registerFonts();
        $this->setCellPaddings(0, 0, 0, 0);
        $this->setCellMargins(0, 0, 0, 0);
        $this->setHeaderMargin(0);
        $this->setFooterMargin(0);
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetMargins(self::LEFT, 24, 210 - self::RIGHT);
        $this->SetAutoPageBreak(true, 19);
        $this->SetCreator("L'Atelier by");
        $this->SetTitle($this->t('commercial_offer_no') . ' ' . $this->number());
    }

    public function render(): self
    {
        $this->AddPage();
        $this->SetY(29);
        $this->renderDay();
        $this->SetY($this->GetY() + 4);
        $this->renderParties();
        $this->SetY($this->GetY() + 4);
        $this->renderSelection();
        $this->SetY($this->GetY() + 4);
        $this->renderNotesAndTotal();
        $this->SetY($this->GetY() + 4);
        $this->renderConditionsAndAgreement();
        return $this;
    }

    // === EN-TÊTE ET PIED, sur chaque page ===

    public function Header()
    {
        $logo = SHARED_FILES_URL . '/franchise-images/pdf-logo.jpg';
        $number = $this->t('commercial_offer_no') . ' ' . $this->number();
        if ($this->getPage() === 1) {
            $this->Image($logo, self::LEFT, 13, 50, 0, 'JPG');
            $this->label(110, 13.6, 85, $number, self::RUBY, 'R');
            $this->font('light', 7.5, self::MUTED);
            $this->paragraph(110, 17.4, 85, $this->t('issued_on') . ' ' . date('d/m/Y', $this->now()), 'R');
        } else {
            $this->Image($logo, self::LEFT, 10, 34, 0, 'JPG');
            $this->font('medium', 7.5, self::MUTED);
            $this->paragraph(110, 11.4, 85, $number . ' — ' . $this->t('continued'), 'R');
        }
    }

    public function Footer()
    {
        $y = 297 - 16;
        $this->rule(self::LEFT, $y, self::RIGHT, self::LINE, 0.3);
        $this->font('light', 6.3, self::MUTED);
        $this->paragraph(self::LEFT + 20, $y + 2.6, 140, $this->t('general_terms_available_upon_request') . ' '
            . $this->t('document_generated_on') . ' ' . date('d/m/Y à H:i', $this->now()) . '.', 'C');
        $this->SetXY(self::LEFT, $y + 2.6);
        $this->Cell(self::WIDTH, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        if (!empty($this->shop['bank_iban'])) {
            $this->font('medium', 6.8, self::RUBY, '', 0.15);
            $this->paragraph(self::LEFT, $y + 6.6, self::WIDTH, 'IBAN ' . $this->shop['bank_iban'] . '  ·  ' . $this->shop['bank_name'], 'C');
            $this->setFontSpacing(0);
        }
    }

    // === LES BLOCS ===

    /** La grande carte beige : la date dans une tuile blanche, le titre, le jour et le mode. */
    private function renderDay(): void
    {
        $o = $this->offer;
        $y = $this->GetY();
        $p = 4.0;
        $tile = 24.0;
        $xr = self::LEFT + $p + $tile + 6;
        $wr = self::RIGHT - $p - $xr;
        $this->font('display', 20);
        $titleHeight = $this->measure($wr, (string) $o['name'], 1.05);
        $h = max($tile + 2 * $p, $p + 4.4 + $titleHeight + 2.6 + 4.6 + $p);
        $this->card(self::LEFT, $y, self::WIDTH, $h, self::BEIGE);

        $ty = $y + ($h - $tile) / 2;
        $this->card(self::LEFT + $p, $ty, $tile, $tile, self::WHITE);
        $this->font('display', 28, self::RUBY);
        $this->paragraph(self::LEFT + $p, $ty + 3.4, $tile, $this->date((string) $o['event_day'], 'd'), 'C', 1);
        $this->font('medium', 6.3, self::INK, '', 0.3);
        $this->paragraph(self::LEFT + $p, $ty + 16.4, $tile, mb_strtoupper($this->date((string) $o['event_day'], 'MMM y')), 'C');
        $this->setFontSpacing(0);

        $this->label($xr, $y + $p, $wr, $this->t('the_day'));
        $this->font('display', 20);
        $yl = $y + $p + 4.4 + $this->paragraph($xr, $y + $p + 4.4, $wr, (string) $o['name'], 'L', 1.05) + 2.6;
        $day = $this->date((string) $o['event_day']);
        $this->font('light', 8.5);
        $this->SetXY($xr, $yl);
        $this->Cell($this->GetStringWidth($day), 4.6, $day, 0, 0, 'L', false, '', 0, false, 'T', 'M');
        $this->pillLeft($xr + $this->GetStringWidth($day) + 3.5, $yl + 2.3, $this->mode(), self::RUBY, self::WHITE);
        $this->SetY($y + $h);
    }

    /** Trois cartes de même hauteur : le client, le lieu, le magasin. */
    private function renderParties(): void
    {
        $p = 4.0;
        $gap = 4.0;
        $w = (self::WIDTH - 2 * $gap) / 3;
        $stacks = [$this->clientItems(), $this->placeItems(), $this->shopItems()];
        $h = 0.0;
        foreach ($stacks as $items) {
            $h = max($h, $this->stack(0, 0, $w - 2 * $p, $items, false) + 2 * $p);
        }
        $y = $this->GetY();
        foreach ($stacks as $i => $items) {
            $x = self::LEFT + $i * ($w + $gap);
            $this->card($x, $y, $w, $h, null, self::LINE);
            $this->stack($x + $p, $y + $p, $w - 2 * $p, $items);
        }
        $this->SetY($y + $h);
    }

    /** La sélection dans une carte beige ; sur une offre longue, une carte par page. */
    private function renderSelection(): void
    {
        $p = self::PAD;
        $nameWidth = self::WIDTH - 2 * $p - 30 - 6;
        $products = array_values((array) $this->offer['products']);
        $heights = array_map(fn($pr) => $this->productHeight($pr, $nameWidth) + 4, $products);
        $n = count($products);
        $i = 0;
        while ($i < $n) {
            $y0 = $this->GetY();
            $limit = $this->getPageHeight() - $this->getBreakMargin();
            $h = $p + 4.6;
            $j = $i;
            while ($j < $n && $y0 + $h + $heights[$j] + $p - 2 <= $limit) {
                $h += $heights[$j++];
            }
            if ($j === $i) {
                $this->AddPage();
                continue;
            }
            $h += $p - 2;
            $this->card(self::LEFT, $y0, self::WIDTH, $h, self::BEIGE_SOFT);
            $this->label(self::LEFT + $p, $y0 + $p, 80, $this->t('your_selection'));
            $this->label(self::RIGHT - $p - 50, $y0 + $p, 50, $this->t('total') . ' ' . $this->t('incl_vat'), self::MUTED, 'R');
            $y = $y0 + $p + 4.6;
            $this->rule(self::LEFT + $p, $y, self::RIGHT - $p, self::LINE, 0.3);
            for ($k = $i; $k < $j; $k++) {
                $this->product(self::LEFT + $p, $y + 2, self::WIDTH - 2 * $p, $products[$k], $nameWidth);
                if ($k < $j - 1) {
                    $this->rule(self::LEFT + $p, $y + $heights[$k], self::RIGHT - $p, self::LINE, 0.25);
                }
                $y += $heights[$k];
            }
            $this->SetY($y0 + $h);
            $i = $j;
            if ($i < $n) {
                $this->AddPage();
            }
        }
    }

    /** La remarque du client (abricot) et l'encadré du total (rubis), côte à côte, de même hauteur. */
    private function renderNotesAndTotal(): void
    {
        $p = 4.5;
        $totalWidth = 72.0;
        $notesWidth = self::WIDTH - $totalWidth - 5;
        $lines = $this->totalLines();
        $description = trim((string) $this->offer['description']);
        $notes = $description === '' ? null : [
            ['label', $this->t('your_notes'), 0, self::ON_APRICOT],
            ['light', 7.8, self::ON_APRICOT, $description, 1.6],
        ];
        $h = max(2 * $p + count($lines) * 4.6 + 15, $notes ? $this->stack(0, 0, $notesWidth - 2 * $p, $notes, false) + 2 * $p : 0);
        $this->ensureSpace($h);
        $y = $this->GetY();
        if ($notes) {
            $this->card(self::LEFT, $y, $notesWidth, $h, self::APRICOT);
            $this->stack(self::LEFT + $p, $y + $p, $notesWidth - 2 * $p, $notes);
        }
        $x = self::RIGHT - $totalWidth;
        $this->card($x, $y, $totalWidth, $h, self::RUBY);
        $yy = $y + $p;
        foreach ($lines as [$name, $amount]) {
            $this->font('light', 8, self::WHITE);
            $this->SetXY($x + $p, $yy);
            $this->Cell($totalWidth - 2 * $p, 5, $name, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $this->SetXY($x + $p, $yy);
            $this->Cell($totalWidth - 2 * $p, 5, $this->money($amount), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $yy += 4.6;
        }
        $bottom = $y + $h - $p;
        $this->rule($x + $p, $bottom - 13.8, $x + $totalWidth - $p, self::APRICOT, 0.3);
        $this->label($x + $p, $bottom - 11.4, $totalWidth - 2 * $p, $this->t('total_to_pay'), self::APRICOT, 'L', 6.6);
        $this->font('black', 17, self::WHITE);
        $this->SetXY($x + $p, $bottom - 8);
        $this->Cell($totalWidth - 2 * $p, 8, $this->money($this->totals()['toPay']), 0, 0, 'R', false, '', 0, false, 'T', 'B');
        $this->SetY($y + $h);
    }

    /** Une carte : les conditions à gauche, le bon pour accord à droite. */
    private function renderConditionsAndAgreement(): void
    {
        $p = 4.5;
        $agreementWidth = 70.0;
        $conditionsWidth = self::WIDTH - 2 * $p - $agreementWidth - 8;
        $text = trim((string) $this->offer['conditions']);
        $conditions = $text === '' ? [] : [['label', $this->t('conditions')], ['light', 8, self::INK, $text, 1.8]];
        $h = max($conditions ? $this->stack(0, 0, $conditionsWidth, $conditions, false) : 0, 16.5) + 2 * $p;
        $this->ensureSpace($h);
        $y = $this->GetY();
        $this->card(self::LEFT, $y, self::WIDTH, $h, null, self::LINE);
        if ($conditions) {
            $this->stack(self::LEFT + $p, $y + $p, $conditionsWidth, $conditions);
        }
        $x = self::RIGHT - $p - $agreementWidth;
        $this->label($x, $y + $p, $agreementWidth, $this->t('agreement'));
        $dateWidth = min(34.0, $agreementWidth * 0.32);
        foreach ([[$this->t('agreement_date'), $x, $dateWidth], [$this->t('agreement_signature'), $x + $dateWidth + 6, $agreementWidth - $dateWidth - 6]] as [$name, $xl, $wl]) {
            $this->rule($xl, $y + $p + 12, $xl + $wl, self::INK, 0.25);
            $this->font('light', 6.5, self::MUTED);
            $this->paragraph($xl, $y + $p + 13.2, $wl, $name);
        }
        $this->SetY($y + $h);
    }

    // === UNE LIGNE DE PRODUIT ===

    private function productHeight(array $p, float $nameWidth): float
    {
        $this->font('medium', 9);
        return $this->measure($nameWidth, (string) $p['product_name'], 1.2) + 0.8 + 3.6;
    }

    /** Le nom et « 60 × 1,20 € 1,08 € −10 % » à gauche, le total TVAC et le HTVA à droite. */
    private function product(float $x, float $y, float $w, array $p, float $nameWidth): void
    {
        $this->font('medium', 9);
        $yd = $y + $this->paragraph($x, $y, $nameWidth, (string) $p['product_name'], 'L', 1.2) + 0.8;

        $discount = $this->discount($p);
        $pieces = [[self::MUTED, '', number_format((float) $p['quantity'], 0, ',', "\u{00A0}") . "\u{00A0}×\u{00A0}"]];
        if ($discount !== '') {
            $pieces[] = [self::MUTED, 'D', $this->money((float) $p['unit_price'])];
            $pieces[] = [self::INK, '', '  ' . $this->money((float) $p['final_total_price'] / max(1, (float) $p['quantity'])) . '  '];
        } else {
            $pieces[] = [self::MUTED, '', $this->money((float) $p['unit_price'])];
        }
        $xd = $x;
        foreach ($pieces as [$color, $style, $text]) {
            $this->font('light', 7.5, $color, $style);
            $wd = $this->GetStringWidth($text);
            $this->SetXY($xd, $yd);
            $this->Cell($wd, 3.6, $text, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $xd += $wd;
        }
        if ($discount !== '') {
            $this->pillLeft($xd, $yd + 1.8, $discount, self::ROSE, self::RUBY);
        }

        $this->font('medium', 9);
        $this->SetXY($x + $w - 30, $y);
        $this->Cell(30, 4.3, $this->money((float) $p['final_total_price']), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->font('light', 6.8, self::MUTED);
        $this->SetXY($x + $w - 30, $y + 4.6);
        $this->Cell(30, 3, $this->t('excl_vat') . ' ' . $this->money((float) $p['final_total_price_net']), 0, 0, 'R', false, '', 0, false, 'T', 'M');
    }

    // === LES DONNÉES ===

    private function clientItems(): array
    {
        $c = $this->client;
        $lines = [];
        if ($c['is_b2b'] && trim(($c['firstname'] ?? '') . ($c['lastname'] ?? '')) !== '') {
            $lines[] = $this->t('attention_of') . ' ' . trim($c['firstname'] . ' ' . $c['lastname']);
        }
        $lines[] = trim($c['street'] . ' ' . $c['street_number']);
        $lines[] = trim($c['zip'] . ' ' . $c['city']);
        if ($c['is_b2b'] && !empty($c['tax_number'])) {
            $lines[] = $this->t('tax_number_short') . ' ' . $c['tax_number'];
        }
        if (!empty($c['phone'])) {
            $lines[] = $this->t('phone_short') . ' ' . $c['phone'];
        }
        if (!empty($c['email'])) {
            $lines[] = $c['email'];
        }
        $name = $c['is_b2b'] ? (string) $c['company_name'] : trim($c['firstname'] . ' ' . $c['lastname']);
        return [['label', $this->t('for')], ['medium', 9, self::INK, $name, 1.4], ['light', 7.5, self::MUTED, implode("\n", $lines), 0.6]];
    }

    /** L'adresse de livraison et ses frais, ou le magasin du retrait. */
    private function placeItems(): array
    {
        $o = $this->offer;
        $s = $this->shop;
        if ($o['in_store_pickup'] == 1) {
            return [
                ['label', $this->t('in_store_pickup')],
                ['medium', 9, self::INK, $this->shopName(), 1.4],
                ['light', 7.5, self::MUTED, trim($s['street'] . ' ' . $s['street_num']) . "\n" . trim($s['zip'] . ' ' . $s['city']), 0.6],
            ];
        }
        return [
            ['label', $this->t('delivery')],
            ['medium', 9, self::INK, (string) $o['delivery_street'], 1.4],
            ['light', 7.5, self::MUTED, trim($o['delivery_zip'] . ' ' . $o['delivery_city']) . "\n"
                . $this->t('shipping_cost') . ' ' . $this->money((float) $o['delivery_cost']), 0.6],
        ];
    }

    private function shopItems(): array
    {
        $s = $this->shop;
        $lines = [];
        if (!empty($s['name']) && $s['name'] !== $s['representative_name']) {
            $lines[] = $s['representative_name'];
        }
        $lines[] = trim($s['street'] . ' ' . $s['street_num']);
        $lines[] = trim($s['zip'] . ' ' . $s['city']);
        $lines[] = $this->t('tax_number_short') . ' ' . $s['tax_number_prefixe'] . $s['tax_number'];
        if (!empty($s['phone'])) {
            $lines[] = $this->t('phone_short') . ' ' . $s['phone'];
        }
        return [['label', $this->t('prepared_by')], ['medium', 9, self::INK, $this->shopName(), 1.4], ['light', 7.5, self::MUTED, implode("\n", $lines), 0.6]];
    }

    /** Le nom commercial du magasin (« Atelier by - Halle »), à défaut sa raison sociale. */
    private function shopName(): string
    {
        return (string) ($this->shop['name'] ?? $this->shop['representative_name']);
    }

    /** HTVA, TVA, TVAC, livraison et à payer. Les frais de livraison s'ajoutent tels quels. */
    private function totals(): array
    {
        $gross = $net = 0.0;
        foreach ((array) $this->offer['products'] as $p) {
            $gross += (float) $p['final_total_price'];
            $net += (float) $p['final_total_price_net'];
        }
        $delivery = $this->offer['in_store_pickup'] == 1 ? 0.0 : (float) $this->offer['delivery_cost'];
        return ['net' => $net, 'vat' => $gross - $net, 'gross' => $gross, 'delivery' => $delivery, 'toPay' => $gross + $delivery];
    }

    private function totalLines(): array
    {
        $t = $this->totals();
        $lines = [[$this->t('total') . ' ' . $this->t('excl_vat'), $t['net']], [$this->t('vat'), $t['vat']]];
        if ($t['delivery'] > 0) {
            $lines[] = [$this->t('shipping_cost'), $t['delivery']];
        }
        return $lines;
    }

    private function mode(): string
    {
        return mb_strtoupper($this->t($this->offer['in_store_pickup'] == 1 ? 'in_store_pickup' : 'delivery'));
    }

    private function number(): string
    {
        return str_pad((string) $this->offer['id'], 4, '0', STR_PAD_LEFT);
    }

    /** 1 234,56 € — espaces insécables, virgule décimale. */
    private function money(float $amount): string
    {
        return number_format($amount, 2, ',', "\u{00A0}") . "\u{00A0}" . $this->currency;
    }

    /** −10 %, −6,00 €, ou rien quand la ligne n'a pas de remise. */
    private function discount(array $p): string
    {
        $v = (float) $p['discount_value'];
        if ($v == 0.0) {
            return '';
        }
        if ((int) $p['id_discount_type'] === 1) {
            return '−' . rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . "\u{00A0}%";
        }
        return '−' . $this->money($v);
    }

    private function now(): int
    {
        return self::$now ?? time();
    }

    /** « jeudi 12 novembre 2026 », ou un morceau (« d », « MMM y ») dans la langue de l'appel. */
    private function date(string $ymd, string $pattern = 'EEEE d MMMM y'): string
    {
        $ts = strtotime($ymd);
        if (class_exists(IntlDateFormatter::class)) {
            $locale = ['fr' => 'fr_BE', 'nl' => 'nl_BE', 'en' => 'en_GB', 'pl' => 'pl_PL'][Language::getCallLanguageCode()] ?? 'fr_BE';
            $formatted = (new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, $pattern))->format($ts);
            if ($formatted !== false) {
                return $formatted;
            }
        }
        return date(['d' => 'j', 'MMM y' => 'm/Y'][$pattern] ?? 'd/m/Y', $ts);
    }

    /** La traduction, à défaut le libellé par défaut de la langue (puis le français). */
    private function t(string $key): string
    {
        $lang = Language::getCallLanguageCode();
        $value = getTranslation('offer', $key, $lang);
        if ($value !== null && $value !== '' && $value !== $key) {
            return (string) $value;
        }
        return self::DEFAULT_LABELS[$lang][$key] ?? self::DEFAULT_LABELS['fr'][$key] ?? $key;
    }

    // === OUTILS DE MISE EN PAGE ===

    private function font(string $role, float $size, array $color = self::INK, string $style = '', float $spacing = 0): void
    {
        [$name, $base] = $this->fontRoles[$role];
        $this->SetFont($name, $base . $style, $size);
        $this->SetTextColorArray($color);
        $this->setFontSpacing($spacing);
    }

    /** Un paragraphe en (x, y) sur la largeur w ; rend la hauteur occupée. */
    private function paragraph(float $x, float $y, float $w, string $text, string $align = 'L', float $lineHeight = 1.35): float
    {
        $this->setCellHeightRatio($lineHeight);
        $this->MultiCell($w, 0, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, 0, 'T', false);
        return $this->GetY() - $y;
    }

    /** La hauteur qu'occuperait paragraph() avec la police courante. */
    private function measure(float $w, string $text, float $lineHeight = 1.35): float
    {
        $this->setCellHeightRatio($lineHeight);
        return $this->getStringHeight($w, $text);
    }

    /** Une petite capitale espacée : « POUR », « LIVRAISON »… */
    private function label(float $x, float $y, float $w, string $text, array $color = self::RUBY, string $align = 'L', float $size = 6.3): float
    {
        $this->font('medium', $size, $color, '', 0.35);
        $h = $this->paragraph($x, $y, $w, mb_strtoupper($text), $align, 1.2);
        $this->setFontSpacing(0);
        return $h;
    }

    /**
     * Une pile de paragraphes : ['label', texte, espace avant, couleur] ou
     * [rôle de police, taille, couleur, texte, espace avant]. Rend la hauteur ;
     * ne dessine rien quand $draw est faux (mesurer avant de placer).
     */
    private function stack(float $x, float $y, float $w, array $items, bool $draw = true): float
    {
        $h = 0.0;
        foreach ($items as $it) {
            if ($it[0] === 'label') {
                $h += $it[2] ?? 0;
                $this->font('medium', 6.3, $it[3] ?? self::RUBY, '', 0.35);
                $text = mb_strtoupper($it[1]);
                $h += $draw ? $this->paragraph($x, $y + $h, $w, $text, 'L', 1.2) : $this->measure($w, $text, 1.2);
                $this->setFontSpacing(0);
            } else {
                $h += $it[4] ?? 0;
                $this->font($it[0], $it[1], $it[2]);
                $h += $draw ? $this->paragraph($x, $y + $h, $w, $it[3]) : $this->measure($w, $it[3]);
            }
        }
        return $h;
    }

    private function rule(float $x1, float $y, float $x2, array $color, float $width = 0.2): void
    {
        $this->Line($x1, $y, $x2, $y, ['width' => $width, 'color' => $color, 'dash' => 0, 'cap' => 'butt']);
    }

    /** Un rectangle aux coins arrondis : un fond, un contour, ou les deux. */
    private function card(float $x, float $y, float $w, float $h, ?array $fill, ?array $stroke = null, float $radius = 3.0): void
    {
        $style = $fill && $stroke ? 'DF' : ($fill ? 'F' : 'D');
        $line = $stroke ? ['width' => 0.35, 'color' => $stroke, 'dash' => 0, 'cap' => 'butt'] : [];
        $this->RoundedRect($x, $y, $w, $h, $radius, '1111', $style, $line, $fill ?? []);
    }

    /** Une pastille arrondie posée depuis sa gauche ; rend sa largeur. */
    private function pillLeft(float $x, float $yMiddle, string $text, array $fill, array $color, float $size = 6.3): float
    {
        $this->font('medium', $size, $color, '', 0.2);
        $w = $this->GetStringWidth($text) + 3.6;
        $h = $size * 0.3528 + 1.9;
        $this->RoundedRect($x, $yMiddle - $h / 2, $w, $h, $h / 2, '1111', 'F', [], $fill);
        $this->SetXY($x, $yMiddle - $h / 2);
        $this->Cell($w, $h, $text, 0, 0, 'C', false, '', 0, false, 'T', 'M');
        $this->setFontSpacing(0);
        return $w;
    }

    /** Reste-t-il h mm avant le pied de page ? Sinon, page suivante. */
    private function ensureSpace(float $h): void
    {
        if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage();
        }
    }

    /** Déclare les polices de la charte (TCPDF en tire ses définitions une fois) ; DejaVu à défaut. */
    private function registerFonts(): void
    {
        $cache = rtrim(self::$fontsCacheDir ?? sys_get_temp_dir() . '/offer-pdf-fonts', '/') . '/';
        $lock = null;
        try {
            if (!is_dir($cache) && !@mkdir($cache, 0775, true) && !is_dir($cache)) {
                throw new \RuntimeException("dossier $cache impossible à créer");
            }
            $lock = fopen($cache . '.lock', 'c');
            if ($lock) {
                flock($lock, LOCK_EX);
            }
            foreach (self::FONT_FILES as $name => $file) {
                if (is_file($cache . $name . '.php')) {
                    continue;
                }
                $ttf = rtrim(self::$fontsDir, '/') . '/' . $file;
                if (!is_file($ttf) || TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32, $cache) !== $name) {
                    throw new \RuntimeException("$ttf illisible");
                }
            }
            foreach (array_keys(self::FONT_FILES) as $name) {
                $this->AddFont($name, '', $cache . $name . '.php');
            }
        } catch (\Throwable $e) {
            $this->fontRoles = [
                'light' => ['dejavusans', ''], 'medium' => ['dejavusans', 'B'],
                'black' => ['dejavusans', 'B'], 'display' => ['dejavusans', 'B'],
            ];
            error_log('OfferPdf : polices de la charte indisponibles (' . $e->getMessage() . '), devis en DejaVu');
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
