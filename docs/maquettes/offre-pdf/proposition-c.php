<?php
declare(strict_types=1);

/**
 * PROPOSITION C — « CARNET D'ÉVÉNEMENT », TROIS VERSIONS PLUS SIMPLES.
 *
 * L'offre racontée depuis l'événement du client, avec moins d'effets que la
 * première version : ni ligne manuscrite, ni pointillés, ni aplat pleine
 * hauteur. Tout se range dans des rectangles aux coins arrondis.
 *
 *   C1 — le volet en cartes : à gauche une pile de cartes (le jour J en rubis,
 *        le lieu, le client, le magasin, le règlement), à droite la sélection ;
 *   C2 — une colonne : le jour J et le titre dans une grande carte beige,
 *        trois cartes (client, lieu, magasin), la sélection dans une carte,
 *        la remarque et le total côte à côte ;
 *   C3 — une carte par produit, la quantité dans une pastille blanche, le
 *        total à payer sur une bande rubis ; remarque, conditions et bon pour
 *        accord en trois cartes.
 *
 * Le trait OffreCarnet porte ce que les trois partagent : la ligne produit,
 * les totaux, le lieu, les cartes du client et du magasin, le bon pour accord.
 */
trait OffreCarnet
{
    /** « 60 × 1,20 € 1,08 € −10 % » : la quantité, le prix barré puis remisé, la remise. */
    private function detailProduit(float $x, float $y, array $p, bool $quantite = true): void
    {
        $d = $this->devise;
        $remise = OffreCharte::remise($p, $d);
        $morceaux = [];
        if ($quantite) {
            $morceaux[] = [OffreCharte::GRIS, '', number_format((float) $p['quantity'], 0, ',', "\u{00A0}") . "\u{00A0}×\u{00A0}"];
        }
        if ($remise !== '') {
            $morceaux[] = [OffreCharte::GRIS, 'D', OffreCharte::prix((float) $p['unit_price'], $d)];
            $morceaux[] = [OffreCharte::NOIR, '', '  ' . OffreCharte::prix(OffreCharte::prixRemise($p), $d) . '  '];
        } else {
            $morceaux[] = [OffreCharte::GRIS, '', OffreCharte::prix((float) $p['unit_price'], $d)];
        }
        foreach ($morceaux as [$encre, $style, $txt]) {
            $this->police(OffreCharte::LIGHT, 7.5, $encre, $style);
            $w = $this->GetStringWidth($txt);
            $this->SetXY($x, $y);
            $this->Cell($w, 3.6, $txt, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $x += $w;
        }
        if ($remise !== '') {
            $this->pastilleG($x, $y + 1.8, $remise, OffreCharte::ROSE, OffreCharte::RUBIS);
        }
    }

    /** La hauteur du nom et du détail d'un produit, sans les marges. */
    private function hauteurProduit(array $p, float $wNom): float
    {
        $this->police(OffreCharte::MEDIUM, 9);
        return $this->hauteur($wNom, (string) $p['product_name'], 1.2) + 0.8 + 3.6;
    }

    /** Le nom et le détail à gauche, le total TVAC et le HTVA à droite (sur wT), à partir de y. */
    private function produit(float $x, float $y, float $w, array $p, float $wNom, bool $quantite = true, float $wT = 30): void
    {
        $this->police(OffreCharte::MEDIUM, 9);
        $hn = $this->bloc($x, $y, $wNom, (string) $p['product_name'], 'L', 1.2);
        $this->detailProduit($x, $y + $hn + 0.8, $p, $quantite);
        $this->police(OffreCharte::MEDIUM, 9);
        $this->SetXY($x + $w - $wT, $y);
        $this->Cell($wT, 4.3, OffreCharte::prix((float) $p['final_total_price'], $this->devise), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->police(OffreCharte::LIGHT, 6.8, OffreCharte::GRIS);
        $this->SetXY($x + $w - $wT, $y + 4.6);
        $this->Cell($wT, 3, OffreCharte::t('excl_vat') . ' ' . OffreCharte::prix((float) $p['final_total_price_net'], $this->devise), 0, 0, 'R', false, '', 0, false, 'T', 'M');
    }

    /** HTVA, TVA, et la livraison quand il y en a une. */
    private function lignesTotaux(): array
    {
        $t = OffreCharte::totaux($this->offre);
        $l = [
            [OffreCharte::t('total') . ' ' . OffreCharte::t('excl_vat'), $t['htva']],
            [OffreCharte::t('vat'), $t['tva']],
        ];
        if ($t['livraison'] > 0) {
            $l[] = [OffreCharte::t('shipping_cost'), $t['livraison']];
        }
        return $l;
    }

    /** Le lieu : l'adresse de livraison et ses frais, ou le magasin du retrait. */
    private function itemsLieu(bool $etiquetteAdresse = false): array
    {
        $o = $this->offre;
        if ($o['in_store_pickup'] == 1) {
            return [
                ['etq', OffreCharte::t($etiquetteAdresse ? 'at_the_shop' : 'in_store_pickup')],
                [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, OffreCharte::nomMagasin($this->magasin), 1.4],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, trim($this->magasin['street'] . ' ' . $this->magasin['street_num']) . "\n" . trim($this->magasin['zip'] . ' ' . $this->magasin['city']), 0.6],
            ];
        }
        return [
            ['etq', OffreCharte::t($etiquetteAdresse ? 'delivery_address' : 'delivery')],
            [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, (string) $o['delivery_street'], 1.4],
            [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, trim($o['delivery_zip'] . ' ' . $o['delivery_city']) . "\n"
                . OffreCharte::t('shipping_cost') . ' ' . OffreCharte::prix((float) $o['delivery_cost'], $this->devise), 0.6],
        ];
    }

    private function itemsClient(): array
    {
        return [
            ['etq', OffreCharte::t('for')],
            [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, OffreCharte::nomClient($this->client), 1.4],
            [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, implode("\n", OffreCharte::lignesClient($this->client)), 0.6],
        ];
    }

    private function itemsMagasin(): array
    {
        return [
            ['etq', OffreCharte::t('prepared_by')],
            [OffreCharte::MEDIUM, 9, OffreCharte::NOIR, OffreCharte::nomMagasin($this->magasin), 1.4],
            [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, implode("\n", OffreCharte::lignesMagasin($this->magasin)), 0.6],
        ];
    }

    /** Trois cartes de même hauteur sur la largeur w : le client, le lieu, le magasin. */
    private function cartesParties(float $x, float $w, float $p, ?array $fond, ?array $contour): void
    {
        $g = 4.0;
        $wc = ($w - 2 * $g) / 3;
        $piles = [$this->itemsClient(), $this->itemsLieu(), $this->itemsMagasin()];
        $h = 0.0;
        foreach ($piles as $items) {
            $h = max($h, $this->pile(0, 0, $wc - 2 * $p, $items, false) + 2 * $p);
        }
        $y = $this->GetY();
        foreach ($piles as $i => $items) {
            $xc = $x + $i * ($wc + $g);
            $this->carte($xc, $y, $wc, $h, $fond, $contour);
            $this->pile($xc + $p, $y + $p, $wc - 2 * $p, $items);
        }
        $this->SetY($y + $h);
    }

    /** La remarque du client (abricot) et l'encadré du total (rubis), côte à côte, de même hauteur. */
    private function noteEtTotal(float $x, float $w, float $wT, float $p): void
    {
        $o = $this->offre;
        $lignes = $this->lignesTotaux();
        $wN = $w - $wT - 5;
        $note = trim((string) $o['description']) === '' ? null : [
            ['etq', OffreCharte::t('your_notes'), 0, OffreCharte::SUR_ABRICOT],
            [OffreCharte::LIGHT, 7.8, OffreCharte::SUR_ABRICOT, (string) $o['description'], 1.6],
        ];
        $h = max(2 * $p + count($lignes) * 4.6 + 15, $note ? $this->pile(0, 0, $wN - 2 * $p, $note, false) + 2 * $p : 0);
        $this->place($h);
        $y = $this->GetY();
        if ($note) {
            $this->carte($x, $y, $wN, $h, OffreCharte::ABRICOT);
            $this->pile($x + $p, $y + $p, $wN - 2 * $p, $note);
        }
        $xT = $x + $w - $wT;
        $this->carte($xT, $y, $wT, $h, OffreCharte::RUBIS);
        $yy = $y + $p;
        foreach ($lignes as [$lib, $v]) {
            $this->police(OffreCharte::LIGHT, 8, OffreCharte::BLANC);
            $this->SetXY($xT + $p, $yy);
            $this->Cell($wT - 2 * $p, 5, $lib, 0, 0, 'L', false, '', 0, false, 'T', 'M');
            $this->SetXY($xT + $p, $yy);
            $this->Cell($wT - 2 * $p, 5, OffreCharte::prix($v, $this->devise), 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $yy += 4.6;
        }
        $yb = $y + $h - $p;
        $this->filet($xT + $p, $yb - 13.8, $xT + $wT - $p, OffreCharte::ABRICOT, 0.3);
        $this->etiquette($xT + $p, $yb - 11.4, $wT - 2 * $p, OffreCharte::t('total_to_pay'), OffreCharte::ABRICOT, 'L', 6.6);
        $this->police(OffreCharte::BLACK, 17, OffreCharte::BLANC);
        $this->SetXY($xT + $p, $yb - 8);
        $this->Cell($wT - 2 * $p, 8, OffreCharte::prix(OffreCharte::totaux($o)['aPayer'], $this->devise), 0, 0, 'R', false, '', 0, false, 'T', 'B');
        $this->SetY($y + $h);
    }

    /** Le bon pour accord : l'étiquette, puis la date et le nom-signature à remplir (16,5 mm). */
    private function accord(float $x, float $y, float $w): void
    {
        $this->etiquette($x, $y, $w, OffreCharte::t('agreement'));
        $wDate = min(34.0, $w * 0.32);
        foreach ([[OffreCharte::t('agreement_date'), $x, $wDate], [OffreCharte::t('agreement_signature'), $x + $wDate + 6, $w - $wDate - 6]] as [$lib, $xl, $wl]) {
            $this->filet($xl, $y + 12, $xl + $wl, OffreCharte::NOIR, 0.25);
            $this->police(OffreCharte::LIGHT, 6.5, OffreCharte::GRIS);
            $this->bloc($xl, $y + 13.2, $wl, $lib);
        }
    }

    private function modeRemise(): string
    {
        return mb_strtoupper(OffreCharte::t($this->offre['in_store_pickup'] == 1 ? 'in_store_pickup' : 'delivery'));
    }

    private function conditions(): array
    {
        $txt = trim((string) $this->offre['conditions']);
        return $txt === '' ? [] : [['etq', OffreCharte::t('conditions')], [OffreCharte::LIGHT, 8, OffreCharte::NOIR, $txt, 1.8]];
    }
}

/** C1 — le volet en cartes. */
final class OffrePdfC1 extends TCPDFModel
{
    use OffreMiseEnPage, OffreCarnet;

    private const XV = 12.0, WV = 50.0;           // la pile de cartes de gauche
    private const G = 70.0, D = 198.0, L = 128.0; // la colonne de droite
    private const P = 4.5;                        // la marge intérieure des cartes

    public function __construct(array $offerData, array $shopData, string $devise)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->preparer($offerData, $shopData, $devise);
        $this->SetMargins(self::G, 20, 210 - self::D);
        $this->SetAutoPageBreak(true, 17);
    }

    public function rendre(): self
    {
        $this->AddPage();
        $this->etiquette(self::G, 16, self::L, OffreCharte::t('commercial_offer_no') . ' ' . OffreCharte::numero($this->offre)
            . '   ·   ' . OffreCharte::t('issued_on') . ' ' . date('d/m/Y', OffreCharte::maintenant()), OffreCharte::GRIS);
        $this->police(OffreCharte::VANK, 24);
        $this->SetY(21 + $this->bloc(self::G, 21, self::L, (string) $this->offre['name'], 'L', 1.05) + 9);
        $this->renderSelection();
        $this->SetY($this->GetY() + 7);
        $this->noteEtTotal(self::G, self::L, 62, self::P);
        if ($c = $this->conditions()) {
            $this->SetY($this->GetY() + 7);
            $this->place($this->pile(0, 0, self::L, $c, false));
            $this->SetY($this->GetY() + $this->pile(self::G, $this->GetY(), self::L, $c));
        }
        $h = 2 * self::P + 16.5;
        $this->SetY($this->GetY() + 7);
        $this->place($h);
        $y = max($this->GetY(), $this->getPageHeight() - $this->getBreakMargin() - $h);
        $this->carte(self::G, $y, self::L, $h, null, OffreCharte::TRAIT);
        $this->accord(self::G + self::P, $y + self::P, self::L - 2 * self::P);
        return $this;
    }

    public function Header()
    {
        $this->Image(OffreCharte::$logo, self::XV, 14, self::WV, 0, 'PNG');
        if ($this->getPage() === 1) {
            $this->renderCartes();
            return;
        }
        $this->carte(self::XV, 30, self::WV, 13, OffreCharte::BEIGE);
        $this->etiquette(self::XV + self::P, 33.4, self::WV - 2 * self::P, OffreCharte::t('commercial_offer_no') . ' ' . OffreCharte::numero($this->offre));
        $this->police(OffreCharte::LIGHT, 7.5, OffreCharte::GRIS);
        $this->bloc(self::XV + self::P, 36.8, self::WV - 2 * self::P, OffreCharte::t('continued'));
    }

    public function Footer()
    {
        $y = 297 - 11;
        $this->police(OffreCharte::LIGHT, 6, OffreCharte::GRIS);
        $this->bloc(self::G, $y, 112, OffreCharte::t('general_terms_available_upon_request') . ' '
            . OffreCharte::t('document_generated_on') . ' ' . date('d/m/Y à H:i', OffreCharte::maintenant()) . '.');
        $this->SetXY(self::G, $y);
        $this->Cell(self::L, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    /** La pile de cartes de la première page. */
    private function renderCartes(): void
    {
        $o = $this->offre;
        $x = self::XV;
        $w = self::WV;
        $p = self::P;
        $wi = $w - 2 * $p;

        // Le jour J, en rubis.
        $y = 30.0;
        $this->carte($x, $y, $w, 50, OffreCharte::RUBIS);
        $this->etiquette($x + $p, $y + $p, $wi, OffreCharte::t('the_day'), OffreCharte::ABRICOT);
        $this->police(OffreCharte::VANK, 46, OffreCharte::BLANC);
        $this->bloc($x + $p - 0.5, $y + 8.6, $wi, OffreCharte::date((string) $o['event_day'], 'd'), 'L', 1);
        $this->police(OffreCharte::VANK, 14, OffreCharte::BLANC);
        $this->bloc($x + $p, $y + 25.6, $wi, OffreCharte::date((string) $o['event_day'], 'MMMM y'), 'L', 1);
        $this->police(OffreCharte::LIGHT, 8, OffreCharte::ABRICOT);
        $this->bloc($x + $p, $y + 31.4, $wi, OffreCharte::date((string) $o['event_day'], 'EEEE'));
        $this->pastilleG($x + $p, $y + 42, $this->modeRemise(), OffreCharte::BLANC, OffreCharte::RUBIS);
        $y += 54;

        foreach ([$this->itemsLieu(true), $this->itemsClient(), $this->itemsMagasin()] as $items) {
            $h = $this->pile(0, 0, $wi, $items, false) + 2 * $p;
            $this->carte($x, $y, $w, $h, OffreCharte::BEIGE);
            $this->pile($x + $p, $y + $p, $wi, $items);
            $y += $h + 4;
        }

        if (!empty($this->magasin['bank_iban'])) {
            $items = [
                ['etq', OffreCharte::t('payment')],
                [OffreCharte::MEDIUM, 7.5, OffreCharte::NOIR, 'IBAN ' . $this->magasin['bank_iban'], 1.4],
                [OffreCharte::LIGHT, 7.5, OffreCharte::GRIS, (string) $this->magasin['bank_name'], 0.2],
            ];
            $h = $this->pile(0, 0, $wi, $items, false) + 2 * $p;
            $y = max($y, 297 - 11 - $h);
            $this->carte($x, $y, $w, $h, OffreCharte::BEIGE);
            $this->pile($x + $p, $y + $p, $wi, $items);
        }
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
        $wN = self::L - 30 - 6;
        $this->place(4.6 + 2 * 13);
        $this->enteteSelection();
        foreach ($this->offre['products'] as $p) {
            $h = $this->hauteurProduit($p, $wN) + 6;
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage();
                $this->enteteSelection();
            }
            $y = $this->GetY();
            $this->produit(self::G, $y + 3, self::L, $p, $wN);
            $this->filet(self::G, $y + $h, self::D, OffreCharte::TRAIT, 0.25);
            $this->SetY($y + $h);
        }
    }
}

/** C2 — une colonne. */
final class OffrePdfC2 extends TCPDFModel
{
    use OffreMiseEnPage, OffreCarnet;

    private const G = 15.0, D = 195.0, L = 180.0, P = 5.0;

    public function __construct(array $offerData, array $shopData, string $devise)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->preparer($offerData, $shopData, $devise);
        $this->SetMargins(self::G, 24, 210 - self::D);
        $this->SetAutoPageBreak(true, 19);
    }

    public function rendre(): self
    {
        $this->AddPage();
        $this->SetY(29);
        $this->renderJour();
        $this->SetY($this->GetY() + 4);
        $this->cartesParties(self::G, self::L, 4, null, OffreCharte::TRAIT);
        $this->SetY($this->GetY() + 4);
        $this->renderSelection();
        $this->SetY($this->GetY() + 4);
        $this->noteEtTotal(self::G, self::L, 72, 4.5);
        $this->SetY($this->GetY() + 4);
        $this->renderConditionsAccord();
        return $this;
    }

    public function Header()
    {
        $num = OffreCharte::numero($this->offre);
        if ($this->getPage() === 1) {
            $this->Image(OffreCharte::$logo, self::G, 13, 50, 0, 'PNG');
            $this->etiquette(110, 13.6, 85, OffreCharte::t('commercial_offer_no') . ' ' . $num, OffreCharte::RUBIS, 'R');
            $this->police(OffreCharte::LIGHT, 7.5, OffreCharte::GRIS);
            $this->bloc(110, 17.4, 85, OffreCharte::t('issued_on') . ' ' . date('d/m/Y', OffreCharte::maintenant()), 'R');
        } else {
            $this->Image(OffreCharte::$logo, self::G, 10, 34, 0, 'PNG');
            $this->police(OffreCharte::MEDIUM, 7.5, OffreCharte::GRIS);
            $this->bloc(110, 11.4, 85, OffreCharte::t('commercial_offer_no') . ' ' . $num . ' — ' . OffreCharte::t('continued'), 'R');
        }
    }

    public function Footer()
    {
        $y = 297 - 16;
        $this->filet(self::G, $y, self::D, OffreCharte::TRAIT, 0.3);
        $this->police(OffreCharte::LIGHT, 6.3, OffreCharte::GRIS);
        $this->bloc(self::G + 20, $y + 2.6, 140, OffreCharte::t('general_terms_available_upon_request') . ' '
            . OffreCharte::t('document_generated_on') . ' ' . date('d/m/Y à H:i', OffreCharte::maintenant()) . '.', 'C');
        $this->SetXY(self::G, $y + 2.6);
        $this->Cell(self::L, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        if (!empty($this->magasin['bank_iban'])) {
            $this->police(OffreCharte::MEDIUM, 6.8, OffreCharte::RUBIS, '', 0.15);
            $this->bloc(self::G, $y + 6.6, self::L, 'IBAN ' . $this->magasin['bank_iban'] . '  ·  ' . $this->magasin['bank_name'], 'C');
            $this->setFontSpacing(0);
        }
    }

    /** La grande carte beige : la date dans une tuile blanche, le titre, le jour et le mode. */
    private function renderJour(): void
    {
        $o = $this->offre;
        $y = $this->GetY();
        $p = 4.0;
        $t = 24.0;
        $xr = self::G + $p + $t + 6;
        $wr = self::D - $p - $xr;
        $this->police(OffreCharte::VANK, 20);
        $hT = $this->hauteur($wr, (string) $o['name'], 1.05);
        $h = max($t + 2 * $p, $p + 4.4 + $hT + 2.6 + 4.6 + $p);
        $this->carte(self::G, $y, self::L, $h, OffreCharte::BEIGE);

        $ty = $y + ($h - $t) / 2;
        $this->carte(self::G + $p, $ty, $t, $t, OffreCharte::BLANC);
        $this->police(OffreCharte::VANK, 28, OffreCharte::RUBIS);
        $this->bloc(self::G + $p, $ty + 3.4, $t, OffreCharte::date((string) $o['event_day'], 'd'), 'C', 1);
        $this->police(OffreCharte::MEDIUM, 6.3, OffreCharte::NOIR, '', 0.3);
        $this->bloc(self::G + $p, $ty + 16.4, $t, mb_strtoupper(OffreCharte::date((string) $o['event_day'], 'MMM y')), 'C');
        $this->setFontSpacing(0);

        $this->etiquette($xr, $y + $p, $wr, OffreCharte::t('the_day'));
        $this->police(OffreCharte::VANK, 20);
        $yl = $y + $p + 4.4 + $this->bloc($xr, $y + $p + 4.4, $wr, (string) $o['name'], 'L', 1.05) + 2.6;
        $jour = OffreCharte::date((string) $o['event_day']);
        $this->police(OffreCharte::LIGHT, 8.5);
        $this->SetXY($xr, $yl);
        $this->Cell($this->GetStringWidth($jour), 4.6, $jour, 0, 0, 'L', false, '', 0, false, 'T', 'M');
        $this->pastilleG($xr + $this->GetStringWidth($jour) + 3.5, $yl + 2.3, $this->modeRemise(), OffreCharte::RUBIS, OffreCharte::BLANC);
        $this->SetY($y + $h);
    }

    /** La sélection dans une carte beige ; sur une offre longue, une carte par page. */
    private function renderSelection(): void
    {
        $p = self::P;
        $wN = self::L - 2 * $p - 30 - 6;
        $produits = array_values($this->offre['products']);
        $hauteurs = array_map(fn($pr) => $this->hauteurProduit($pr, $wN) + 4, $produits);
        $n = count($produits);
        $i = 0;
        while ($i < $n) {
            $y0 = $this->GetY();
            $limite = $this->getPageHeight() - $this->getBreakMargin();
            $h = $p + 4.6;
            $j = $i;
            while ($j < $n && $y0 + $h + $hauteurs[$j] + $p - 2 <= $limite) {
                $h += $hauteurs[$j++];
            }
            if ($j === $i) {
                $this->AddPage();
                continue;
            }
            $h += $p - 2;
            $this->carte(self::G, $y0, self::L, $h, OffreCharte::BEIGE_DOUX);
            $this->etiquette(self::G + $p, $y0 + $p, 80, OffreCharte::t('your_selection'));
            $this->etiquette(self::D - $p - 50, $y0 + $p, 50, OffreCharte::t('total') . ' ' . OffreCharte::t('incl_vat'), OffreCharte::GRIS, 'R');
            $y = $y0 + $p + 4.6;
            $this->filet(self::G + $p, $y, self::D - $p, OffreCharte::TRAIT, 0.3);
            for ($k = $i; $k < $j; $k++) {
                $this->produit(self::G + $p, $y + 2, self::L - 2 * $p, $produits[$k], $wN);
                if ($k < $j - 1) {
                    $this->filet(self::G + $p, $y + $hauteurs[$k], self::D - $p, OffreCharte::TRAIT, 0.25);
                }
                $y += $hauteurs[$k];
            }
            $this->SetY($y0 + $h);
            $i = $j;
            if ($i < $n) {
                $this->AddPage();
            }
        }
    }

    /** Une carte : les conditions à gauche, le bon pour accord à droite. */
    private function renderConditionsAccord(): void
    {
        $p = 4.5;
        $wA = 70.0;
        $wC = self::L - 2 * $p - $wA - 8;
        $c = $this->conditions();
        $h = max($c ? $this->pile(0, 0, $wC, $c, false) : 0, 16.5) + 2 * $p;
        $this->place($h);
        $y = $this->GetY();
        $this->carte(self::G, $y, self::L, $h, null, OffreCharte::TRAIT);
        if ($c) {
            $this->pile(self::G + $p, $y + $p, $wC, $c);
        }
        $this->accord(self::D - $p - $wA, $y + $p, $wA);
        $this->SetY($y + $h);
    }
}

/** C3 — une carte par produit. */
final class OffrePdfC3 extends TCPDFModel
{
    use OffreMiseEnPage, OffreCarnet;

    private const G = 15.0, D = 195.0, L = 180.0, P = 5.0;
    private const WQ = 13.0, WT = 30.0;   // la pastille de quantité, la colonne des totaux

    public function __construct(array $offerData, array $shopData, string $devise)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->preparer($offerData, $shopData, $devise);
        $this->SetMargins(self::G, 24, 210 - self::D);
        $this->SetAutoPageBreak(true, 19);
    }

    public function rendre(): self
    {
        $this->AddPage();
        $this->SetY(31);
        $this->renderTitre();
        $this->cartesParties(self::G, self::L, 4, null, OffreCharte::TRAIT);
        $this->SetY($this->GetY() + 5);
        $this->renderSelection();
        $this->SetY($this->GetY() + 2.5);
        $this->renderTotal();
        $this->SetY($this->GetY() + 4);
        $this->renderBas();
        return $this;
    }

    public function Header()
    {
        $num = OffreCharte::numero($this->offre);
        $wb = 54.0;
        if ($this->getPage() === 1) {
            $this->Image(OffreCharte::$logo, self::G, 13, 50, 0, 'PNG');
            $this->carte(self::D - $wb, 11.5, $wb, 14, OffreCharte::RUBIS);
            $this->etiquette(self::D - $wb + 4.5, 14.3, $wb - 9, OffreCharte::t('commercial_offer_no') . ' ' . $num, OffreCharte::ABRICOT);
            $this->police(OffreCharte::LIGHT, 7, OffreCharte::BLANC);
            $this->bloc(self::D - $wb + 4.5, 18.6, $wb - 9, OffreCharte::t('issued_on') . ' ' . date('d/m/Y', OffreCharte::maintenant()));
        } else {
            $this->Image(OffreCharte::$logo, self::G, 10, 34, 0, 'PNG');
            $this->carte(self::D - $wb, 8.5, $wb, 9, OffreCharte::RUBIS);
            $this->etiquette(self::D - $wb, 11.4, $wb, OffreCharte::t('offer_no') . ' ' . $num . ' — ' . OffreCharte::t('continued'), OffreCharte::ABRICOT, 'C');
        }
    }

    public function Footer()
    {
        $y = 297 - 16;
        $this->filet(self::G, $y, self::D, OffreCharte::TRAIT, 0.3);
        $this->police(OffreCharte::LIGHT, 6.3, OffreCharte::GRIS);
        $this->bloc(self::G + 20, $y + 2.6, 140, OffreCharte::t('general_terms_available_upon_request') . ' '
            . OffreCharte::t('document_generated_on') . ' ' . date('d/m/Y à H:i', OffreCharte::maintenant()) . '.', 'C');
        $this->SetXY(self::G, $y + 2.6);
        $this->Cell(self::L, 3, $this->getAliasRightShift() . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        if (!empty($this->magasin['bank_iban'])) {
            $this->police(OffreCharte::MEDIUM, 6.8, OffreCharte::RUBIS, '', 0.15);
            $this->bloc(self::G, $y + 6.6, self::L, 'IBAN ' . $this->magasin['bank_iban'] . '  ·  ' . $this->magasin['bank_name'], 'C');
            $this->setFontSpacing(0);
        }
    }

    /** Le titre, puis la date et le mode en pastilles. */
    private function renderTitre(): void
    {
        $y = $this->GetY();
        $this->police(OffreCharte::VANK, 24);
        $y += $this->bloc(self::G, $y, self::L, (string) $this->offre['name'], 'L', 1.05) + 2.6;
        $w = $this->pastilleG(self::G, $y + 2.3, mb_strtoupper(OffreCharte::date((string) $this->offre['event_day'])), OffreCharte::ABRICOT, OffreCharte::SUR_ABRICOT, 7);
        $this->pastilleG(self::G + $w + 2.5, $y + 2.3, $this->modeRemise(), OffreCharte::RUBIS, OffreCharte::BLANC, 7);
        $this->SetY($y + 4.6 + 5);
    }

    private function enteteSelection(): void
    {
        $y = $this->GetY();
        $this->etiquette(self::G, $y, 80, OffreCharte::t('your_selection'));
        $this->etiquette(self::D - 4 - 50, $y, 50, OffreCharte::t('total') . ' ' . OffreCharte::t('incl_vat'), OffreCharte::GRIS, 'R');
        $this->SetY($y + 5);
    }

    private function renderSelection(): void
    {
        $xN = self::G + 4 + self::WQ + 4;
        $wN = self::D - 4 - $xN - self::WT - 6;
        $this->place(5 + 2 * 14);
        $this->enteteSelection();
        foreach ($this->offre['products'] as $p) {
            $h = max(11.5, $this->hauteurProduit($p, $wN) + 4.4);
            if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
                $this->AddPage();
                $this->enteteSelection();
            }
            $y = $this->GetY();
            $this->carte(self::G, $y, self::L, $h, OffreCharte::BEIGE_DOUX, null, 2.5);
            $this->carte(self::G + 4, $y + ($h - 8) / 2, self::WQ, 8, OffreCharte::BLANC, null, 2);
            $this->police(OffreCharte::MEDIUM, 8.5, OffreCharte::RUBIS);
            $this->SetXY(self::G + 4, $y + ($h - 8) / 2);
            $this->Cell(self::WQ, 8, number_format((float) $p['quantity'], 0, ',', "\u{00A0}"), 0, 0, 'C', false, '', 0, false, 'T', 'M');
            $this->produit($xN, $y + ($h - $this->hauteurProduit($p, $wN)) / 2, self::D - 4 - $xN, $p, $wN, false, self::WT);
            $this->SetY($y + $h + 1.8);
        }
    }

    /** Le total à payer sur une bande rubis : le détail à gauche, le montant à droite. */
    private function renderTotal(): void
    {
        $h = 17.5;
        $this->place($h);
        $y = $this->GetY();
        $this->carte(self::G, $y, self::L, $h, OffreCharte::RUBIS);
        $this->etiquette(self::G + self::P, $y + 4, 100, OffreCharte::t('total_to_pay'), OffreCharte::ABRICOT, 'L', 6.8);
        $detail = implode('   ·   ', array_map(fn($l) => $l[0] . ' ' . OffreCharte::prix($l[1], $this->devise), $this->lignesTotaux()));
        $this->police(OffreCharte::LIGHT, 7.3, OffreCharte::BLANC);
        $this->bloc(self::G + self::P, $y + 8.8, 110, $detail);
        $this->police(OffreCharte::BLACK, 20, OffreCharte::BLANC);
        $this->SetXY(self::D - self::P - 70, $y);
        $this->Cell(70, $h, OffreCharte::prix(OffreCharte::totaux($this->offre)['aPayer'], $this->devise), 0, 0, 'R', false, '', 0, false, 'T', 'M');
        $this->SetY($y + $h);
    }

    /** Trois cartes de même hauteur : la remarque du client (abricot), les conditions, le bon pour accord. */
    private function renderBas(): void
    {
        $p = 4.5;
        $desc = trim((string) $this->offre['description']);
        $cartes = [];
        if ($desc !== '') {
            $cartes[] = [[['etq', OffreCharte::t('your_notes'), 0, OffreCharte::SUR_ABRICOT], [OffreCharte::LIGHT, 7.5, OffreCharte::SUR_ABRICOT, $desc, 1.6]], OffreCharte::ABRICOT, null];
        }
        if ($c = $this->conditions()) {
            $c[1][1] = 7.5;
            $cartes[] = [$c, null, OffreCharte::TRAIT];
        }
        $cartes[] = [null, null, OffreCharte::TRAIT];   // le bon pour accord
        $w = (self::L - 4 * (count($cartes) - 1)) / count($cartes);
        $h = 16.5 + 2 * $p;
        foreach ($cartes as [$items]) {
            if ($items) {
                $h = max($h, $this->pile(0, 0, $w - 2 * $p, $items, false) + 2 * $p);
            }
        }
        $this->place($h);
        $y = $this->GetY();
        foreach ($cartes as $i => [$items, $fond, $contour]) {
            $x = self::G + $i * ($w + 4);
            $this->carte($x, $y, $w, $h, $fond, $contour);
            if ($items) {
                $this->pile($x + $p, $y + $p, $w - 2 * $p, $items);
            } else {
                $this->accord($x + $p, $y + $p, $w - 2 * $p);
            }
        }
        $this->SetY($y + $h);
    }
}
