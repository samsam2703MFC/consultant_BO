<?php
declare(strict_types=1);

/**
 * LE DEVIS AUX COULEURS DE L'ATELIER BY — ce que les trois propositions
 * partagent : la charte traduite pour TCPDF (couleurs, polices, formats),
 * les libellés, et quelques outils de mise en page.
 *
 * TCPDF compte en millimètres et en points : chaque cote de ce dossier se
 * reporte telle quelle dans OfferService. Les classes OffrePdfA/B/C étendent
 * TCPDFModel comme le service d'aujourd'hui, et dessinent leur fond et leur
 * pied dans Header()/Footer() : une offre longue passe à la page suivante
 * avec son décor, et le tableau y reprend son en-tête.
 */

final class OffreCharte
{
    // La palette de public/assets/ds/global.css.
    public const RUBIS       = [141, 29, 44];   // --color-primary   #8D1D2C
    public const ABRICOT     = [242, 201, 160]; // --color-secondary #F2C9A0
    public const BEIGE       = [234, 228, 220]; // --color-bg        #EAE4DC
    public const BEIGE_DOUX  = [244, 239, 232]; // --color-background-secondary #F4EFE8
    public const NOIR        = [34, 34, 34];    // --color-text      #222222
    public const GRIS        = [102, 102, 102]; // --color-text-muted #666666
    public const SUR_ABRICOT = [107, 68, 32];   // --color-on-abricot #6b4420
    public const TRAIT       = [221, 214, 205]; // filet chaud ≈ rgba(34,34,34,.12)
    public const ROSE        = [244, 226, 228]; // rubis à 12 % : fond des pastilles de remise
    public const BLANC       = [255, 255, 255];

    // Gotham n'existe qu'en OpenType CFF, que TCPDF ne lit pas : polices/ en porte
    // les TrueType convertis. Light, Medium et Black sont les seules graisses
    // livrées qui portent le « € ».
    public const LIGHT = 'gothamlight', MEDIUM = 'gothammedium', BLACK = 'gothamblack',
        VANK = 'gc_vank', SCRIPT = 'playwritedevalight';
    public const POLICES = [
        self::LIGHT  => 'polices/Gotham-Light.ttf',
        self::MEDIUM => 'polices/Gotham-Medium.ttf',
        self::BLACK  => 'polices/Gotham-Black.ttf',
        self::VANK   => '../../../public/assets/ds/fonts/GC_Vank.ttf',
        self::SCRIPT => '../../../public/assets/ds/fonts/PlaywriteDEVA-Light.ttf',
    ];

    /** Dossier des définitions TCPDF des polices (K_PATH_FONTS en production). */
    public static string $dossierPolices = '';
    /** Logo sur fond clair et logo blanc (fond rubis), PNG transparents. */
    public static string $logo = '';
    public static string $logoBlanc = '';
    /** Horodatage du document — figé pour les maquettes, l'heure courante sinon. */
    public static ?int $maintenant = null;

    /** Déclare les cinq polices au document (les convertit au premier passage). */
    public static function polices(TCPDF $pdf): void
    {
        if (!is_dir(self::$dossierPolices)) {
            mkdir(self::$dossierPolices, 0775, true);
        }
        foreach (self::POLICES as $nom => $fichier) {
            $def = self::$dossierPolices . $nom . '.php';
            if (!is_file($def)) {
                TCPDF_FONTS::addTTFfont(__DIR__ . '/' . $fichier, 'TrueTypeUnicode', '', 32, self::$dossierPolices);
            }
            $pdf->AddFont($nom, '', $def);
        }
    }

    public static function t(string $cle): string
    {
        return getTranslation('offer', $cle, Language::getCallLanguageCode());
    }

    public static function maintenant(): int
    {
        return self::$maintenant ?? time();
    }

    /** 1 234,56 € — espaces insécables, la virgule décimale. */
    public static function prix(float $v, string $devise): string
    {
        return number_format($v, 2, ',', "\u{00A0}") . "\u{00A0}" . $devise;
    }

    /** −10 %, −6,00 €, ou rien quand la ligne n'a pas de remise. */
    public static function remise(array $p, string $devise): string
    {
        $v = (float) $p['discount_value'];
        if ($v == 0.0) {
            return '';
        }
        if ((int) $p['id_discount_type'] === 1) {
            return '−' . rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . "\u{00A0}%";
        }
        return '−' . self::prix($v, $devise);
    }

    /** Le prix unitaire une fois la remise passée. */
    public static function prixRemise(array $p): float
    {
        return (float) $p['final_total_price'] / max(1, (float) $p['quantity']);
    }

