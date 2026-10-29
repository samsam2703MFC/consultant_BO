<?php
declare(strict_types=1);

/**
 * PROPOSITION A — « ÉPURÉE ».
 *
 * Le document d'aujourd'hui, ses blocs dans le même ordre et ses huit
 * colonnes, réimprimé dans la charte : papier blanc, le rubis pour seule
 * couleur, Gotham pour le texte, Vank pour le titre. Pas un montant de plus
 * ni de moins : les montants alignés à droite, des en-têtes qui disent ce
 * qu'ils comptent (avant remise, HTVA, TVAC), « dont TVA » sous le total.
 * Le moins d'encre, et le plus petit pas depuis le document actuel.
 */
final class OffrePdfA extends TCPDFModel
{
    use OffreMiseEnPage;

    private const G = 15.0, D = 195.0;
    // Produit, Qté, P.U., Total avant remise, Remise, P.U. après remise, Total HTVA, Total TVAC — 180 mm
    private const COLS = [56, 11, 17, 22, 16, 19, 19, 20];

    public function __construct(array $offerData, array $shopData, string $devise)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->preparer($offerData, $shopData, $devise);
        $this->SetMargins(self::G, 25, 210 - self::D);
        $this->SetAutoPageBreak(true, 25);
    }

    public function rendre(): self
    {
        $this->AddPage();
        $this->SetY(41);
        $this->renderParties();
        $this->renderOfferTitle();
        $this->renderProductsTable();
        $this->renderDeliveryAndComments();
        return $this;
    }

    public function Header()
    {
        $num = OffreCharte::numero($this->offre);
        if ($this->getPage() === 1) {
            $this->Image(OffreCharte::$logo, self::G, 15, 58, 0, 'PNG');
            $this->etiquette(110, 14.2, 85, OffreCharte::t('commercial_offer'), OffreCharte::RUBIS, 'R');
            $this->police(OffreCharte::VANK, 22);
            $this->bloc(110, 17.6, 85, OffreCharte::t('offer_no') . ' ' . $num, 'R', 1);
            $this->police(OffreCharte::LIGHT, 7.5, OffreCharte::GRIS);
            $this->bloc(110, 27.2, 85, OffreCharte::t('issued_on') . ' ' . date('d/m/Y', OffreCharte::maintenant()), 'R');
            $this->filet(self::G, 34, self::D, OffreCharte::RUBIS, 0.45);
        } else {
            $this->Image(OffreCharte::$logo, self::G, 10, 34, 0, 'PNG');
            $this->police(OffreCharte::MEDIUM, 7.5, OffreCharte::GRIS);
            $this->bloc(110, 11.4, 85, OffreCharte::t('commercial_offer_no') . ' ' . $num . ' — ' . OffreCharte::t('continued'), 'R');
            $this->filet(self::G, 18, self::D, OffreCharte::RUBIS, 0.45);
        }
    }

    public function Footer()
    {
        $y = 297 - 18;
        $this->filet(self::G, $y, self::D, OffreCharte::TRAIT, 0.3);
        $this->police(OffreCharte::LIGHT, 6.5, OffreCharte::GRIS);
        $this->bloc(self::G + 20, $y + 2.6, 140, OffreCharte::t('general_terms_available_upon_request') . ' '
            . OffreCharte::t('document_generated_on') . ' ' . date('d/m/Y à H:i', OffreCharte::maintenant()) . '.', 'C');
        $this->SetXY(self::G, $y + 2.6);
        $this->Cell(180, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        if (!empty($this->magasin['bank_iban'])) {
            $this->police(OffreCharte::MEDIUM, 6.8, OffreCharte::RUBIS, '', 0.15);
            $this->bloc(self::G, $y + 6.6, 180, 'IBAN ' . $this->magasin['bank_iban'] . ' — ' . $this->magasin['bank_name'], 'C');
            $this->setFontSpacing(0);
        }
    }

    /** Le magasin à gauche, le client à droite sur un fond beige. */
    private function renderParties(): void
    {
        $y = $this->GetY();
        $bas = $y + $this->pile(self::G, $y, 80, [
            ['etq', OffreCharte::t('from')],
            [OffreCharte::MEDIUM, 10, OffreCharte::NOIR, OffreCharte::nomMagasin($this->magasin), 1.4],
            [OffreCharte::LIGHT, 8, OffreCharte::GRIS, implode("\n", OffreCharte::lignesMagasin($this->magasin)), 0.6],
        ]);

        $x = 108.0;
        $p = 5.0;
        $client = [
            ['etq', OffreCharte::t('for')],
            [OffreCharte::MEDIUM, 10, OffreCharte::NOIR, OffreCharte::nomClient($this->client), 1.4],
            [OffreCharte::LIGHT, 8, OffreCharte::NOIR, implode("\n", OffreCharte::lignesClient($this->client)), 0.6],
        ];
        $h = $this->pile($x + $p, $y, self::D - $x - 2 * $p, $client, false);
        $this->Rect($x, $y - 4.2, self::D - $x, $h + 4.2 + $p, 'F', [], OffreCharte::BEIGE_DOUX);
        $this->pile($x + $p, $y, self::D - $x - 2 * $p, $client);

        $this->SetY(max($bas, $y + $h + $p) + 9);
    }

    private function renderOfferTitle(): void
    {
        $y = $this->GetY();
        $this->police(OffreCharte::VANK, 18, OffreCharte::RUBIS);
        $y += $this->bloc(self::G, $y, 180, (string) $this->offre['name'], 'L', 1.1) + 1.2;
        $lab = OffreCharte::t('event_date') . '  ·  ';
        $this->police(OffreCharte::MEDIUM, 8.5);
        $w = $this->GetStringWidth($lab);
        $this->SetXY(self::G, $y);
        $this->Cell($w, 4.5, $lab);
        $this->police(OffreCharte::LIGHT, 8.5);
        $this->Cell(180 - $w, 4.5, OffreCharte::date((string) $this->offre['event_day']));
        $this->SetY($y + 4.5 + 7);
    }

    private function enteteTableau(): void
    {
        $t = [OffreCharte::class, 't'];
        $tetes = [
            $t('product'), $t('quantity_short'), $t('unit_price_short'),
            $t('total') . "\n" . $t('before_discount'), $t('discount'),
            $t('unit_price_short') . "\n" . $t('after_discount'),
            $t('total') . "\n" . $t('excl_vat'), $t('total') . "\n" . $t('incl_vat'),
        ];
        $y = $this->GetY();
        $x = self::G;
        $this->police(OffreCharte::MEDIUM, 6, OffreCharte::RUBIS, '', 0.1);
        $this->setCellHeightRatio(1.2);
        foreach ($tetes as $i => $txt) {
            $this->MultiCell(self::COLS[$i], 8, mb_strtoupper($txt), 0, $i === 0 ? 'L' : 'R', false, 0, $x, $y, true, 0, false, true, 8, 'B');
            $x += self::COLS[$i];
        }
        $this->setFontSpacing(0);
        $this->filet(self::G, $y + 9.2, self::D, OffreCharte::RUBIS, 0.4);
        $this->SetY($y + 9.2);
    }

    private function renderProductsTable(): void
    {
        $d = $this->devise;
        $this->place(9.2 + 2 * 7.5);
        $this->enteteTableau();
        foreach ($this->offre['products'] as $p) {
            $this->police(OffreCharte::LIGHT, 8);
            $h = max(7.5, $this->hauteur(self::COLS[0] - 3, (string) $p['product_name'], 1.25) + 3.4);
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage();
                $this->enteteTableau();
                $this->police(OffreCharte::LIGHT, 8);
            }
            $y = $this->GetY();
            $this->setCellHeightRatio(1.25);
            $this->MultiCell(self::COLS[0] - 3, $h, (string) $p['product_name'], 0, 'L', false, 0, self::G, $y, true, 0, false, true, $h, 'M');
            $remise = OffreCharte::remise($p, $d);
            $vals = [
                number_format((float) $p['quantity'], 0, ',', "\u{00A0}"),
                OffreCharte::prix((float) $p['unit_price'], $d),
                OffreCharte::prix((float) $p['total_price_gross'], $d),
                $remise === '' ? '—' : $remise,
                OffreCharte::prix(OffreCharte::prixRemise($p), $d),
                OffreCharte::prix((float) $p['final_total_price_net'], $d),
                OffreCharte::prix((float) $p['final_total_price'], $d),
            ];
            $x = self::G + self::COLS[0];
            foreach ($vals as $i => $v) {
                $c = $i + 1;
                $encre = $c === 4 ? ($remise === '' ? OffreCharte::GRIS : OffreCharte::RUBIS) : OffreCharte::NOIR;
                $this->police($c === 7 ? OffreCharte::MEDIUM : OffreCharte::LIGHT, 8, $encre);
                $this->SetXY($x, $y);
                $this->Cell(self::COLS[$c], $h, $v, 0, 0, 'R', false, '', 0, false, 'T', 'M');
                $x += self::COLS[$c];
            }
            $this->filet(self::G, $y + $h, self::D, OffreCharte::TRAIT, 0.2);
            $this->SetY($y + $h);
        }

        // Le total général : HTVA, TVAC, et la TVA dessous.
        $tot = OffreCharte::totaux($this->offre);
        $this->place(14);
        $y = $this->GetY();
        $this->filet(self::G, $y, self::D, OffreCharte::RUBIS, 0.4);
        $this->police(OffreCharte::MEDIUM, 6.8, OffreCharte::RUBIS, '', 0.3);
        $this->SetXY(self::G, $y);
        $this->Cell(array_sum(array_slice(self::COLS, 0, 6)) - 3, 9, mb_strtoupper(OffreCharte::t('total_general')), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->police(OffreCharte::MEDIUM, 8.5);
        $this->SetX(self::G + array_sum(array_slice(self::COLS, 0, 6)));
        $this->Cell(self::COLS[6], 9, OffreCharte::prix($tot['htva'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->police(OffreCharte::BLACK, 9, OffreCharte::RUBIS);
        $this->Cell(self::COLS[7], 9, OffreCharte::prix($tot['tvac'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->police(OffreCharte::LIGHT, 7, OffreCharte::GRIS);
        $this->SetXY(self::G, $y + 8.2);
        $this->Cell(180, 3.5, OffreCharte::t('including_vat') . ' ' . OffreCharte::prix($tot['tva'], $d), 0, 0, 'R');
        $this->SetY($y + 8.2 + 3.5 + 9);
    }

    /** Livraison (ou retrait) et commentaire, côte à côte sous un filet rubis. */
    private function renderDeliveryAndComments(): void
    {
        $o = $this->offre;
        $avecComm = trim((string) $o['description']) !== '';
        $w = $avecComm ? 85.0 : 180.0;
        if ($o['in_store_pickup'] == 1) {
            $gauche = [
                ['etq', OffreCharte::t('in_store_pickup')],
                [OffreCharte::LIGHT, 8, OffreCharte::NOIR, OffreCharte::nomMagasin($this->magasin) . ', ' . trim($this->magasin['street'] . ' ' . $this->magasin['street_num']) . ', ' . trim($this->magasin['zip'] . ' ' . $this->magasin['city']), 2],
            ];
        } else {
            $gauche = [
                ['etq', OffreCharte::t('delivery')],
                [OffreCharte::LIGHT, 8, OffreCharte::NOIR, $o['delivery_street'] . "\n" . trim($o['delivery_zip'] . ' ' . $o['delivery_city']), 2],
                [OffreCharte::MEDIUM, 8, OffreCharte::NOIR, OffreCharte::t('shipping_cost') . '  ' . OffreCharte::prix((float) $o['delivery_cost'], $this->devise), 1.5],
            ];
        }
        if (trim((string) $o['conditions']) !== '') {
            $gauche[] = [OffreCharte::MEDIUM, 7.5, OffreCharte::NOIR, OffreCharte::t('conditions'), 2.5];
            $gauche[] = [OffreCharte::LIGHT, 8, OffreCharte::NOIR, (string) $o['conditions'], 0.4];
        }
        $droite = [['etq', OffreCharte::t('comment')], [OffreCharte::LIGHT, 8, OffreCharte::NOIR, (string) $o['description'], 2]];

        $h = max($this->pile(self::G, 0, $w, $gauche, false), $avecComm ? $this->pile(0, 0, $w, $droite, false) : 0);
        $this->place($h + 4);
        $y = $this->GetY();
        $this->filet(self::G, $y, self::G + $w, OffreCharte::RUBIS, 0.4);
        $this->pile(self::G, $y + 3, $w, $gauche);
        if ($avecComm) {
            $this->filet(self::D - $w, $y, self::D, OffreCharte::RUBIS, 0.4);
            $this->pile(self::D - $w, $y + 3, $w, $droite);
        }
        $this->SetY($y + 3 + $h);
    }
}
