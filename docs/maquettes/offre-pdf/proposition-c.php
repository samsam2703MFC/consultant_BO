<?php
declare(strict_types=1);

/**
 * PROPOSITION C — « CARNET D'ÉVÉNEMENT ».
 *
 * L'offre racontée depuis l'événement du client. Un volet beige à gauche
 * tient tout ce qui ne change pas en lisant : le jour J en grand, la
 * livraison ou le retrait, le client, le magasin, le règlement. La colonne
 * de droite se lit comme une carte : le titre en Vank sous une ligne
 * manuscrite, une ligne par produit (quantité × prix, remise en pastille,
 * total TVAC et HTVA dessous), les totaux, la remarque du client sur un
 * papier abricot, les conditions, le bon pour accord.
 */
final class OffrePdfC extends TCPDFModel
{
    use OffreMiseEnPage;

    private const VOLET = 64.0;            // largeur du volet beige
    private const XV = 10.0, WV = 44.0;    // le texte du volet
    private const G = 74.0, D = 195.0, L = 121.0;
    private const WT = 30.0;               // la colonne des totaux de ligne

    public function __construct(array $offerData, array $shopData, string $devise)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->preparer($offerData, $shopData, $devise);
        $this->SetMargins(self::G, 18, 210 - self::D);
        $this->SetAutoPageBreak(true, 20);
    }

    public function rendre(): self
    {
        $this->AddPage();
        $this->renderTitre();
        $this->renderSelection();
        $this->renderTotaux();
        $this->renderConditions();
        $this->renderAccord();
        return $this;
    }

    public function Header()
    {
        $this->Rect(0, 0, self::VOLET, 297, 'F', [], OffreCharte::BEIGE);
        if ($this->getPage() === 1) {
            $this->renderVolet();
        } else {
            $this->Image(OffreCharte::$logo, self::XV, 16, self::WV, 0, 'PNG');
            $this->etiquette(self::XV, 30, self::WV, OffreCharte::t('commercial_offer_no') . ' ' . OffreCharte::numero($this->offre));
            $this->police(OffreCharte::LIGHT, 7.5, OffreCharte::GRIS);
            $this->bloc(self::XV, 33.5, self::WV, OffreCharte::t('continued'));
        }
    }

    public function Footer()
    {
        $y = 297 - 12;
        $this->police(OffreCharte::LIGHT, 6, OffreCharte::GRIS);
        $this->bloc(self::G, $y, 112, OffreCharte::t('general_terms_available_upon_request') . ' '
            . OffreCharte::t('document_generated_on') . ' ' . date('d/m/Y à H:i', OffreCharte::maintenant()) . '.');
        $this->SetXY(self::G, $y);
        $this->Cell(self::L, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    /** Le volet de la première page : jour J, livraison, client, magasin, règlement. */
    private function renderVolet(): void
    {
        $o = $this->offre;
        $x = self::XV;
        $w = self::WV;
        $this->Image(OffreCharte::$logo, $x, 16, $w, 0, 'PNG');

        $this->etiquette($x, 36, $w, OffreCharte::t('the_day'));
        $this->police(OffreCharte::VANK, 54, OffreCharte::RUBIS);
        $this->bloc($x - 0.6, 40.2, $w, OffreCharte::date((string) $o['event_day'], 'd'), 'L', 1);
        $this->police(OffreCharte::VANK, 15);
        $this->bloc($x, 59.4, $w, OffreCharte::date((string) $o['event_day'], 'MMMM y'), 'L', 1);
        $this->police(OffreCharte::LIGHT, 8.5, OffreCharte::GRIS);
        $this->bloc($x, 65.6, $w, OffreCharte::date((string) $o['event_day'], 'EEEE'));

        $mode = mb_strtoupper(OffreCharte::t($o['in_store_pickup'] == 1 ? 'in_store_pickup' : 'delivery'));
        $this->police(OffreCharte::MEDIUM, 6.3, OffreCharte::BLANC, '', 0.2);
        $this->pastille($x + $this->GetStringWidth($mode) + 3.6, 75, $mode, OffreCharte::RUBIS, OffreCharte::BLANC);
        $lieu = $o['in_store_pickup'] == 1
            ? [
                [OffreCharte::LIGHT, 8, OffreCharte::NOIR, OffreCharte::t('at_the_shop') . "\n" . trim($this->magasin['street'] . ' ' . $this->magasin['street_num']) . "\n" . trim($this->magasin['zip'] . ' ' . $this->magasin['city'])],
            ]
            : [
                [OffreCharte::LIGHT, 8, OffreCharte::NOIR, $o['delivery_street'] . "\n" . trim($o['delivery_zip'] . ' ' . $o['delivery_city'])],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, OffreCharte::t('shipping_cost') . ' ' . OffreCharte::prix((float) $o['delivery_cost'], $this->devise), 1],
            ];
        $y = 80 + $this->pile($x, 80, $w, $lieu);

        foreach ([
            [OffreCharte::t('for'), OffreCharte::nomClient($this->client), OffreCharte::lignesClient($this->client)],
            [OffreCharte::t('prepared_by'), OffreCharte::nomMagasin($this->magasin), OffreCharte::lignesMagasin($this->magasin)],
        ] as [$etq, $nom, $lignes]) {
            $y += 7;
            $this->Rect($x, $y, 8, 0.6, 'F', [], OffreCharte::RUBIS);
            $y += 3.6 + $this->pile($x, $y + 3.6, $w, [
                ['etq', $etq],
                [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, $nom, 1.4],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, implode("\n", $lignes), 0.6],
            ]);
        }

        if (!empty($this->magasin['bank_iban'])) {
            $y = max($y + 7, 262.0);
            $this->Rect($x, $y, 8, 0.6, 'F', [], OffreCharte::RUBIS);
            $this->pile($x, $y + 3.6, $w, [
                ['etq', OffreCharte::t('payment')],
                [OffreCharte::MEDIUM, 7.5, OffreCharte::NOIR, 'IBAN ' . $this->magasin['bank_iban'], 1.4],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, (string) $this->magasin['bank_name'], 0.2],
            ]);
        }
    }

    private function renderTitre(): void
    {
        $this->etiquette(self::G, 16, self::L, OffreCharte::t('commercial_offer_no') . ' ' . OffreCharte::numero($this->offre)
            . '   ·   ' . OffreCharte::t('issued_on') . ' ' . date('d/m/Y', OffreCharte::maintenant()), OffreCharte::GRIS);
        $this->police(OffreCharte::SCRIPT, 12, OffreCharte::RUBIS);
        $y = 22.5 + $this->bloc(self::G, 22.5, self::L, OffreCharte::t('prepared_for_you'), 'L', 1.3);
        $this->police(OffreCharte::VANK, 25);
        $y += 1 + $this->bloc(self::G, $y + 1, self::L, (string) $this->offre['name'], 'L', 1.05);
        $this->Rect(self::G, $y + 3, 22, 1.3, 'F', [], OffreCharte::ABRICOT);
        $this->SetY($y + 13);
    }

    private function enteteSelection(): void
    {
        $y = $this->GetY();
        $this->etiquette(self::G, $y, 80, OffreCharte::t('your_selection'));
        $this->etiquette(self::D - 50, $y, 50, OffreCharte::t('total') . ' ' . OffreCharte::t('incl_vat'), OffreCharte::GRIS, 'R');
        $this->filet(self::G, $y + 4.6, self::D, OffreCharte::NOIR, 0.3);
        $this->SetY($y + 4.6);
    }

    private function renderSelection(): void
    {
        $d = $this->devise;
        $wn = self::L - self::WT - 6;
        $this->place(4.6 + 2 * 13);
        $this->enteteSelection();
        foreach ($this->offre['products'] as $p) {
            $this->police(OffreCharte::MEDIUM, 9);
            $hn = $this->hauteur($wn, (string) $p['product_name'], 1.2);
            $h = 3.2 + $hn + 0.8 + 3.6 + 3.2;
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage();
                $this->enteteSelection();
                $this->police(OffreCharte::MEDIUM, 9);
            }
            $y = $this->GetY();
            $this->bloc(self::G, $y + 3.2, $wn, (string) $p['product_name'], 'L', 1.2);

            // 60 × 1,20 € 1,08 €  −10 %
            $yd = $y + 3.2 + $hn + 0.8;
            $remise = OffreCharte::remise($p, $d);
            $morceaux = [[OffreCharte::LIGHT, '', OffreCharte::GRIS, number_format((float) $p['quantity'], 0, ',', "\u{00A0}") . "\u{00A0}×\u{00A0}"]];
            if ($remise !== '') {
                $morceaux[] = [OffreCharte::LIGHT, 'D', OffreCharte::GRIS, OffreCharte::prix((float) $p['unit_price'], $d)];
                $morceaux[] = [OffreCharte::LIGHT, '', OffreCharte::NOIR, '  ' . OffreCharte::prix(OffreCharte::prixRemise($p), $d) . '  '];
            } else {
                $morceaux[] = [OffreCharte::LIGHT, '', OffreCharte::GRIS, OffreCharte::prix((float) $p['unit_price'], $d)];
            }
            $x = self::G;
            foreach ($morceaux as [$police, $style, $encre, $txt]) {
                $this->police($police, 7.5, $encre, $style);
                $w = $this->GetStringWidth($txt);
                $this->SetXY($x, $yd);
                $this->Cell($w, 3.6, $txt, 0, 0, 'L', false, '', 0, false, 'T', 'M');
                $x += $w;
            }
            if ($remise !== '') {
                $this->police(OffreCharte::MEDIUM, 6.3, OffreCharte::RUBIS, '', 0.2);
                $this->pastille($x + $this->GetStringWidth($remise) + 3.6, $yd + 1.8, $remise, OffreCharte::ROSE, OffreCharte::RUBIS);
            }

            $this->police(OffreCharte::MEDIUM, 9);
            $this->SetXY(self::D - self::WT, $y + 3.2);
            $this->Cell(self::WT, 4.4, OffreCharte::prix((float) $p['final_total_price'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $this->police(OffreCharte::LIGHT, 6.8, OffreCharte::GRIS);
            $this->SetXY(self::D - self::WT, $y + 7.8);
            $this->Cell(self::WT, 3, OffreCharte::t('excl_vat') . ' ' . OffreCharte::prix((float) $p['final_total_price_net'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');

            $this->filet(self::G, $y + $h, self::D, OffreCharte::TRAIT, 0.3, '0.6,0.9');
            $this->SetY($y + $h);
        }
    }

    /** La remarque du client sur un papier abricot ; les totaux à droite. */
    private function renderTotaux(): void
    {
        $o = $this->offre;
        $d = $this->devise;
        $tot = OffreCharte::totaux($o);
        $wR = 60.0;
        $xR = self::D - $wR;
        $wN = self::L - $wR - 8;
        $lignes = [
            [OffreCharte::t('total') . ' ' . OffreCharte::t('excl_vat'), $tot['htva']],
            [OffreCharte::t('vat'), $tot['tva']],
        ];
        if ($tot['livraison'] > 0) {
            $lignes[] = [OffreCharte::t('shipping_cost'), $tot['livraison']];
        }
        $hR = count($lignes) * 5 + 3 + 4.5 + 9;
        $note = trim((string) $o['description']) === '' ? null : [
            [OffreCharte::SCRIPT, 10, OffreCharte::SUR_ABRICOT, OffreCharte::t('your_notes')],
            [OffreCharte::LIGHT, 7.8, OffreCharte::SUR_ABRICOT, (string) $o['description'], 1.6],
        ];
        $hN = $note ? $this->pile(0, 0, $wN - 9, $note, false) + 9 : 0;
        $this->SetY($this->GetY() + 6);
        $this->place(max($hR, $hN));
        $y = $this->GetY();

        if ($note) {
            $this->Rect(self::G, $y, $wN, $hN, 'F', [], OffreCharte::ABRICOT);
            $this->pile(self::G + 4.5, $y + 3.6, $wN - 9, $note);
        }
        $yy = $y + 1;
        foreach ($lignes as [$lib, $v]) {
            $this->police(OffreCharte::LIGHT, 8);
            $this->SetXY($xR, $yy);
            $this->Cell($wR, 5, $lib, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $this->SetXY($xR, $yy);
            $this->Cell($wR, 5, OffreCharte::prix($v, $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $yy += 5;
        }
        $this->filet($xR, $yy + 1.5, self::D, OffreCharte::RUBIS, 0.45);
        $this->etiquette($xR, $yy + 4, $wR, OffreCharte::t('total_to_pay'), OffreCharte::RUBIS, 'L', 6.8);
        $this->police(OffreCharte::BLACK, 19, OffreCharte::RUBIS);
        $this->SetXY($xR, $yy + 7);
        $this->Cell($wR, 9, OffreCharte::prix($tot['aPayer'], $d), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->SetY($y + max($hR, $hN) + 8);
    }

    private function renderConditions(): void
    {
        $txt = trim((string) $this->offre['conditions']);
        if ($txt === '') {
            return;
        }
        $items = [['etq', OffreCharte::t('conditions')], [OffreCharte::LIGHT, 8, OffreCharte::NOIR, $txt, 1.8]];
        $this->place($this->pile(0, 0, self::L, $items, false));
        $this->SetY($this->GetY() + $this->pile(self::G, $this->GetY(), self::L, $items) + 8);
    }

    /** Le bon pour accord, posé en bas de la dernière page. */
    private function renderAccord(): void
    {
        $h = 21.0;
        $this->place($h);
        $y = max($this->GetY(), $this->getPageHeight() - $this->getBreakMargin() - $h);
        $this->etiquette(self::G, $y, self::L, OffreCharte::t('agreement'));
        $champs = [[OffreCharte::t('agreement_date'), 34.0], [OffreCharte::t('agreement_signature'), self::L - 34 - 8]];
        $x = self::G;
        foreach ($champs as [$lib, $w]) {
            $this->filet($x, $y + 15, $x + $w, OffreCharte::NOIR, 0.25);
            $this->police(OffreCharte::LIGHT, 6.5, OffreCharte::GRIS);
            $this->bloc($x, $y + 16.2, $w, $lib);
            $x += $w + 8;
        }
    }
}