    /** « jeudi 12 novembre 2026 », ou un morceau (« d », « MMMM y », « EEEE ») dans la langue de l'appel. */
    public static function date(string $ymd, string $motif = 'EEEE d MMMM y'): string
    {
        $ts = strtotime($ymd);
        if (!class_exists(IntlDateFormatter::class)) {
            return date('d/m/Y', $ts);
        }
        $locale = ['fr' => 'fr_BE', 'nl' => 'nl_BE', 'en' => 'en_GB', 'pl' => 'pl_PL'][Language::getCallLanguageCode()] ?? 'fr_BE';
        $f = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Brussels', null, $motif);
        return (string) $f->format($ts);
    }

    public static function numero(array $offre): string
    {
        return str_pad((string) $offre['id'], 4, '0', STR_PAD_LEFT);
    }

    public static function nomClient(array $c): string
    {
        return $c['is_b2b'] ? (string) $c['company_name'] : trim($c['firstname'] . ' ' . $c['lastname']);
    }

    /** Les lignes d'adresse et de contact du client, sans son nom. */
    public static function lignesClient(array $c): array
    {
        $l = [];
        if ($c['is_b2b'] && trim(($c['firstname'] ?? '') . ($c['lastname'] ?? '')) !== '') {
            $l[] = self::t('attention_of') . ' ' . trim($c['firstname'] . ' ' . $c['lastname']);
        }
        $l[] = trim($c['street'] . ' ' . $c['street_number']);
        $l[] = trim($c['zip'] . ' ' . $c['city']);
        if ($c['is_b2b'] && !empty($c['tax_number'])) {
            $l[] = self::t('tax_number_short') . ' ' . $c['tax_number'];
        }
        if (!empty($c['phone'])) {
            $l[] = self::t('phone_short') . ' ' . $c['phone'];
        }
        if (!empty($c['email'])) {
            $l[] = $c['email'];
        }
        return $l;
    }

    /** Le nom commercial du magasin (« Atelier by - Halle »), à défaut sa raison sociale. */
    public static function nomMagasin(array $s): string
    {
        return (string) ($s['name'] ?? $s['representative_name']);
    }

    /** Raison sociale, adresse, TVA, téléphone du magasin. */
    public static function lignesMagasin(array $s): array
    {
        $l = [];
        if (!empty($s['name']) && $s['name'] !== $s['representative_name']) {
            $l[] = $s['representative_name'];
        }
        $l[] = trim($s['street'] . ' ' . $s['street_num']);
        $l[] = trim($s['zip'] . ' ' . $s['city']);
        $l[] = self::t('tax_number_short') . ' ' . $s['tax_number_prefixe'] . $s['tax_number'];
        if (!empty($s['phone'])) {
            $l[] = self::t('phone_short') . ' ' . $s['phone'];
        }
        return $l;
    }

    /** Les totaux de l'offre : HTVA, TVA, TVAC, livraison, à payer. */
    public static function totaux(array $offre): array
    {
        $tvac = $htva = 0.0;
        foreach ($offre['products'] as $p) {
            $tvac += (float) $p['final_total_price'];
            $htva += (float) $p['final_total_price_net'];
        }
        $livraison = $offre['in_store_pickup'] == 1 ? 0.0 : (float) $offre['delivery_cost'];
        return ['htva' => $htva, 'tva' => $tvac - $htva, 'tvac' => $tvac, 'livraison' => $livraison, 'aPayer' => $tvac + $livraison];
    }
}

/** Les outils de mise en page communs aux trois documents (des TCPDF). */
trait OffreMiseEnPage
{
    private array $offre;
    private array $magasin;
    private array $client;
    private string $devise;

    private function preparer(array $offerData, array $shopData, string $devise): void
    {
        $this->offre = $offerData;
        $this->magasin = $shopData;
        $this->client = $offerData['client'];
        $this->devise = $devise;
        OffreCharte::polices($this);
        $this->setCellPaddings(0, 0, 0, 0);
        $this->setCellMargins(0, 0, 0, 0);
        $this->setHeaderMargin(0);
        $this->setFooterMargin(0);
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetCreator("L'Atelier by");
        $this->SetTitle(OffreCharte::t('commercial_offer_no') . ' ' . OffreCharte::numero($offerData));
    }

    private function police(string $nom, float $taille, array $couleur = OffreCharte::NOIR, string $style = '', float $espace = 0): void
    {
        $this->SetFont($nom, $style, $taille);
        $this->SetTextColorArray($couleur);
        $this->setFontSpacing($espace);
    }

    /** Écrit un paragraphe en (x, y) sur la largeur w ; rend la hauteur occupée. */
    private function bloc(float $x, float $y, float $w, string $txt, string $align = 'L', float $interligne = 1.35): float
    {
        $this->setCellHeightRatio($interligne);
        $this->MultiCell($w, 0, $txt, 0, $align, false, 1, $x, $y, true, 0, false, true, 0, 'T', false);
        return $this->GetY() - $y;
    }

