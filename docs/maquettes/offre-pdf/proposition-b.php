<?php
declare(strict_types=1);

/**
 * PROPOSITION B — « BANDEAU RUBIS ».
 *
 * La marque d'abord : un bandeau rubis plein, le logo blanc, « Offre » en
 * Vank. Trois cartes — le magasin, le client, le jour J (abricot) — puis un
 * tableau ramené à six colonnes : le prix unitaire barré puis remisé dit à
 * lui seul le « avant / après remise ». À droite, l'encadré des totaux finit
 * sur le total à payer, livraison comprise, et la case « Bon pour accord »
 * que le client signe et renvoie. Le pied beige porte l'IBAN.
 */
final class OffrePdfB extends TCPDFModel
{
    use OffreMiseEnPage;

    private const G = 15.0, D = 195.0;
    // Produit, Qté, Prix unitaire, Remise, Total HTVA, Total TVAC — 180 mm
    private const COLS = [76, 13, 27, 18, 22, 24];

    public function __construct(array $offerData, array $shopData, string $devise)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->preparer($offerData, $shopData, $devise);
        $this->SetMargins(self::G, 25, 210 - self::D);
        $this->SetAutoPageBreak(true, 20);
    }

    public function rendre(): self
    {
        $this->AddPage();
        $this->SetY(55);
        $this->renderCartes();
        $this->renderProductsTable();
        $this->renderBas();
        return $this;
    }

    public function Header()
    {
        $num = OffreCharte::numero($this->offre);
        if ($this->getPage() === 1) {
            $this->Rect(0, 0, 210, 44, 'F', [], OffreCharte::RUBIS);
            $this->Image(OffreCharte::$logoBlanc, self::G, 16.5, 62, 0, 'PNG');
            $this->police(OffreCharte::VANK, 40, OffreCharte::BLANC);
            $this->bloc(100, 8.5, 95, OffreCharte::t('offer'), 'R', 1);
            $this->etiquette(100, 30.5, 95, OffreCharte::t('offer_no') . ' ' . $num . '   ·   ' . OffreCharte::t('issued_on') . ' '
                . date('d/m/Y', OffreCharte::maintenant()), OffreCharte::ABRICOT, 'R', 7);
            $this->Rect(0, 44, 210, 1.6, 'F', [], OffreCharte::ABRICOT);
        } else {
            $this->Rect(0, 0, 210, 15, 'F', [], OffreCharte::RUBIS);
            $this->Image(OffreCharte::$logoBlanc, self::G, 4.6, 34, 0, 'PNG');
            $this->etiquette(100, 6.2, 95, OffreCharte::t('commercial_offer_no') . ' ' . $num . ' — ' . OffreCharte::t('continued'), OffreCharte::ABRICOT, 'R', 6.8);
            $this->Rect(0, 15, 210, 1.2, 'F', [], OffreCharte::ABRICOT);
        }
    }

    public function Footer()
    {
        $s = $this->magasin;
        $y = 297 - 16;
        $this->Rect(0, $y, 210, 16, 'F', [], OffreCharte::BEIGE);
        $this->police(OffreCharte::MEDIUM, 6.8);
        $this->bloc(self::G, $y + 4.2, 120, implode('  ·  ', [
            $s['representative_name'],
            trim($s['street'] . ' ' . $s['street_num']) . ', ' . trim($s['zip'] . ' ' . $s['city']),
            OffreCharte::t('tax_number_short') . ' ' . $s['tax_number_prefixe'] . $s['tax_number'],
        ]));
        if (!empty($s['bank_iban'])) {
            $this->police(OffreCharte::MEDIUM, 6.8, OffreCharte::RUBIS, '', 0.1);
            $this->bloc(120, $y + 4.2, 75, 'IBAN ' . $s['bank_iban'] . '  ·  ' . $s['bank_name'], 'R');
            $this->setFontSpacing(0);
        }
        $this->police(OffreCharte::LIGHT, 6.3, OffreCharte::GRIS);
        $this->bloc(self::G, $y + 8.6, 150, OffreCharte::t('general_terms_available_upon_request') . ' '
            . OffreCharte::t('document_generated_on') . ' ' . date('d/m/Y à H:i', OffreCharte::maintenant()) . '.');
        $this->SetXY(self::G, $y + 8.6);
        $this->Cell(180, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    /** Le magasin, le client, le jour J ; puis l'objet de l'offre. */
    private function renderCartes(): void
    {
        $y = $this->GetY();
        $g = 4.0;
        $w = (180 - 2 * $g) / 3;
        $p = 5.0;
        $cartes = [
            [
                ['etq', OffreCharte::t('from')],
                [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, OffreCharte::nomMagasin($this->magasin), 1.6],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, implode("\n", OffreCharte::lignesMagasin($this->magasin)), 0.8],
            ],
            [
                ['etq', OffreCharte::t('for')],
                [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, OffreCharte::nomClient($this->client), 1.6],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, implode("\n", OffreCharte::lignesClient($this->client)), 0.8],
            ],
        ];
        $h = 40.0;
        foreach ($cartes as $c) {
            $h = max($h, $this->pile(0, 0, $w - 2 * $p, $c, false) + 2 * $p);
        }
        foreach ($cartes as $i => $c) {
            $x = self::G + $i * ($w + $g);
            $this->RoundedRect($x, $y, $w, $h, 2, '1111', 'F', [], OffreCharte::BEIGE_DOUX);
            $this->pile($x + $p, $y + $p, $w - 2 * $p, $c);
        }

        // Le jour J, sur l'abricot.
        $o = $this->offre;
        $x = self::G + 2 * ($w + $g);
        $this->RoundedRect($x, $y, $w, $h, 2, '1111', 'F', [], OffreCharte::ABRICOT);
        $yy = $y + $p + $this->pile($x + $p, $y + $p, $w - 2 * $p, [
            ['etq', OffreCharte::t('the_day'), 0, OffreCharte::SUR_ABRICOT],
            [OffreCharte::LIGHT, 8, OffreCharte::SUR_ABRICOT, OffreCharte::date((string) $o['event_day'], 'EEEE'), 1.6],
        ]);
        $this->police(OffreCharte::VANK, 20, OffreCharte::RUBIS);
        $yy += $this->bloc($x + $p, $yy, $w - 2 * $p, OffreCharte::date((string) $o['event_day'], 'd MMMM y'), 'L', 1.05) + 2.2;
        $mode = mb_strtoupper(OffreCharte::t($o['in_store_pickup'] == 1 ? 'in_store_pickup' : 'delivery'));
        $this->police(OffreCharte::MEDIUM, 6.3, OffreCharte::RUBIS, '', 0.2);
        $this->pastille($x + $p + $this->GetStringWidth($mode) + 3.6, $yy + 2.1, $mode, OffreCharte::BLANC, OffreCharte::RUBIS);
        $lieu = $o['in_store_pickup'] == 1
            ? trim($this->magasin['street'] . ' ' . $this->magasin['street_num']) . ', ' . $this->magasin['city']
            : $o['delivery_street'] . ', ' . $o['delivery_city'];
        $this->police(OffreCharte::LIGHT, 7.5, OffreCharte::SUR_ABRICOT);
        $this->bloc($x + $p, $yy + 6, $w - 2 * $p, $lieu);

        $this->SetY($y + $h + 8);
        $this->police(OffreCharte::VANK, 17, OffreCharte::RUBIS);
        $this->SetY($this->GetY() + $this->bloc(self::G, $this->GetY(), 180, (string) $o['name'], 'L', 1.1) + 4);
    }

    private function enteteTableau(): void
    {
        $t = [OffreCharte::class, 't'];
        $tetes = [$t('product'), $t('quantity_short'), $t('unit_price'), $t('discount'),
            $t('total') . ' ' . $t('excl_vat'), $t('total') . ' ' . $t('incl_vat')];
        $y = $this->GetY();
        $this->Rect(self::G, $y, 180, 8, 'F', [], OffreCharte::ABRICOT);
        $this->police(OffreCharte::MEDIUM, 6.2, OffreCharte::SUR_ABRICOT, '', 0.3);
        $x = self::G;
        foreach ($tetes as $i => $txt) {
            $this->SetXY($x + ($i === 0 ? 3 : 0), $y);
            $this->Cell(self::COLS[$i] - ($i === 0 || $i === 5 ? 3 : 0), 8, mb_strtoupper($txt), 0, 0, $i === 0 ? 'L' : 'R', false, '', 0, false, 'T', 'M');
            $x += self::COLS[$i];
        }
        $this->setFontSpacing(0);
        $this->SetY($y + 8);
    }

    private function renderProductsTable(): void
    {
        $d = $this->devise;
        $this->place(8 + 2 * 9);
        $this->enteteTableau();
        foreach ($this->offre['products'] as $p) {
            $this->police(OffreCharte::MEDIUM, 8);
            $h = max(9.0, $this->hauteur(self::COLS[0] - 9, (string) $p['product_name'], 1.25) + 4.2);
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage();
                $this->enteteTableau();
                $this->police(OffreCharte::MEDIUM, 8);
            }
            $y = $this->GetY();
            $x = self::G;
            $this->setCellHeightRatio(1.25);
            $this->MultiCell(self::COLS[0] - 9, $h, (string) $p['product_name'], 0, 'L', false, 0, $x + 3, $y, true, 0, false, true, $h, 'M');
            $x += self::COLS[0];

            $this->police(OffreCharte::LIGHT, 8);
            $this->SetXY($x, $y);
            $this->Cell(self::COLS[1], $h, number_format((float) $p['quantity'], 0, ',', "\u{00A0}"), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $x += self::COLS[1];

            $this->prixUnitaire($x + self::COLS[2], $y, $h, $p, 8);
            $x += self::COLS[2];

            $remise = OffreCharte::remise($p, $d);
            if ($remise !== '') {
                $this->pastille($x + self::COLS[3], $y + $h / 2, $remise, OffreCharte::ROSE, OffreCharte::RUBIS);
            } else {
                $this->police(OffreCharte::LIGHT, 8, OffreCharte::TRAIT);
                $this->SetXY($x, $y);
                $this->Cell(self::COLS[3] - 2, $h, '—', 0, 0, 'R', false, '', 0, false, 'T', 'M');
            }
            $x += self::COLS[3];

            $this->police(OffreCharte::LIGHT, 8, OffreCharte::GRIS);
            $this->SetXY($x, $y);
            $this->Cell(self::COLS[4], $h, OffreCharte::prix((float) $p['final_total_price_net'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $x += self::COLS[4];
            $this->police(OffreCharte::MEDIUM, 8);
            $this->Cell(self::COLS[5] - 3, $h, OffreCharte::prix((float) $p['final_total_price'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');

            $this->filet(self::G, $y + $h, self::D, OffreCharte::TRAIT, 0.2);
            $this->SetY($y + $h);
        }
        $this->SetY($this->GetY() + 6);
    }

    /** À gauche la livraison, les conditions, la remarque ; à droite les totaux et le bon pour accord. */
    private function renderBas(): void
    {
        $o = $this->offre;
        $d = $this->devise;
        $tot = OffreCharte::totaux($o);
        $wL = 100.0;
        $xR = 127.0;
        $wR = self::D - $xR;
        $p = 5.0;

        if ($o['in_store_pickup'] == 1) {
            $gauche = [
                ['etq', OffreCharte::t('in_store_pickup')],
                [OffreCharte::MEDIUM, 8.5, OffreCharte::NOIR, OffreCharte::nomMagasin($this->magasin), 1.8],
                [OffreCharte::LIGHT, 8, OffreCharte::NOIR, trim($this->magasin['street'] . ' ' . $this->magasin['street_num']) . "\n" . trim($this->magasin['zip'] . ' ' . $this->magasin['city']), 0.4],
            ];
        } else {
            $gauche = [
                ['etq', OffreCharte::t('delivery')],
                [OffreCharte::MEDIUM, 8.5, OffreCharte::NOIR, OffreCharte::nomClient($this->client), 1.8],
                [OffreCharte::LIGHT, 8, OffreCharte::NOIR, $o['delivery_street'] . "\n" . trim($o['delivery_zip'] . ' ' . $o['delivery_city']), 0.4],
            ];
        }
        if (trim((string) $o['conditions']) !== '') {
            $gauche[] = ['etq', OffreCharte::t('conditions'), 5];
            $gauche[] = [OffreCharte::LIGHT, 8, OffreCharte::NOIR, (string) $o['conditions'], 1.8];
        }
        $note = trim((string) $o['description']) === '' ? null : [
            ['etq', OffreCharte::t('your_notes')],
            [OffreCharte::LIGHT, 8, OffreCharte::NOIR, (string) $o['description'], 1.8],
        ];

        $lignes = [
            [OffreCharte::t('total') . ' ' . OffreCharte::t('excl_vat'), $tot['htva']],
            [OffreCharte::t('vat'), $tot['tva']],
            [OffreCharte::t('total') . ' ' . OffreCharte::t('incl_vat'), $tot['tvac']],
        ];
        if ($tot['livraison'] > 0) {
            $lignes[] = [OffreCharte::t('shipping_cost'), $tot['livraison']];
        }
        $hRecap = $p + count($lignes) * 5 + 3 + 4 + 9 + $p;
        $hAccord = 24.0;
        $hG = $this->pile(0, 0, $wL, $gauche, false) + ($note ? 6 + $this->pile(0, 0, $wL - 8, $note, false) + 8 : 0);
        $this->place(max($hG, $hRecap + 5 + $hAccord));
        $y = $this->GetY();

        // Gauche
        $yy = $y + $this->pile(self::G, $y, $wL, $gauche);
        if ($note) {
            $hn = $this->pile(0, 0, $wL - 8, $note, false) + 8;
            $this->RoundedRect(self::G, $yy + 6, $wL, $hn, 2, '1111', 'F', [], OffreCharte::BEIGE_DOUX);
            $this->pile(self::G + 4, $yy + 10, $wL - 8, $note);
        }

        // Droite : les totaux…
        $this->RoundedRect($xR, $y, $wR, $hRecap, 2, '1111', 'F', [], OffreCharte::RUBIS);
        $yy = $y + $p;
        foreach ($lignes as [$lib, $v]) {
            $this->police(OffreCharte::LIGHT, 8, OffreCharte::BLANC);
            $this->SetXY($xR + $p, $yy);
            $this->Cell($wR - 2 * $p, 5, $lib, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $this->SetXY($xR + $p, $yy);
            $this->Cell($wR - 2 * $p, 5, OffreCharte::prix($v, $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $yy += 5;
        }
        $this->filet($xR + $p, $yy + 1.5, $xR + $wR - $p, OffreCharte::ABRICOT, 0.3);
        $this->etiquette($xR + $p, $yy + 4, $wR - 2 * $p, OffreCharte::t('total_to_pay'), OffreCharte::ABRICOT, 'L', 6.8);
        $this->police(OffreCharte::BLACK, 17, OffreCharte::BLANC);
        $this->SetXY($xR + $p, $yy + 7.5);
        $this->Cell($wR - 2 * $p, 8, OffreCharte::prix($tot['aPayer'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');

        // … et le bon pour accord.
        $ya = $y + $hRecap + 5;
        $this->RoundedRect($xR, $ya, $wR, $hAccord, 2, '1111', 'D', ['width' => 0.3, 'color' => OffreCharte::GRIS, 'dash' => '1,1']);
        $this->etiquette($xR + $p, $ya + 4, $wR - 2 * $p, OffreCharte::t('agreement'));
        $this->police(OffreCharte::LIGHT, 6.5, OffreCharte::GRIS);
        $this->bloc($xR + $p, $ya + 7.6, $wR - 2 * $p, OffreCharte::t('agreement_date') . ', ' . mb_strtolower(OffreCharte::t('agreement_signature')));
        $this->filet($xR + $p, $ya + $hAccord - 5, $xR + $wR - $p, OffreCharte::TRAIT, 0.3);

        $this->SetY($y + max($hG, $hRecap + 5 + $hAccord));
    }
}