    /** La hauteur qu'occuperait bloc() avec la police courante. */
    private function hauteur(float $w, string $txt, float $interligne = 1.35): float
    {
        $this->setCellHeightRatio($interligne);
        return $this->getStringHeight($w, $txt);
    }

    /** Une petite capitale espacée : « DE », « POUR », « LIVRAISON »… */
    private function etiquette(float $x, float $y, float $w, string $txt, array $couleur = OffreCharte::RUBIS, string $align = 'L', float $taille = 6.3): float
    {
        $this->police(OffreCharte::MEDIUM, $taille, $couleur, '', 0.35);
        $h = $this->bloc($x, $y, $w, mb_strtoupper($txt), $align, 1.2);
        $this->setFontSpacing(0);
        return $h;
    }

    /**
     * Une pile de paragraphes : ['etq', texte, espace avant, couleur] ou
     * [police, taille, couleur, texte, espace avant]. Rend la hauteur ; ne
     * dessine rien quand $dessiner est faux (mesurer avant de placer).
     */
    private function pile(float $x, float $y, float $w, array $items, bool $dessiner = true): float
    {
        $h = 0.0;
        foreach ($items as $it) {
            if ($it[0] === 'etq') {
                $h += $it[2] ?? 0;
                $this->police(OffreCharte::MEDIUM, 6.3, $it[3] ?? OffreCharte::RUBIS, '', 0.35);
                $txt = mb_strtoupper($it[1]);
                $h += $dessiner ? $this->bloc($x, $y + $h, $w, $txt, 'L', 1.2) : $this->hauteur($w, $txt, 1.2);
                $this->setFontSpacing(0);
            } else {
                $h += $it[4] ?? 0;
                $this->police($it[0], $it[1], $it[2]);
                $h += $dessiner ? $this->bloc($x, $y + $h, $w, $it[3]) : $this->hauteur($w, $it[3]);
            }
        }
        return $h;
    }

    private function filet(float $x1, float $y, float $x2, array $couleur, float $epaisseur = 0.2, $tirets = 0): void
    {
        $this->Line($x1, $y, $x2, $y, ['width' => $epaisseur, 'color' => $couleur, 'dash' => $tirets, 'cap' => 'butt']);
    }

    /** Une pastille arrondie (remise, mode de remise) ; rend sa largeur. */
    private function pastille(float $xDroite, float $yMilieu, string $txt, array $fond, array $encre, float $taille = 6.3): float
    {
        $this->police(OffreCharte::MEDIUM, $taille, $encre, '', 0.2);
        $w = $this->GetStringWidth($txt) + 3.6;
        $h = $taille * 0.3528 + 1.9;
        $this->RoundedRect($xDroite - $w, $yMilieu - $h / 2, $w, $h, $h / 2, '1111', 'F', [], $fond);
        $this->SetXY($xDroite - $w, $yMilieu - $h / 2);
        $this->Cell($w, $h, $txt, 0, 0, 'C', false, '', 0, false, 'T', 'M');
        $this->setFontSpacing(0);
        return $w;
    }

    /** Reste-t-il h mm avant le pied de page ? Sinon, page suivante. */
    private function place(float $h): void
    {
        if ($this->GetY() + $h > $this->getPageHeight() - $this->getBreakMargin()) {
            $this->AddPage();
        }
    }

    /** Le prix unitaire : barré puis remisé quand la ligne a une remise, aligné à droite sur xDroite. */
    private function prixUnitaire(float $xDroite, float $y, float $h, array $p, float $taille, array $encre = OffreCharte::NOIR): void
    {
        $remise = OffreCharte::remise($p, $this->devise) !== '';
        $net = OffreCharte::prix($remise ? OffreCharte::prixRemise($p) : (float) $p['unit_price'], $this->devise);
        $this->police(OffreCharte::LIGHT, $taille, $encre);
        $w = $this->GetStringWidth($net);
        $this->SetXY($xDroite - $w, $y);
        $this->Cell($w, $h, $net, 0, 0, 'R', false, '', 0, false, 'T', 'M');
        if ($remise) {
            $brut = OffreCharte::prix((float) $p['unit_price'], $this->devise);
            $this->police(OffreCharte::LIGHT, $taille - 1.3, OffreCharte::GRIS, 'D');
            $wb = $this->GetStringWidth($brut);
            $this->SetXY($xDroite - $w - 1.6 - $wb, $y);
            $this->Cell($wb, $h, $brut, 0, 0, 'R', false, '', 0, false, 'T', 'M');
            $this->SetFont(OffreCharte::LIGHT, '', $taille);
        }
    }
}
