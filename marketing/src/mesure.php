<?php
declare(strict_types=1);

/**
 * Cockpit CEO — mesure de l'impact d'une campagne commerciale.
 *
 * Le principe, et il tient en une phrase : un « avant / après » brut ne prouve
 * RIEN. La rentrée, la météo, l'inflation font monter le chiffre toutes seules.
 * Trois garde-fous rendent la mesure honnête, et ce fichier ne calcule que ça :
 *
 *  1. une fenêtre de référence de MÊME composition de jours (semaines pleines
 *     avant le lancement) — pas « le mois d'avant » ;
 *  2. un TÉMOIN : les magasins hors campagne. L'effet publiable est
 *     `variation des magasins en campagne − variation du témoin` ;
 *  3. un PLACEBO : la même mesure appliquée à la période qui précède la
 *     référence, sur les mêmes magasins. Elle donne la variation « habituelle »
 *     — le bruit — au-dessus duquel un écart commence à vouloir dire quelque
 *     chose. Sans lui, +2 % passerait pour un succès.
 *
 * Tout est ramené au JOUR OUVERT et en POURCENTAGE de la propre base du
 * magasin : +3 000 € à Halle et +3 000 € à Sombreffe ne racontent pas la même
 * histoire, et un magasin fermé le lundi n'est pas un magasin en baisse.
 *
 * Ce fichier ne garde que la mesure (et le KPI d'une période pour l'assistant). Ce qui n'était pas du marketing en
 * est sorti le 21/11/2026, à l'identique : les réclamations matière (panel/src/reclamations_magasin.php), le
 * classement des tâches (consultant/src/taches_classement.php), le profil de semaine (consultant/src/profil_jour.php),
 * la sonde des routes consultant (consultant/src/sonde_consultant.php) et magasinNom (shared/src/magasins.php).
 */

/** Les trois tables de la mesure. Créées à la volée, comme le reste du cockpit. */
function ensureMesureTables(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS mar_campagne_mesure ('
        . 'campagne_id INT NOT NULL,'
        . 'ref_debut DATE NULL,'
        . 'ref_fin DATE NULL,'
        . 'remanence_jours INT NOT NULL DEFAULT 14,'
        . 'temoins VARCHAR(160) NULL,'
        . 'n1 TINYINT(1) NOT NULL DEFAULT 1,'
        . 'cible_trafic DECIMAL(6,2) NULL,'
        . 'cible_panier DECIMAL(6,2) NULL,'
        . 'cible_ca DECIMAL(6,2) NULL,'
        . 'cible_promo DECIMAL(6,2) NULL,'
        . 'cible_fb INT NULL,'
        . 'produits TEXT NULL,'
        . 'cout DECIMAL(12,2) NULL,'
        . 'marge_pct DECIMAL(5,2) NULL,'
        . 'gele_le DATETIME NULL,'
        . 'maj DATETIME NULL,'
        . 'PRIMARY KEY (campagne_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Le relevé Facebook : deux nombres, saisis à la main. La page n'est pas
    // connectée — un chiffre relevé vaut mieux qu'un indicateur absent.
    Db::exec('CREATE TABLE IF NOT EXISTS mar_campagne_releve ('
        . 'campagne_id INT NOT NULL,'
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'phase VARCHAR(8) NOT NULL,'
        . 'abonnes INT NULL,'
        . 'releve_le DATE NULL,'
        . 'maj DATETIME NULL,'
        . 'PRIMARY KEY (campagne_id, shop_id, phase)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Le gel de la référence. Les remontées de caisse arrivent avec du retard :
    // sans cette photo prise au lancement, l'« avant » change tout seul et le
    // résultat n'est plus reproductible d'une semaine à l'autre.
    Db::exec('CREATE TABLE IF NOT EXISTS mar_campagne_snapshot ('
        . 'campagne_id INT NOT NULL,'
        . 'kpi VARCHAR(32) NOT NULL,'
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'valeur DECIMAL(16,3) NULL,'
        . 'mesure_le DATETIME NULL,'
        . 'PRIMARY KEY (campagne_id, kpi, shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Jours entre deux dates, bornes comprises. */
function mesJours(string $du, string $au): int
{
    $d = new DateTimeImmutable($du); $f = new DateTimeImmutable($au);
    return $f < $d ? 0 : (int) $d->diff($f)->days + 1;
}

function mesDecale(string $d, int $jours): string
{
    return date('Y-m-d', strtotime($d . ' ' . ($jours >= 0 ? '+' : '') . $jours . ' day'));
}

/**
 * Les fenêtres de comparaison.
 *
 * La référence tient un nombre ENTIER de semaines : quatre semaines pleines
 * portent autant de samedis que de lundis. Une « période précédente » de 23
 * jours en porterait trois d'un côté et quatre de l'autre, et la comparaison
 * mesurerait le calendrier avant de mesurer la campagne.
 */
function mesFenetres(array $camp, array $p): array
{
    $auj = date('Y-m-d');
    $campDu = (string) $camp['debut'];
    $campFin = (string) $camp['fin'];
    // Une campagne à venir garde SA fenêtre : borner au jour même donnerait
    // « du 01/11 au 22/08 », une période à l'envers.
    $campAu = $campDu > $auj ? $campFin : min($campFin, $auj);
    $duree = max(1, mesJours($campDu, $campFin));
    // Semaines PLEINES, au plus près de la durée de campagne : 30 jours de
    // campagne se comparent à quatre semaines (28 jours), pas à cinq. On
    // compare des moyennes par jour ouvert — c'est la composition des jours
    // qui doit correspondre, pas le nombre.
    $refLong = max(7, (int) (max(1, round($duree / 7)) * 7));

    $refAu = ($p['ref_fin'] ?? null) ?: mesDecale($campDu, -1);
    $refDu = ($p['ref_debut'] ?? null) ?: mesDecale($refAu, -($refLong - 1));
    $refLong = max(1, mesJours($refDu, $refAu));

    $preAu = mesDecale($refDu, -1);
    $preDu = mesDecale($preAu, -($refLong - 1));

    $rem = max(0, (int) ($p['remanence_jours'] ?? 14));
    $remDu = mesDecale($campFin, 1);
    $remAu = mesDecale($campFin, $rem);
    $remLu = min($remAu, $auj);

    return [
        'pre'  => ['du' => $preDu, 'au' => $preAu, 'jours' => mesJours($preDu, $preAu), 'lu' => $preAu <= $auj],
        'ref'  => ['du' => $refDu, 'au' => $refAu, 'jours' => $refLong, 'lu' => $refAu <= $auj],
        'camp' => ['du' => $campDu, 'au' => $campAu, 'jours' => $duree,
                   'ecoulee' => $campDu > $auj ? 0 : mesJours($campDu, min($campFin, $auj)),
                   'encours' => $campFin > $auj, 'commencee' => $campDu <= $auj],
        'rem'  => ['du' => $remDu, 'au' => $remAu, 'lu' => $remLu, 'jours' => $rem,
                   'dispo' => $rem > 0 && $remDu <= $auj],
        'n1'   => ['refDu' => mesDecale($refDu, 0), 'campDu' => $campDu],
        'span' => ['du' => $preDu, 'au' => min(max($remAu, $campAu), $auj)],
    ];
}

/** Une fenêtre vide : c'est un trou, jamais un zéro. */
function mesBloc(): array
{
    return ['ca' => 0.0, 'tickets' => 0, 'jours' => 0, 'caJour' => null, 'ticketsJour' => null, 'panier' => null];
}

function mesClore(array $b): array
{
    if ($b['jours'] > 0) {
        $b['caJour'] = round($b['ca'] / $b['jours'], 2);
        $b['ticketsJour'] = round($b['tickets'] / $b['jours'], 1);
    }
    $b['panier'] = $b['tickets'] > 0 ? round($b['ca'] / $b['tickets'], 2) : null;
    $b['ca'] = round($b['ca'], 2);
    return $b;
}

/** Variation relative — et rien du tout si la base manque. */
function mesVar(?float $apres, ?float $avant): ?float
{
    if ($apres === null || $avant === null || $avant <= 0) { return null; }
    return round(($apres / $avant - 1) * 100, 2);
}

/**
 * Séries quotidiennes par magasin, sur toute l'étendue mesurée.
 *
 * `margin-heatmap` rend le détail JOUR par jour pour un magasin (ca, tickets,
 * panier) et honore `from`/`to` — un appel par magasin suffit donc à couvrir
 * les quatre fenêtres. À défaut d'API, la table `transaction` du cockpit donne
 * la même chose avec un jour ou deux de retard.
 */
function mesSeriesJour(array $shops, string $du, string $au, array &$motifs): array
{
    $out = [];
    foreach ($shops as $sid) { $out[(string) $sid] = []; }

    if (PanelApi::configured()) {
        // MESURÉ : `margin-heatmap` ne rend rien au-delà d'un mois environ —
        // demandée sur soixante-trois jours, la route reste muette et on
        // retombait en silence sur la caisse en base, qui n'a qu'une poignée de
        // jours. On découpe donc l'étendue en MOIS CIVILS, un appel chacun.
        $req = [];
        $mois = [];
        for ($m = date('Y-m-01', strtotime($du)); $m <= $au; $m = date('Y-m-01', strtotime($m . ' +1 month'))) {
            $mois[] = [max($m, $du), min(date('Y-m-t', strtotime($m)), $au)];
        }
        foreach ($shops as $sid) {
            foreach ($mois as $i => [$d1, $d2]) {
                $req[$sid . '|' . $i] = '/consultant/shops/' . (int) $sid . '/margin-heatmap?'
                    . http_build_query(['from' => $d1, 'to' => $d2]);
            }
        }
        $vu = 0;
        if (count($req) <= 60) {
            foreach (PanelApi::getParallele($req) as $cle => $r) {
                $sid = (string) strtok((string) $cle, '|');
                $jours = is_array($r) ? ($r['days'] ?? null) : null;
                if (!is_array($jours) || !isset($out[$sid])) { continue; }
                foreach ($jours as $d) {
                    $date = (string) ($d['date'] ?? '');
                    if ($date === '' || $date < $du || $date > $au) { continue; }
                    $ca = (float) ($d['ca'] ?? 0);
                    if (empty($d['has_data']) || $ca <= 0) { continue; }
                    $out[$sid][$date] = ['ca' => $ca, 'tickets' => (int) ($d['tickets'] ?? 0)];
                    $vu++;
                }
            }
        } else {
            $motifs[] = 'étendue trop large pour le détail quotidien';
        }
        if ($vu > 0) { return $out; }
        $motifs[] = 'le panel n’a pas rendu le détail quotidien';
    }

    try {
        foreach (Db::rows("SELECT id_shop, DATE(insert_timestamp) j,
                                  COUNT(DISTINCT ticket_key) tickets,
                                  SUM(total_gross_amount_after_discount) ca
                             FROM transaction
                            WHERE insert_timestamp >= ? AND insert_timestamp < ?
                         GROUP BY id_shop, j", [$du . ' 00:00:00', mesDecale($au, 1) . ' 00:00:00']) as $r) {
            $sid = (string) $r['id_shop'];
            if (!isset($out[$sid])) { continue; }
            $ca = (float) $r['ca'];
            if ($ca <= 0) { continue; }
            $out[$sid][(string) $r['j']] = ['ca' => $ca, 'tickets' => (int) $r['tickets']];
        }
    } catch (PDOException $e) {
        $motifs[] = 'caisse indisponible : ' . $e->getMessage();
    }
    return $out;
}

/**
 * Le total d'une fenêtre, magasin par magasin, en UN appel.
 *
 * Filet de sécurité du détail quotidien : quand celui-ci manque, les totaux
 * restent justes — seule la courbe disparaît. Un chiffre approché tiré d'une
 * caisse locale incomplète serait pire qu'une courbe absente.
 */
function mesFenetreParMagasin(string $du, string $au): array
{
    $out = [];
    if (!PanelApi::configured() || $du > date('Y-m-d')) { return $out; }
    foreach (analyseListe(PanelApi::shopsSalesKpisEntre($du, min($au, date('Y-m-d'))) ?? []) as $x) {
        $id = 0;
        foreach (['shop_id', 'id_shop', 'id'] as $c) {
            if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; }
        }
        if ($id <= 0) { continue; }
        $ca = (float) (nombreOuNull($x, ['ca', 'turnover', 'revenue']) ?? 0);
        $tk = (int) (nombreOuNull($x, ['tickets', 'receipts', 'transactions']) ?? 0);
        if ($ca <= 0 && $tk <= 0) { continue; }
        $out[(string) $id] = ['ca' => $ca, 'tickets' => $tk];
    }
    return $out;
}

/** Cumul d'une série quotidienne sur une fenêtre. */
function mesCumul(array $serie, string $du, string $au): array
{
    $b = mesBloc();
    foreach ($serie as $date => $v) {
        if ($date < $du || $date > $au) { continue; }
        $b['ca'] += $v['ca']; $b['tickets'] += $v['tickets']; $b['jours']++;
    }
    return mesClore($b);
}

/** Somme de plusieurs magasins sur une fenêtre — au jour OUVERT du groupe. */
function mesCumulGroupe(array $series, array $shops, string $du, string $au): array
{
    $ca = 0.0; $tk = 0; $jours = [];
    foreach ($shops as $sid) {
        foreach ($series[(string) $sid] ?? [] as $date => $v) {
            if ($date < $du || $date > $au) { continue; }
            $ca += $v['ca']; $tk += $v['tickets']; $jours[$date] = true;
        }
    }
    $b = ['ca' => $ca, 'tickets' => $tk, 'jours' => count($jours), 'caJour' => null, 'ticketsJour' => null, 'panier' => null];
    return mesClore($b);
}

/**
 * Volumes vendus d'une référence sur une fenêtre.
 *
 * MESURÉ (sonde produit) : le panel rend la même quantité pour tous les
 * magasins — c'est une valeur RÉSEAU. Un seul appel suffit donc par fenêtre, et
 * l'écran doit le dire plutôt que de laisser croire à un détail par magasin.
 */
function mesVolumes(int $shopRef, string $du, string $au): array
{
    $r = PanelApi::get('/shops/' . $shopRef . '/products/waste?'
        . http_build_query(['from' => $du, 'date_from' => $du, 'to' => $au, 'date_to' => $au]));
    $lignes = (is_array($r) && isset($r['products']) && is_array($r['products'])) ? $r['products'] : analyseListe($r);
    $out = [];
    foreach ($lignes as $p) {
        $id = trim((string) ($p['id_product'] ?? ''));
        if ($id === '') { continue; }
        $out[$id] = ['qte' => (float) ($p['sold_qty'] ?? 0),
            'nom' => (string) ($p['name'] ?? $p['product_name'] ?? ''),
            'categorie' => (string) ($p['category_name'] ?? '')];
    }
    return $out;
}

/** Les références promues par la campagne : le catalogue de l'offre, plus les ajouts à la main. */
function mesProduitsCampagne(int $campagneId, ?string $libre): array
{
    $out = [];
    // L'offre se lit à la fiche de la campagne, au module Marketing (marketing/src/campagnes.php, 21/11/2026). Module
    // muet ou offre absente : la saisie libre prend le relais.
    $f = campagneFiche($campagneId);
    foreach ((array) ($f['fiche']['offer']['items'] ?? []) as $r) {
        if (!is_array($r)) { continue; }
        $sku = trim((string) ($r['sku_ref'] ?? ''));
        if ($sku === '') { continue; }
        $nom = ($r['label'] ?? null) !== null ? $r['label'] : ($r['catalog_name'] ?? null);   // le libellé de l'offre, sinon le catalogue
        $out[$sku] = ['sku' => $sku, 'nom' => (string) ($nom ?? $sku), 'source' => 'offre'];
    }

    foreach (preg_split('/[\r\n]+/', (string) $libre) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '') { continue; }
        if (!preg_match('/^(\S+)\s*(.*)$/u', $ligne, $m)) { continue; }
        $sku = $m[1];
        $out[$sku] = ['sku' => $sku, 'nom' => $m[2] !== '' ? $m[2] : ($out[$sku]['nom'] ?? $sku), 'source' => 'saisie'];
    }
    return array_values($out);
}

/**
 * GET /marketing/mesure/comparaison — la lecture SIMPLE d'une campagne.
 *
 * Une question, une réponse : pour CHAQUE magasin, ce que la campagne a
 * changé. Deux fenêtres de même longueur — celle d'avant, celle de la
 * campagne — et chacune comparée à SON N-1, c'est-à-dire aux mêmes semaines
 * l'an dernier. Le N-1 se prend à 364 jours (52 semaines) et non à « un an » :
 * un lundi doit tomber en face d'un lundi, sinon on compare un week-end à un
 * jour creux et l'écart ne veut rien dire.
 *
 * L'EFFET NET est la seule mesure qui parle de la campagne : écart pendant
 * moins écart avant. Un magasin déjà en croissance de 5 % avant la campagne ne
 * lui doit pas ces 5 %. La ligne « réseau hors campagne » donne le bruit de
 * fond — ce qui reste dans cette marge n'est pas un effet.
 *
 * L'indicateur suit la campagne : trafic (clients), panier moyen, chiffre
 * d'affaires. Les VOLUMES PROMUS, eux, ne sont pas mesurables par magasin —
 * le panel rend la même quantité pour tous (mesuré) : l'écran le dit au lieu
 * d'inventer un détail.
 */
function ep_mesure_comparaison(): array
{
    $motifs = [];
    [$camps, $errC] = campagnesDatees();   // au module Marketing (marketing/src/campagnes.php)
    if ($camps === null) {
        return ['indispo' => true, 'raison' => 'Le module Marketing ne rend pas les campagnes : ' . $errC . '.'];
    }
    $liste = [];
    foreach ($camps as $c) {
        $liste[] = ['id' => (int) $c['id'], 'nom' => (string) $c['name'],
            'debut' => (string) $c['starts_on'], 'fin' => (string) ($c['ends_on'] ?: $c['starts_on']),
            'type' => (string) ($c['type_label'] ?? 'Sans type'),
            'scope' => strtoupper(trim((string) ($c['scope'] ?? '')))];
    }
    $out = ['campagnes' => $liste, 'campagne' => null];
    if ($liste === []) { return ['campagnes' => [], 'campagne' => null, 'motifs' => [],
        'vide' => 'Aucune campagne datée dans le module marketing.']; }

    $auj = date('Y-m-d');
    $choisie = (int) ($_GET['campagne'] ?? 0);
    $camp = null;
    foreach ($liste as $c) { if ($c['id'] === $choisie) { $camp = $c; } }
    if ($camp === null) {
        foreach ($liste as $c) { if ($c['debut'] <= $auj && $c['fin'] >= $auj) { $camp = $c; break; } }
        if ($camp === null) {
            foreach ($liste as $c) { if ($c['fin'] < $auj && ($camp === null || $c['fin'] > $camp['fin'])) { $camp = $c; } }
        }
        $camp = $camp ?? $liste[0];
    }
    $out['campagne'] = $camp;

    // L'indicateur. « produits » est demandé mais non mesurable par magasin :
    // on le dit, et on retombe sur le trafic plutôt que de rendre un chiffre
    // réseau en le faisant passer pour un chiffre de magasin.
    $mesure = (string) ($_GET['mesure'] ?? '');
    if ($mesure === 'produits') {
        $motifs[] = 'volumes promus : le panel rend la même quantité pour tous les magasins (mesuré) — pas de détail par magasin';
        $mesure = 'trafic';
    }
    if (!in_array($mesure, ['trafic', 'panier', 'ca'], true)) { $mesure = 'trafic'; }
    $out['mesure'] = $mesure;

    // --- les quatre fenêtres. La période mesurée est celle de la campagne, ou
    // celle qu'on demande explicitement : comparer deux périodes de son choix
    // reste utile hors campagne, et la bande de campagne dit alors où elle
    // tombe dans la courbe.
    $duLibre = (string) ($_GET['du'] ?? '');
    $auLibre = (string) ($_GET['au'] ?? '');
    $dateOk = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    $libre = $dateOk($duLibre) && $dateOk($auLibre) && $duLibre <= $auLibre;
    $out['libre'] = $libre;

    // La campagne s'arrête à aujourd'hui : compter des jours à venir ferait
    // chuter la moyenne sans que rien ne se soit passé.
    $campDu = $libre ? $duLibre : $camp['debut'];
    $campAu = $libre ? min($auLibre, $auj) : min($camp['fin'], $auj);

    // Une campagne PAS ENCORE COMMENCÉE n'a rien à mesurer. On le dit, on
    // montre les fenêtres qui SERONT comparées, et on n'appelle pas le panel
    // pour des jours qui n'existent pas.
    $pasCommencee = !$libre && $camp['debut'] > $auj;
    $out['pasCommencee'] = $pasCommencee;
    if ($pasCommencee) { $campAu = $camp['fin']; }
    if ($campAu < $campDu) { $campAu = $campDu; }
    $duree = mesJours($campDu, $campAu);
    $avantAu = mesDecale($campDu, -1);
    $avantDu = mesDecale($avantAu, -($duree - 1));
    $n1 = static fn (string $d): string => mesDecale($d, -364);
    $f = ['avantDu' => $avantDu, 'avantAu' => $avantAu, 'pendantDu' => $campDu, 'pendantAu' => $campAu,
        'avantN1Du' => $n1($avantDu), 'avantN1Au' => $n1($avantAu),
        'pendantN1Du' => $n1($campDu), 'pendantN1Au' => $n1($campAu),
        'jours' => $duree, 'aVenir' => mesJours($campAu, $camp['fin']) - 1];
    $out['fenetres'] = $f;

    // --- magasins et périmètre.
    $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    $nomDe = [];
    foreach ($shops as $s2) { $nomDe[(string) $s2['id']] = (string) $s2['name']; }
    // Le périmètre suit le SCOPE de la campagne, pas la table de liaison.
    // MESURÉ : une campagne « RÉSEAU » porte quand même des lignes dans
    // mar_campaign_shop — quatre pour Saint-Nicolas, dont un magasin FERMÉ et
    // sans Sombreffe. S'y fier faisait sortir du périmètre un magasin que la
    // campagne couvre pourtant, et le comptait comme témoin : l'effet mesuré
    // était alors comparé à lui-même.
    $reseau = str_starts_with((string) ($camp['scope'] ?? ''), 'RESEAU')
        || str_starts_with((string) ($camp['scope'] ?? ''), 'RÉSEAU')
        || str_starts_with((string) ($camp['scope'] ?? ''), 'NETWORK');
    $perim = [];
    if (!$reseau) {
        // Les boutiques de la campagne, à sa fiche du module ; illisibles : réseau entier, comme avant.
        [$ids] = campagneBoutiques((int) $camp['id']);
        foreach ($ids ?? [] as $sid) {
            if (isset($nomDe[$sid])) { $perim[] = $sid; }
        }
    }
    if ($perim === []) { $perim = array_map('strval', array_keys($nomDe)); }
    $hors = array_values(array_diff(array_map('strval', array_keys($nomDe)), $perim));
    $out['scope'] = $reseau ? 'reseau' : 'locale';

    // --- les séries. DEUX étendues seulement — cette année et l'an dernier —
    // plutôt qu'une seule qui couvrirait quatorze mois : la route quotidienne
    // se découpe en mois et le nombre d'appels exploserait. Une campagne à
    // venir n'en demande AUCUNE : lire des jours qui n'existent pas coûte
    // quarante appels au panel pour rien.
    $tous = array_map('strval', array_keys($nomDe));
    $serN = []; $serN1 = [];
    if (!$pasCommencee) {
        $serN = mesSeriesJour($tous, $f['avantDu'], min($f['pendantAu'], $auj), $motifs);
        $serN1 = mesSeriesJour($tous, $f['avantN1Du'], $f['pendantN1Au'], $motifs);
    } else {
        $motifs[] = 'campagne pas encore commencée : rien à mesurer, seules les fenêtres sont montrées';
    }
    foreach ($tous as $sid) { $serN[(string) $sid] = $serN[(string) $sid] ?? []; $serN1[(string) $sid] = $serN1[(string) $sid] ?? []; }

    // Valeur d'une fenêtre selon l'indicateur choisi, ramenée au JOUR OUVERT
    // pour le trafic et le CA — deux fenêtres n'ont pas toujours le même
    // nombre de jours ouverts, et comparer des totaux mentirait.
    $valeur = static function (array $cumul) use ($mesure) {
        if ($mesure === 'panier') { return $cumul['panier']; }
        if ($mesure === 'ca') { return $cumul['caJour']; }
        return $cumul['ticketsJour'];
    };
    $total = static function (array $cumul) use ($mesure) {
        if ($mesure === 'panier') { return $cumul['panier']; }
        if ($mesure === 'ca') { return $cumul['ca'] > 0 ? $cumul['ca'] : null; }
        return $cumul['tickets'] > 0 ? (float) $cumul['tickets'] : null;
    };

    // La courbe : une valeur par SEMAINE, alignée sur le début de la fenêtre
    // « avant » — c'est elle qui donne le rythme, jour par jour la courbe
    // devient illisible et le bruit du week-end masque la campagne.
    $semaines = static function (array $serie, string $du, string $au) use ($valeur): array {
        $out2 = [];
        $i = 0;
        for ($d = $du; $d <= $au; $d = mesDecale($d, 7), $i++) {
            $fin = min(mesDecale($d, 6), $au);
            $out2[] = ['s' => $i + 1, 'du' => $d, 'au' => $fin, 'v' => $valeur(mesCumul($serie, $d, $fin))];
        }
        return $out2;
    };

    $ligne = static function (array $sids, string $nom, ?string $id) use (
        $serN, $serN1, $f, $valeur, $total, $semaines, $mesure
    ): array {
        $cum = static function (array $series, string $du, string $au) use ($sids) {
            return mesCumulGroupe($series, $sids, $du, $au);
        };
        $aN = $valeur($cum($serN, $f['avantDu'], $f['avantAu']));
        $aN1 = $valeur($cum($serN1, $f['avantN1Du'], $f['avantN1Au']));
        $pN = $valeur($cum($serN, $f['pendantDu'], $f['pendantAu']));
        $pN1 = $valeur($cum($serN1, $f['pendantN1Du'], $f['pendantN1Au']));
        $eA = mesVar($aN, $aN1);
        $eP = mesVar($pN, $pN1);
        // Une seule série par ligne : la somme des magasins de la ligne.
        $unN = []; $unN1 = [];
        foreach ($sids as $sid) {
            foreach ($serN[(string) $sid] ?? [] as $d => $v) {
                $unN[$d] = ['ca' => ($unN[$d]['ca'] ?? 0) + $v['ca'], 'tickets' => ($unN[$d]['tickets'] ?? 0) + $v['tickets']];
            }
            foreach ($serN1[(string) $sid] ?? [] as $d => $v) {
                $unN1[$d] = ['ca' => ($unN1[$d]['ca'] ?? 0) + $v['ca'], 'tickets' => ($unN1[$d]['tickets'] ?? 0) + $v['tickets']];
            }
        }
        return [
            'id' => $id, 'nom' => $nom, 'magasins' => count($sids),
            'avant' => ['n' => $aN, 'n1' => $aN1, 'ecart' => $eA,
                'totalN' => $total($cum($serN, $f['avantDu'], $f['avantAu'])),
                'totalN1' => $total($cum($serN1, $f['avantN1Du'], $f['avantN1Au']))],
            'pendant' => ['n' => $pN, 'n1' => $pN1, 'ecart' => $eP,
                'totalN' => $total($cum($serN, $f['pendantDu'], $f['pendantAu'])),
                'totalN1' => $total($cum($serN1, $f['pendantN1Du'], $f['pendantN1Au']))],
            // L'effet net n'existe que si les DEUX écarts existent : un seul
            // des deux rendrait un chiffre qui ressemble à une mesure.
            'net' => ($eA === null || $eP === null) ? null : round($eP - $eA, 2),
            'courbe' => $semaines($unN, $f['avantDu'], $f['pendantAu']),
            'courbeN1' => $semaines($unN1, $f['avantN1Du'], $f['pendantN1Au']),
        ];
    };

    // TOUS les magasins ont leur rangée — ceux de la campagne d'abord, les
    // autres ensuite. Un magasin hors campagne fondu dans le seul témoin
    // disparaissait de l'écran : on ne pouvait plus lire ce qu'il a fait, ni
    // vérifier que le bruit vient bien de lui.
    $out['magasins'] = [];
    foreach ($perim as $sid) {
        $l = $ligne([$sid], $nomDe[$sid] ?? ('Magasin ' . $sid), $sid);
        $l['role'] = 'campagne';
        $out['magasins'][] = $l;
    }
    foreach ($hors as $sid) {
        $l = $ligne([$sid], $nomDe[$sid] ?? ('Magasin ' . $sid), $sid);
        $l['role'] = 'hors';
        $out['magasins'][] = $l;
    }
    // Le témoin : les magasins qui n'ont RIEN lancé. Sans eux, on mesure la
    // saison et la météo.
    // Le témoin reste la SOMME des magasins hors campagne : c'est elle qui
    // donne le bruit, un magasin seul étant trop bavard pour servir d'étalon.
    $out['temoin'] = $hors ? $ligne($hors, 'Réseau hors campagne', null) : null;
    if ($out['temoin'] !== null) { $out['temoin']['role'] = 'temoin'; }
    if (!$hors) {
        $motifs[] = $reseau
            ? 'campagne réseau : tous les magasins en sont, il n’y a pas de témoin — c’est la comparaison au N-1 qui fait référence'
            : 'toute la campagne couvre le réseau : aucun magasin témoin';
    }
    $out['perimetre'] = array_map(fn ($sid) => $nomDe[$sid] ?? $sid, $perim);
    $out['source'] = 'ventes du panel, jour par jour ; N-1 aligné à 52 semaines (mêmes jours de semaine)';
    $out['motifs'] = array_values(array_unique($motifs));
    return $out;
}

/** GET /marketing/mesure — tout l'écran, les trois vues comprises. */
function ep_mesure(): array
{
    ensureMesureTables();
    $motifs = [];

    [$camps, $errC] = campagnesDatees();   // au module Marketing (marketing/src/campagnes.php)
    if ($camps === null) {
        return ['indispo' => true, 'raison' => 'Le module Marketing ne rend pas les campagnes : ' . $errC . '.'];
    }

    $liste = [];
    foreach ($camps as $c) {
        $liste[] = ['id' => (int) $c['id'], 'nom' => (string) $c['name'],
            'debut' => (string) $c['starts_on'], 'fin' => (string) ($c['ends_on'] ?: $c['starts_on']),
            'statut' => (string) ($c['statut'] ?? $c['status_code'] ?? ''),
            'type' => (string) ($c['type_label'] ?? 'Sans type')];
    }
    $out = ['campagnes' => $liste, 'campagne' => null, 'motifs' => $motifs];
    if ($liste === []) { return $out + ['vide' => 'Aucune campagne datée dans le module marketing.']; }

    // La campagne regardée : celle demandée, sinon la dernière commencée.
    $choisie = (int) ($_GET['campagne'] ?? 0);
    $camp = null;
    foreach ($liste as $c) { if ($c['id'] === $choisie) { $camp = $c; } }
    if ($camp === null) {
        // La campagne la plus proche du présent : celle en cours, sinon la
        // dernière terminée, sinon la prochaine. Ouvrir sur une campagne de
        // novembre au mois d'août n'apprend rien à personne.
        $auj = date('Y-m-d');
        foreach ($liste as $c) { if ($c['debut'] <= $auj && $c['fin'] >= $auj) { $camp = $c; break; } }
        if ($camp === null) {
            foreach ($liste as $c) {
                if ($c['fin'] < $auj && ($camp === null || $c['fin'] > $camp['fin'])) { $camp = $c; }
            }
        }
        if ($camp === null) {
            foreach ($liste as $c) {
                if ($c['debut'] > $auj && ($camp === null || $c['debut'] < $camp['debut'])) { $camp = $c; }
            }
        }
        $camp = $camp ?? $liste[0];
    }
    $out['campagne'] = $camp;

    // Paramétrage mémorisé.
    $p = [];
    foreach (Db::rows('SELECT * FROM mar_campagne_mesure WHERE campagne_id = ?', [$camp['id']]) as $r) { $p = $r; }

    $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    $nomDe = [];
    foreach ($shops as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }

    $perim = [];
    // Les boutiques de la campagne, à sa fiche du module ; illisibles : réseau entier, comme avant.
    [$ids] = campagneBoutiques((int) $camp['id']);
    foreach ($ids ?? [] as $sid) {
        if (isset($nomDe[$sid])) { $perim[] = $sid; }
    }
    // PHP retransforme « 3 » en 3 dès qu'il sert de clé : sans ce passage par
    // la chaîne, la comparaison stricte avec les identifiants de magasins
    // échoue en silence et le périmètre paraît vide.
    if ($perim === []) { $perim = array_map('strval', array_keys($nomDe)); }

    // Témoin : trois régimes.
    //  · « auto »   — tous les magasins hors campagne (le défaut, le plus net) ;
    //  · « réseau » — le RÉSEAU COMPLET, magasins en campagne compris : la
    //    référence devient « le réseau a fait ceci, la campagne cela ». Le
    //    témoin est alors DILUÉ par les magasins en campagne, donc l'écart net
    //    est minoré — l'écran le dit plutôt que de laisser croire à une mesure
    //    plus fine qu'elle ne l'est ;
    //  · liste explicite — les magasins cochés.
    $brut = trim((string) ($p['temoins'] ?? ''));
    $temoinMode = $brut === 'reseau' ? 'reseau' : ($brut === '' ? 'auto' : 'choix');
    $temoins = [];
    if ($temoinMode === 'reseau') {
        foreach ($nomDe as $sid => $nom) { $temoins[] = (string) $sid; }
    } elseif ($temoinMode === 'choix') {
        foreach (array_values(array_filter(array_map('trim', explode(',', $brut)))) as $sid) {
            if (isset($nomDe[$sid]) && !in_array($sid, $perim, true)) { $temoins[] = $sid; }
        }
        if ($temoins === []) { $temoinMode = 'auto'; }
    }
    if ($temoinMode === 'auto') {
        foreach ($nomDe as $sid => $nom) { if (!in_array((string) $sid, $perim, true)) { $temoins[] = (string) $sid; } }
    }
    // Un témoin qui ne contient QUE les magasins en campagne ne témoigne de
    // rien : il se comparerait à lui-même et rendrait un écart nul, présenté
    // comme un résultat. On préfère dire qu'il n'y a pas de témoin.
    $dilue = count(array_intersect($temoins, $perim));
    if ($temoins !== [] && $dilue === count($temoins)) {
        $temoins = [];
        $motifs[] = 'campagne réseau : aucun magasin ne peut servir de témoin — le verdict reste indicatif';
    }

    $f = mesFenetres($camp, $p);
    $out['fenetres'] = $f;
    $out['param'] = [
        'refDebut' => $f['ref']['du'], 'refFin' => $f['ref']['au'],
        'remanenceJours' => (int) ($p['remanence_jours'] ?? 14),
        'temoins' => $temoins, 'temoinAuto' => $temoinMode === 'auto', 'temoinMode' => $temoinMode,
        'temoinDilue' => $temoins === [] ? 0 : $dilue,
        'n1' => (int) ($p['n1'] ?? 1) === 1,
        'cibleTrafic' => isset($p['cible_trafic']) && $p['cible_trafic'] !== null ? (float) $p['cible_trafic'] : null,
        'ciblePanier' => isset($p['cible_panier']) && $p['cible_panier'] !== null ? (float) $p['cible_panier'] : null,
        'cibleCa' => isset($p['cible_ca']) && $p['cible_ca'] !== null ? (float) $p['cible_ca'] : null,
        'ciblePromo' => isset($p['cible_promo']) && $p['cible_promo'] !== null ? (float) $p['cible_promo'] : null,
        'cibleFb' => isset($p['cible_fb']) && $p['cible_fb'] !== null ? (int) $p['cible_fb'] : null,
        'produits' => (string) ($p['produits'] ?? ''),
        'cout' => isset($p['cout']) && $p['cout'] !== null ? (float) $p['cout'] : null,
        'margePct' => isset($p['marge_pct']) && $p['marge_pct'] !== null ? (float) $p['marge_pct'] : null,
        'geleLe' => $p['gele_le'] ?? null,
    ];
    $out['magasins'] = [];
    foreach ($nomDe as $sid => $nom) {
        $sid = (string) $sid;
        $out['magasins'][] = ['id' => $sid, 'nom' => $nom,
            'role' => in_array($sid, $perim, true) ? 'campagne' : (in_array($sid, $temoins, true) ? 'temoin' : 'hors'),
            'dansTemoin' => in_array($sid, $temoins, true)];
    }

    // ── Les séries quotidiennes, une lecture pour toute l'étendue.
    $tous = array_values(array_unique(array_merge($perim, $temoins)));
    $series = mesSeriesJour($tous, $f['span']['du'], $f['span']['au'], $motifs);

    // Le détail quotidien est-il exploitable ? Un magasin couvert sur la moitié
    // au moins des jours écoulés de la référence suffit. En dessous, on ne
    // BRICOLE PAS avec ce qu'on a : on relit les totaux fenêtre par fenêtre,
    // justes par construction, et la courbe s'efface en le disant.
    $bornes = [
        'pre'  => [$f['pre']['du'], $f['pre']['au']],
        'ref'  => [$f['ref']['du'], $f['ref']['au']],
        'camp' => [$f['camp']['du'], $f['camp']['au']],
        'rem'  => [$f['rem']['du'], $f['rem']['lu']],
    ];
    $auj = date('Y-m-d');
    $ecoules = static function (array $b) use ($auj): int {
        $au = min($b[1], $auj);
        return $au < $b[0] ? 0 : mesJours($b[0], $au);
    };
    $attendu = $ecoules($bornes['ref']);
    $couverture = 0;
    foreach ($tous as $sid) { $couverture = max($couverture, count(array_filter(
        array_keys($series[(string) $sid] ?? []),
        fn ($d) => $d >= $f['ref']['du'] && $d <= $f['ref']['au']))); }
    $detailOk = $attendu > 0 && $couverture >= $attendu * 0.5;

    $parFen = [];
    if (!$detailOk) {
        foreach ($bornes as $cle => $b) {
            if ($ecoules($b) <= 0) { $parFen[$cle] = []; continue; }
            $parFen[$cle] = mesFenetreParMagasin($b[0], $b[1]);
        }
        $motifs[] = 'détail quotidien indisponible : totaux relus fenêtre par fenêtre, courbe masquée';
    }
    $fenBloc = static function (string $cle, array $ids) use ($parFen, $bornes, $ecoules): array {
        $b = mesBloc();
        foreach ($ids as $sid) {
            $v = $parFen[$cle][(string) $sid] ?? null;
            if ($v === null) { continue; }
            $b['ca'] += $v['ca']; $b['tickets'] += $v['tickets'];
        }
        $b['jours'] = $b['ca'] > 0 ? $ecoules($bornes[$cle]) : 0;
        return mesClore($b);
    };

    $bloc = static function (string $sid) use ($series, $f, $detailOk, $fenBloc) {
        if (!$detailOk) {
            return ['pre' => $fenBloc('pre', [$sid]), 'ref' => $fenBloc('ref', [$sid]),
                'camp' => $fenBloc('camp', [$sid]), 'rem' => $fenBloc('rem', [$sid])];
        }
        return [
            'pre'  => mesCumul($series[$sid] ?? [], $f['pre']['du'], $f['pre']['au']),
            'ref'  => mesCumul($series[$sid] ?? [], $f['ref']['du'], $f['ref']['au']),
            'camp' => mesCumul($series[$sid] ?? [], $f['camp']['du'], $f['camp']['au']),
            'rem'  => mesCumul($series[$sid] ?? [], $f['rem']['du'], $f['rem']['lu']),
        ];
    };
    $groupe = static function (array $ids, string $cle) use ($series, $f, $detailOk, $fenBloc, $bornes) {
        if (!$detailOk) { return $fenBloc($cle, $ids); }
        return mesCumulGroupe($series, $ids, $bornes[$cle][0], $bornes[$cle][1]);
    };

    // Le témoin d'abord : c'est lui qui donne le bruit de fond à retrancher.
    $gT = ['ref' => $groupe($temoins, 'ref'), 'camp' => $groupe($temoins, 'camp'),
           'pre' => $groupe($temoins, 'pre'), 'rem' => $groupe($temoins, 'rem')];
    $tTraf = mesVar($gT['camp']['ticketsJour'], $gT['ref']['ticketsJour']);
    $tPan  = mesVar($gT['camp']['panier'], $gT['ref']['panier']);
    $tCa   = mesVar($gT['camp']['caJour'], $gT['ref']['caJour']);

    $lignes = [];
    foreach ($perim as $sid) {
        $b = $bloc($sid);
        $dTraf = mesVar($b['camp']['ticketsJour'], $b['ref']['ticketsJour']);
        $dPan  = mesVar($b['camp']['panier'], $b['ref']['panier']);
        $dCa   = mesVar($b['camp']['caJour'], $b['ref']['caJour']);
        // € gagnés : ce que le magasin a fait EN PLUS de ce qu'il aurait fait
        // en suivant simplement le témoin. Pas « CA campagne − CA référence ».
        $euros = null;
        if ($b['ref']['caJour'] !== null && $b['camp']['caJour'] !== null && $b['camp']['jours'] > 0) {
            $attendu = $b['ref']['caJour'] * (1 + (($tCa ?? 0) / 100));
            $euros = round(($b['camp']['caJour'] - $attendu) * $b['camp']['jours'], 2);
        }
        $lignes[] = ['shopId' => $sid, 'nom' => $nomDe[$sid] ?? $sid,
            'ref' => $b['ref'], 'camp' => $b['camp'], 'rem' => $b['rem'], 'pre' => $b['pre'],
            'dTrafic' => $dTraf, 'dPanier' => $dPan, 'dCa' => $dCa,
            'netTrafic' => ($dTraf === null || $tTraf === null) ? null : round($dTraf - $tTraf, 2),
            'netPanier' => ($dPan === null || $tPan === null) ? null : round($dPan - $tPan, 2),
            'netCa' => ($dCa === null || $tCa === null) ? null : round($dCa - $tCa, 2),
            'euros' => $euros];
    }
    $out['lignes'] = $lignes;

    $lignesT = [];
    foreach ($temoins as $sid) {
        $b = $bloc($sid);
        $lignesT[] = ['shopId' => $sid, 'nom' => $nomDe[$sid] ?? $sid,
            'ref' => $b['ref'], 'camp' => $b['camp'],
            'dTrafic' => mesVar($b['camp']['ticketsJour'], $b['ref']['ticketsJour']),
            'dPanier' => mesVar($b['camp']['panier'], $b['ref']['panier']),
            'dCa' => mesVar($b['camp']['caJour'], $b['ref']['caJour'])];
    }
    $out['temoinLignes'] = $lignesT;

    // ── Le réseau en campagne, et le verdict.
    $gC = ['pre' => $groupe($perim, 'pre'), 'ref' => $groupe($perim, 'ref'),
           'camp' => $groupe($perim, 'camp'), 'rem' => $groupe($perim, 'rem')];
    $cTraf = mesVar($gC['camp']['ticketsJour'], $gC['ref']['ticketsJour']);
    $cPan  = mesVar($gC['camp']['panier'], $gC['ref']['panier']);
    $cCa   = mesVar($gC['camp']['caJour'], $gC['ref']['caJour']);
    // Le placebo : la même mesure, un cran plus tôt, sur les mêmes magasins.
    $placebo = mesVar($gC['ref']['caJour'], $gC['pre']['caJour']);
    $net = ($cCa === null || $tCa === null) ? null : round($cCa - $tCa, 2);
    $bruit = $placebo === null ? null : abs($placebo);

    $eurosTot = 0.0; $eurosOk = false;
    foreach ($lignes as $l) { if ($l['euros'] !== null) { $eurosTot += $l['euros']; $eurosOk = true; } }
    $marge = $out['param']['margePct'] !== null ? $out['param']['margePct'] / 100 : null;
    $cout = $out['param']['cout'];

    $niveau = 'insuffisant'; $txt = 'pas encore mesurable';
    if ($cCa !== null && $f['camp']['commencee']) {
        if ($net === null) {
            $niveau = 'indicatif';
            $txt = 'sans témoin — variation brute, à lire avec prudence';
        } elseif ($bruit === null) {
            $niveau = 'indicatif';
            $txt = 'écart au témoin de ' . number_format($net, 1, ',', ' ') . ' pt, sans période de contrôle';
        } else {
            $rapport = $bruit > 0.3 ? abs($net) / $bruit : ($net === 0.0 ? 0 : 9.9);
            $niveau = $rapport >= 2 ? ($net >= 0 ? 'probant' : 'negatif') : 'indicatif';
            $txt = 'écart au témoin ' . number_format($rapport, 1, ',', ' ') . '× la variation habituelle';
        }
    }
    $out['reseau'] = [
        'campagne' => $gC, 'temoin' => $gT,
        'dTrafic' => $cTraf, 'dPanier' => $cPan, 'dCa' => $cCa,
        'tTrafic' => $tTraf, 'tPanier' => $tPan, 'tCa' => $tCa,
        'netTrafic' => ($cTraf === null || $tTraf === null) ? null : round($cTraf - $tTraf, 2),
        'netPanier' => ($cPan === null || $tPan === null) ? null : round($cPan - $tPan, 2),
        'netCa' => $net,
        'placebo' => $placebo, 'bruit' => $bruit,
        'euros' => $eurosOk ? round($eurosTot, 2) : null,
        'marge' => ($eurosOk && $marge !== null) ? round($eurosTot * $marge, 2) : null,
        'cout' => $cout,
        'gain' => ($eurosOk && $marge !== null && $cout !== null) ? round($eurosTot * $marge - $cout, 2) : null,
        'retour' => ($eurosOk && $marge !== null && $cout !== null && $cout > 0)
            ? round($eurosTot * $marge / $cout, 2) : null,
        'remanence' => mesVar($gC['rem']['caJour'], $gC['ref']['caJour']),
        'remanenceTemoin' => mesVar($gT['rem']['caJour'], $gT['ref']['caJour']),
        'verdict' => ['niveau' => $niveau, 'txt' => $txt],
    ];

    // ── La courbe : trafic quotidien, en indice 100 = moyenne de référence.
    $baseC = $gC['ref']['ticketsJour'] ?: null;
    $baseT = $gT['ref']['ticketsJour'] ?: null;
    $serie = [];
    for ($d = $detailOk ? $f['span']['du'] : $f['span']['au']; $detailOk && $d <= $f['span']['au']; $d = mesDecale($d, 1)) {
        $tc = 0; $okC = false; $tt = 0; $okT = false;
        foreach ($perim as $sid) { if (isset($series[$sid][$d])) { $tc += $series[$sid][$d]['tickets']; $okC = true; } }
        foreach ($temoins as $sid) { if (isset($series[$sid][$d])) { $tt += $series[$sid][$d]['tickets']; $okT = true; } }
        $phase = $d < $f['ref']['du'] ? 'pre' : ($d <= $f['ref']['au'] ? 'ref'
            : ($d <= $f['camp']['au'] ? 'camp' : 'rem'));
        $serie[] = ['date' => $d, 'phase' => $phase,
            'camp' => $okC ? $tc : null, 'temoin' => $okT ? $tt : null,
            'campIdx' => ($okC && $baseC) ? round(100 * $tc / $baseC, 1) : null,
            'temoinIdx' => ($okT && $baseT) ? round(100 * $tt / $baseT, 1) : null];
    }
    $out['serie'] = $serie;

    // ── Les références promues : volume réseau, fenêtre par fenêtre.
    $prods = mesProduitsCampagne($camp['id'], $out['param']['produits']);
    $out['produits'] = [];
    $out['produitsNote'] = 'volume vendu RÉSEAU : le panel rend la même quantité pour tous les magasins (mesuré).';
    if ($prods !== [] && PanelApi::configured()) {
        $shopRef = (int) ($perim[0] ?? array_key_first($nomDe) ?? 2);
        $vol = [
            'ref'  => mesVolumes($shopRef, $f['ref']['du'], $f['ref']['au']),
            'camp' => $f['camp']['commencee'] ? mesVolumes($shopRef, $f['camp']['du'], $f['camp']['au']) : [],
            'rem'  => $f['rem']['dispo'] ? mesVolumes($shopRef, $f['rem']['du'], $f['rem']['lu']) : [],
            'n1'   => $out['param']['n1'] ? mesVolumes($shopRef,
                mesDecale($f['camp']['du'], -365), mesDecale($f['camp']['au'], -365)) : [],
        ];
        foreach ($prods as $pr) {
            $sku = $pr['sku'];
            $q = static fn (string $k) => isset($vol[$k][$sku]) ? (float) $vol[$k][$sku]['qte'] : null;
            $r = $q('ref'); $c = $q('camp'); $rm = $q('rem'); $n1 = $q('n1');
            // À longueur de fenêtre différente, on compare au JOUR.
            $parJour = static function (?float $v, int $j): ?float { return ($v === null || $j <= 0) ? null : $v / $j; };
            $rj = $parJour($r, $f['ref']['jours']);
            $cj = $parJour($c, max(1, $f['camp']['ecoulee']));
            $mj = $parJour($rm, max(1, mesJours($f['rem']['du'], $f['rem']['lu'])));
            $reponse = mesVar($cj, $rj);
            $out['produits'][] = [
                'sku' => $sku, 'nom' => $pr['nom'] ?: ($vol['ref'][$sku]['nom'] ?? $sku),
                'categorie' => $vol['ref'][$sku]['categorie'] ?? ($vol['camp'][$sku]['categorie'] ?? ''),
                'source' => $pr['source'],
                'ref' => $r, 'camp' => $c, 'rem' => $rm, 'n1' => $n1,
                'refJour' => $rj === null ? null : round($rj, 1),
                'campJour' => $cj === null ? null : round($cj, 1),
                'reponse' => $reponse,
                'n1Var' => ($n1 !== null && $c !== null && $n1 > 0) ? round(($c / $n1 - 1) * 100, 1) : null,
                'remanence' => mesVar($mj, $rj),
                'connu' => $r !== null || $c !== null,
            ];
        }
    } elseif ($prods === []) {
        $out['produitsNote'] = 'aucune référence promue : ajoutez-les dans le paramétrage (une par ligne : identifiant puis libellé).';
    }

    // ── Le relevé Facebook.
    $rel = [];
    foreach (Db::rows('SELECT shop_id, phase, abonnes, releve_le FROM mar_campagne_releve WHERE campagne_id = ?',
        [$camp['id']]) as $r) {
        $rel[(string) $r['shop_id']][(string) $r['phase']] = [
            'abonnes' => $r['abonnes'] !== null ? (int) $r['abonnes'] : null,
            'date' => $r['releve_le'] ?? null];
    }
    $fbLignes = []; $fbAv = 0; $fbAp = 0; $fbOkAv = false; $fbOkAp = false;
    foreach (array_merge([['id' => '0', 'nom' => 'Réseau / page de marque']],
        array_map(fn ($sid) => ['id' => $sid, 'nom' => $nomDe[$sid] ?? $sid], $perim)) as $m) {
        $a = $rel[$m['id']]['avant'] ?? ['abonnes' => null, 'date' => null];
        $b = $rel[$m['id']]['apres'] ?? ['abonnes' => null, 'date' => null];
        if ($a['abonnes'] !== null) { $fbAv += $a['abonnes']; $fbOkAv = true; }
        if ($b['abonnes'] !== null) { $fbAp += $b['abonnes']; $fbOkAp = true; }
        $fbLignes[] = ['shopId' => $m['id'], 'nom' => $m['nom'],
            'avant' => $a['abonnes'], 'avantDate' => $a['date'],
            'apres' => $b['abonnes'], 'apresDate' => $b['date'],
            'delta' => ($a['abonnes'] !== null && $b['abonnes'] !== null) ? $b['abonnes'] - $a['abonnes'] : null];
    }
    $out['fb'] = ['lignes' => $fbLignes,
        'avant' => $fbOkAv ? $fbAv : null, 'apres' => $fbOkAp ? $fbAp : null,
        'delta' => ($fbOkAv && $fbOkAp) ? $fbAp - $fbAv : null,
        'cible' => $out['param']['cibleFb']];

    // ── Le gel : ce que valait la référence au lancement.
    $snap = [];
    foreach (Db::rows('SELECT kpi, shop_id, valeur, mesure_le FROM mar_campagne_snapshot WHERE campagne_id = ?',
        [$camp['id']]) as $r) {
        $snap[(string) $r['kpi']][(string) $r['shop_id']] = $r['valeur'] !== null ? (float) $r['valeur'] : null;
    }
    $out['gel'] = ['le' => $out['param']['geleLe'], 'valeurs' => $snap];
    $out['motifs'] = $motifs;
    $out['source'] = PanelApi::configured()
        ? 'panel — ventes quotidiennes par magasin, mesurées sur les fenêtres'
        : 'caisse en base — les derniers jours peuvent manquer';
    return $out;
}

/* ─────────────────────────── Écritures ─────────────────────────── */

/** PUT /marketing/mesure/{id} — le paramétrage, écrit à chaque saisie. */
function wr_mesure_param(int $id): array
{
    ensureMesureTables();
    $f = campagneFiche($id);   // au module Marketing
    if ($f['erreur'] !== null) {
        http_response_code(502);
        return ['error' => 'le module Marketing ne rend pas la campagne : ' . $f['erreur']];
    }
    if ($f['fiche'] === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $camp = ['id' => (int) $f['fiche']['id'], 'name' => (string) ($f['fiche']['name'] ?? '')];
    $b = body();

    $date = static function ($v): ?string {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    };
    $num = static function ($v): ?float {
        if ($v === '' || $v === null) { return null; }
        return is_numeric($v) ? (float) $v : null;
    };
    // Les témoins arrivent en liste ; une liste vide veut dire « choix
    // automatique », pas « aucun témoin » — l'écran le dit ainsi.
    $tem = $b['temoins'] ?? null;
    $temStr = null;
    if ($tem === 'reseau') { $temStr = 'reseau'; }
    elseif (is_array($tem)) {
        $ok = [];
        foreach ($tem as $sid) { if (magasinConnu((string) $sid) !== null) { $ok[] = (string) $sid; } }
        $temStr = $ok === [] ? null : implode(',', $ok);
    }

    // L'écran écrit champ par champ : un corps qui ne porte pas une clé ne
    // veut pas dire « efface-la ». Seul ce qui est envoyé est modifié.
    $cur = Db::row('SELECT * FROM mar_campagne_mesure WHERE campagne_id = ?', [$id]) ?? [];
    $val = static function (string $cle, $neuf, $ancien) use ($b) {
        return array_key_exists($cle, $b) ? $neuf : $ancien;
    };

    $ligne = [
        'ref_debut' => $val('refDebut', $date($b['refDebut'] ?? ''), $cur['ref_debut'] ?? null),
        'ref_fin' => $val('refFin', $date($b['refFin'] ?? ''), $cur['ref_fin'] ?? null),
        'remanence_jours' => $val('remanenceJours', max(0, min(120, (int) ($b['remanenceJours'] ?? 14))), (int) ($cur['remanence_jours'] ?? 14)),
        'temoins' => array_key_exists('temoins', $b) ? $temStr : ($cur['temoins'] ?? null),
        'n1' => $val('n1', !empty($b['n1']) ? 1 : 0, (int) ($cur['n1'] ?? 1)),
        'cible_trafic' => $val('cibleTrafic', $num($b['cibleTrafic'] ?? null), $cur['cible_trafic'] ?? null),
        'cible_panier' => $val('ciblePanier', $num($b['ciblePanier'] ?? null), $cur['cible_panier'] ?? null),
        'cible_ca' => $val('cibleCa', $num($b['cibleCa'] ?? null), $cur['cible_ca'] ?? null),
        'cible_promo' => $val('ciblePromo', $num($b['ciblePromo'] ?? null), $cur['cible_promo'] ?? null),
        'cible_fb' => $val('cibleFb', ($b['cibleFb'] ?? '') === '' ? null : (int) $b['cibleFb'], $cur['cible_fb'] ?? null),
        'produits' => $val('produits', trim((string) ($b['produits'] ?? '')), $cur['produits'] ?? null),
        'cout' => $val('cout', $num($b['cout'] ?? null), $cur['cout'] ?? null),
        'marge_pct' => $val('margePct', $num($b['margePct'] ?? null), $cur['marge_pct'] ?? null),
    ];

    Db::exec('INSERT INTO mar_campagne_mesure
        (campagne_id, ref_debut, ref_fin, remanence_jours, temoins, n1,
         cible_trafic, cible_panier, cible_ca, cible_promo, cible_fb, produits, cout, marge_pct, maj)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE ref_debut = VALUES(ref_debut), ref_fin = VALUES(ref_fin),
          remanence_jours = VALUES(remanence_jours), temoins = VALUES(temoins), n1 = VALUES(n1),
          cible_trafic = VALUES(cible_trafic), cible_panier = VALUES(cible_panier),
          cible_ca = VALUES(cible_ca), cible_promo = VALUES(cible_promo), cible_fb = VALUES(cible_fb),
          produits = VALUES(produits), cout = VALUES(cout), marge_pct = VALUES(marge_pct), maj = VALUES(maj)',
        [$id, $ligne['ref_debut'], $ligne['ref_fin'], $ligne['remanence_jours'], $ligne['temoins'], $ligne['n1'],
         $ligne['cible_trafic'], $ligne['cible_panier'], $ligne['cible_ca'], $ligne['cible_promo'],
         $ligne['cible_fb'], $ligne['produits'], $ligne['cout'], $ligne['marge_pct'], date('Y-m-d H:i:s')]);

    if (($_GET['journal'] ?? '') !== '0') {
        journalAdd('CEO', 'Campagne', (string) $camp['name'], 'Paramétrage de la mesure d’impact');
    }
    return ['ok' => true];
}

/** PUT /marketing/mesure/{id}/releve — les abonnés Facebook, relevés à la main. */
function wr_mesure_releve(int $id): array
{
    ensureMesureTables();
    $b = body();
    $sid = (string) ($b['shopId'] ?? '0');
    $phase = ($b['phase'] ?? '') === 'apres' ? 'apres' : 'avant';
    if ($sid !== '0' && magasinConnu($sid) === null) { http_response_code(400); return ['error' => 'magasin inconnu']; }

    $v = $b['abonnes'] ?? null;
    if ($v === '' || $v === null) {
        Db::exec('DELETE FROM mar_campagne_releve WHERE campagne_id = ? AND shop_id = ? AND phase = ?', [$id, $sid, $phase]);
        return ['ok' => true, 'efface' => true];
    }
    $date = trim((string) ($b['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    Db::exec('INSERT INTO mar_campagne_releve (campagne_id, shop_id, phase, abonnes, releve_le, maj)
              VALUES (?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE abonnes = VALUES(abonnes), releve_le = VALUES(releve_le), maj = VALUES(maj)',
        [$id, $sid, $phase, max(0, (int) $v), $date, date('Y-m-d H:i:s')]);
    return ['ok' => true];
}

/**
 * POST /marketing/mesure/{id}/gel — figer la référence.
 *
 * On écrit ce que valait l'avant AU MOMENT DU GEL. La caisse remonte avec du
 * retard : relu trois semaines plus tard, le même « avant » aurait changé.
 */
function wr_mesure_gel(int $id): array
{
    ensureMesureTables();
    $b = body();
    if (!empty($b['annuler'])) {
        Db::exec('DELETE FROM mar_campagne_snapshot WHERE campagne_id = ?', [$id]);
        Db::exec('UPDATE mar_campagne_mesure SET gele_le = NULL WHERE campagne_id = ?', [$id]);
        return ['ok' => true, 'gele' => false];
    }
    $_GET['campagne'] = (string) $id;
    $d = ep_mesure();
    if (!empty($d['indispo']) || empty($d['lignes'])) {
        http_response_code(409);
        return ['error' => 'rien à geler : la fenêtre de référence ne rend aucune donnée'];
    }
    $now = date('Y-m-d H:i:s');
    Db::exec('DELETE FROM mar_campagne_snapshot WHERE campagne_id = ?', [$id]);
    $pose = static function (string $kpi, string $sid, ?float $v) use ($id, $now) {
        if ($v === null) { return; }
        Db::exec('INSERT INTO mar_campagne_snapshot (campagne_id, kpi, shop_id, valeur, mesure_le) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), mesure_le = VALUES(mesure_le)',
            [$id, $kpi, $sid, $v, $now]);
    };
    foreach ($d['lignes'] as $l) {
        $pose('ca_jour', (string) $l['shopId'], $l['ref']['caJour'] ?? null);
        $pose('tickets_jour', (string) $l['shopId'], $l['ref']['ticketsJour'] ?? null);
        $pose('panier', (string) $l['shopId'], $l['ref']['panier'] ?? null);
    }
    $r = $d['reseau']['campagne']['ref'] ?? [];
    $pose('ca_jour', '0', $r['caJour'] ?? null);
    $pose('tickets_jour', '0', $r['ticketsJour'] ?? null);
    $pose('panier', '0', $r['panier'] ?? null);
    foreach ($d['produits'] ?? [] as $p) { $pose('volume:' . $p['sku'], '0', $p['refJour']); }

    Db::exec('INSERT INTO mar_campagne_mesure (campagne_id, gele_le, maj) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE gele_le = VALUES(gele_le), maj = VALUES(maj)', [$id, $now, $now]);
    journalAdd('CEO', 'Campagne', (string) ($d['campagne']['nom'] ?? $id),
        'Référence de mesure gelée (' . ($d['fenetres']['ref']['du'] ?? '') . ' → ' . ($d['fenetres']['ref']['au'] ?? '') . ')');
    return ['ok' => true, 'gele' => true, 'le' => $now];
}

/**
 * Le KPI d'une période, magasin par magasin — pour l'assistant de campagne.
 *
 * GET /marketing/kpi-periode?du=&au=[&magasins=2,3][&mesure=trafic|panier|ca]
 *
 * Même mécanique que « Mesure des campagnes », appelée au moment où la période
 * est choisie dans l'assistant : mêmes fenêtres, même décalage de 364 jours,
 * mêmes lectures du panel. Deux écrans qui comparent à N-1 ne doivent pas le
 * faire chacun à leur façon — l'un dirait +2 %, l'autre +3 %, et il n'y aurait
 * aucun moyen de savoir lequel a raison.
 *
 * La période visée est presque toujours À VENIR : on ne mesure donc pas elle,
 * mais ce que les mêmes dates ont donné l'AN DERNIER, avant et pendant. L'écart
 * entre les deux est la pente naturelle de la saison ; c'est au-dessus d'elle
 * que l'objectif se pose, pas au-dessus de zéro.
 */
function ep_mkt_kpi_periode(): array
{
    return mktKpiPeriode($_GET);
}

/**
 * Le calcul lui-même, appelable sans passer par la requête : la note de
 * campagne envoyée aux franchisés porte le MÊME objectif que l'assistant, et
 * deux calculs séparés finiraient par ne plus dire le même chiffre.
 *
 * @param array<string,mixed> $p du, au, levier, mesure, magasins
 */
function mktKpiPeriode(array $p): array
{
    $motifs = [];
    $dateOk = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    $du = (string) ($p['du'] ?? '');
    $au = (string) ($p['au'] ?? '');
    if (!$dateOk($du) || !$dateOk($au) || $au < $du) {
        http_response_code(422);
        return ['error' => 'Période attendue : « du » et « au » au format AAAA-MM-JJ, « du » avant « au ».'];
    }

    // Le KPI vient du LEVIER de la campagne : l'assistant envoie son code, le
    // serveur dit ce qui se mesure. Un `mesure=` explicite reste possible —
    // c'est ce que fait la bascule d'indicateur de l'écran.
    $levier = (string) ($p['levier'] ?? '');
    $mesure = (string) ($p['mesure'] ?? '');
    $duLevier = $levier === '' ? null : mktMesureDuLevier($levier);
    if (!in_array($mesure, ['trafic', 'panier', 'ca'], true)) { $mesure = $duLevier ?? 'trafic'; }

    $duree = mesJours($du, $au);
    // Garde-fou : la route quotidienne du panel se découpe en mois civils, un
    // appel par magasin et par mois. Une période d'un an en demanderait plus de
    // cent pour un écran de saisie — le panel resterait muet et l'assistant
    // attendrait pour rien.
    if ($duree > 122) {
        http_response_code(422);
        return ['error' => 'Période trop longue pour une référence quotidienne : 122 jours au plus.'];
    }

    $avantAu = mesDecale($du, -1);
    $avantDu = mesDecale($avantAu, -($duree - 1));
    // 364 jours et non « un an » : 52 semaines exactes mettent les mêmes jours
    // de semaine en face. Un décalage d'un an calendaire compare un samedi à un
    // dimanche, et l'écart qui en sort n'est que le calendrier.
    $n1 = static fn (string $d): string => mesDecale($d, -364);
    $f = ['du' => $du, 'au' => $au, 'jours' => $duree,
        'avantDu' => $avantDu, 'avantAu' => $avantAu,
        'avantN1Du' => $n1($avantDu), 'avantN1Au' => $n1($avantAu),
        'pendantN1Du' => $n1($du), 'pendantN1Au' => $n1($au),
        'decalage' => 364];

    $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    $nomDe = [];
    foreach ($shops as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }

    // Périmètre demandé par l'assistant (campagne locale). Les identifiants
    // inconnus sont ignorés sans bruit : le paramètre vient d'une page, pas
    // d'un contrat.
    $demandes = array_filter(array_map('trim', explode(',', (string) ($p['magasins'] ?? ''))));
    $perim = array_values(array_filter($demandes, static fn ($s) => isset($nomDe[$s])));
    if ($perim === []) { $perim = array_map('strval', array_keys($nomDe)); }

    // Les deux fenêtres N-1 se touchent : une seule étendue les couvre, donc
    // une seule série par magasin.
    $ser = mesSeriesJour($perim, $f['avantN1Du'], $f['pendantN1Au'], $motifs);

    $valeur = static function (array $b) use ($mesure) {
        if ($mesure === 'panier') { return $b['panier']; }
        if ($mesure === 'ca') { return $b['caJour']; }
        return $b['ticketsJour'];
    };

    // Un magasin sans N-1 n'a pas d'objectif « à zéro » : il a une activité
    // récente, elle. À défaut de l'an dernier, la moyenne des TROIS DERNIERS
    // MOIS COMPLETS donne un point de départ honnête — à condition de dire que
    // c'en est un, et non la référence demandée.
    $mDu = date('Y-m-01', strtotime('-3 month', strtotime(date('Y-m-01'))));
    $mAu = date('Y-m-t', strtotime('-1 month', strtotime(date('Y-m-01'))));

    $lignes = [];
    $avecN1 = 0;
    $sansN1 = [];
    $aReplier = [];
    foreach ($perim as $sid) {
        $a = mesCumul($ser[(string) $sid] ?? [], $f['avantN1Du'], $f['avantN1Au']);
        $p = mesCumul($ser[(string) $sid] ?? [], $f['pendantN1Du'], $f['pendantN1Au']);
        $va = $valeur($a); $vp = $valeur($p);
        if ($va !== null && $vp !== null) { $avecN1++; }
        else { $sansN1[] = $nomDe[(string) $sid]; $aReplier[] = (string) $sid; }
        $lignes[] = ['id' => (string) $sid, 'nom' => $nomDe[(string) $sid],
            'avant' => $a, 'pendant' => $p,
            'valeurAvant' => $va, 'valeurPendant' => $vp,
            'variation' => mesVar($vp === null ? null : (float) $vp, $va === null ? null : (float) $va),
            // Un magasin sans N-1 n'a pas un objectif à zéro : il n'en a pas.
            'sansN1' => $va === null || $vp === null,
            'repli' => null];
    }

    // Le repli, seulement pour ceux qui en ont besoin : trois mois de plus par
    // magasin coûtent trois appels chacun, et les magasins qui ont leur N-1
    // n'en ont que faire.
    if ($aReplier !== [] && count($aReplier) * 3 <= 24) {
        $serR = mesSeriesJour($aReplier, $mDu, $mAu, $motifs);
        foreach ($lignes as $i => $l) {
            if (!$l['sansN1']) { continue; }
            $b = mesCumul($serR[(string) $l['id']] ?? [], $mDu, $mAu);
            $vb = $valeur($b);
            if ($vb === null) { continue; }
            $lignes[$i]['repli'] = ['valeur' => $vb, 'du' => $mDu, 'au' => $mAu,
                'jours' => $b['jours'], 'ca' => $b['ca'], 'tickets' => $b['tickets'],
                'libelle' => 'moyenne des 3 derniers mois'];
        }
    } elseif ($aReplier !== []) {
        $motifs[] = 'trop de magasins sans N-1 pour calculer un repli sur les 3 derniers mois';
    }

    // La valeur RETENUE — et d'où elle vient. Les deux voyagent ensemble :
    // afficher un chiffre sans sa provenance, c'est le faire passer pour la
    // référence demandée.
    //
    // Arrondie À L'AFFICHAGE ICI, et non dans l'écran : le total du réseau est
    // la somme de ces valeurs, et arrondir chacun de son côté ferait une
    // colonne qui n'additionne pas à son propre total.
    $dec = (int) mktKpiCatalogue()[$mesure]['decimales'];
    $arr = static fn (?float $v): ?float => $v === null ? null : round($v, $dec);
    foreach ($lignes as $i => $l) {
        $lignes[$i]['valeurAvant'] = $arr($l['valeurAvant'] === null ? null : (float) $l['valeurAvant']);
        $lignes[$i]['valeurPendant'] = $arr($l['valeurPendant'] === null ? null : (float) $l['valeurPendant']);
        if (($l['repli']['valeur'] ?? null) !== null) {
            $lignes[$i]['repli']['valeur'] = $arr((float) $l['repli']['valeur']);
        }
        $lignes[$i]['valeurRetenue'] = $lignes[$i]['valeurPendant'] ?? ($lignes[$i]['repli']['valeur'] ?? null);
        $lignes[$i]['source'] = $lignes[$i]['valeurPendant'] !== null ? 'n1'
            : (($lignes[$i]['repli']['valeur'] ?? null) !== null ? 'repli' : null);
    }

    $ra = mesCumulGroupe($ser, $perim, $f['avantN1Du'], $f['avantN1Au']);
    $rp = mesCumulGroupe($ser, $perim, $f['pendantN1Du'], $f['pendantN1Au']);
    $rva = $valeur($ra); $rvp = $valeur($rp);
    $rva = $rva === null ? null : round((float) $rva, $dec);
    $rvp = $rvp === null ? null : round((float) $rvp, $dec);

    // Le total du réseau doit être la somme de ce que le tableau affiche.
    // Il ne l'était pas : la colonne montrait le repli d'un magasin sans N-1,
    // et le total ne comptait que les fenêtres N-1 — quatre lignes qui font
    // 710 en face d'un total à 575. On recompose donc à partir des BLOCS
    // retenus, ligne par ligne.
    //
    // Un panier moyen ne s'additionne pas : c'est un rapport. Le réseau le
    // recalcule sur les cumuls (CA total ÷ tickets totaux) — additionner
    // quatre paniers aurait donné 48 € le panier.
    $caR = 0.0; $tkR = 0; $jrR = 0; $nN1 = 0; $nRepli = 0; $aucun = 0;
    foreach ($lignes as $l) {
        $bloc = $l['source'] === 'n1' ? $l['pendant']
            : ($l['source'] === 'repli' ? $l['repli'] : null);
        if ($bloc === null) { $aucun++; continue; }
        if ($l['source'] === 'n1') { $nN1++; } else { $nRepli++; }
        $caR += (float) ($bloc['ca'] ?? 0);
        $tkR += (int) ($bloc['tickets'] ?? 0);
        $jrR = max($jrR, (int) ($bloc['jours'] ?? 0));
    }
    $rvRetenue = null;
    if ($nN1 + $nRepli > 0) {
        if ($mesure === 'panier') {
            $rvRetenue = $tkR > 0 ? round($caR / $tkR, 2) : null;
        } else {
            // Somme des moyennes journalières de chaque magasin : c'est bien
            // ce que la colonne additionne, jour ouvert par jour ouvert.
            $somme = 0.0;
            foreach ($lignes as $l) {
                if ($l['valeurRetenue'] !== null) { $somme += (float) $l['valeurRetenue']; }
            }
            $rvRetenue = round($somme, $dec);
        }
    }

    // Nommer les magasins plutôt que les compter : « 1 magasin sans relevé »
    // oblige à parcourir le tableau pour savoir lequel, et l'objectif qui reste
    // à poser à la main est le sien.
    if ($avecN1 === 0) {
        $motifs[] = 'aucun magasin n’a de relevé sur les mêmes dates l’an dernier';
    } elseif ($sansN1 !== []) {
        $motifs[] = (count($sansN1) === 1 ? $sansN1[0] . ' n’a pas de relevé N-1 : son objectif se pose à la main'
            : implode(', ', $sansN1) . ' n’ont pas de relevé N-1 : leur objectif se pose à la main');
    }

    return [
        'fenetres' => $f + ['repliDu' => $mDu, 'repliAu' => $mAu],
        'mesure' => $mesure,
        'kpi' => mktKpiCatalogue()[$mesure],
        'catalogue' => mktKpiCatalogue(),
        'leviers' => mktKpiParLevier(),
        // Le levier demandé n'a pas toujours un KPI en caisse : l'écran doit
        // pouvoir écrire pourquoi, plutôt que d'afficher le trafic sans dire
        // qu'il a changé d'indicateur en chemin.
        'levierDemande' => $levier === '' ? null : $levier,
        'levierMesurable' => $levier === '' ? null : ($duLevier !== null),
        'magasins' => $lignes,
        'reseau' => ['avant' => $ra, 'pendant' => $rp,
            'valeurAvant' => $rva, 'valeurPendant' => $rvp,
            // La valeur RETENUE — celle qui égale la somme des lignes — et sa
            // composition. La variation, elle, reste calculée sur les seuls
            // magasins qui ont un N-1 : comparer une moyenne de trois mois à
            // l'été dernier ne veut rien dire.
            'valeurRetenue' => $rvRetenue,
            'source' => $nRepli === 0 ? 'n1' : ($nN1 === 0 ? 'repli' : 'mixte'),
            'nN1' => $nN1, 'nRepli' => $nRepli, 'nSans' => $aucun,
            'variation' => mesVar($rvp === null ? null : (float) $rvp, $rva === null ? null : (float) $rva),
            'sansN1' => $rva === null || $rvp === null],
        'motifs' => $motifs,
        'source' => PanelApi::configured()
            ? 'panel — ventes quotidiennes par magasin (/consultant/shops/{id}/margin-heatmap)'
            : 'caisse du cockpit — le compte panel n’est pas configuré',
    ];
}

/**
 * L'effet attendu d'une campagne, en euros, magasin par magasin.
 *
 * Le pourcentage d'objectif ne dit rien à personne en boutique. Des euros, si :
 * le panier moyen récent × les clients par jour qu'on vise en plus × la durée
 * de la campagne. Ajouté à ce que le magasin faisait sur les mêmes dates l'an
 * dernier, cela donne un attendu qui se compare au budget encodé.
 *
 * Le panier vient de la SÉRIE QUOTIDIENNE, jamais des totaux mensuels : mesuré
 * sur juillet 2026, la colonne `tickets` de la table mensuelle vaut 1 765 pour
 * Sombreffe quand son chiffre d'affaires en vaut 45 099 — le mois n'y est
 * compté qu'à moitié côté tickets. Diviser l'un par l'autre donnait un panier
 * de 25 €, et la moyenne sur trois mois montait à 14,61 € au lieu de 11,9 €.
 * Une série quotidienne tronquée l'est des DEUX côtés à la fois : le rapport,
 * lui, reste juste.
 *
 * @param list<string> $perim
 * @return array{entete:array<string,mixed>,magasins:array<string,array<string,mixed>>}
 */
function mktEffetAttendu(int $campagneId, string $du, string $au, array $perim, ?array $kpiDeja = null): array
{
    $motifs = [];
    $vide = ['entete' => ['base' => null, 'gain' => null, 'attendu' => null,
        'panierDu' => '', 'panierAu' => '', 'jours' => 0, 'motifs' => $motifs], 'magasins' => []];
    if ($du === '' || $au === '' || $au < $du || $perim === []) { return $vide; }

    // Le coefficient d'objectif et le levier du type, à la fiche de la campagne au module Marketing (21/11/2026).
    $fiche = campagneFiche($campagneId)['fiche'];
    $camp = $fiche === null ? null
        : ['objective_coef_pct' => $fiche['objective_coef_pct'] ?? null, 'lever_code' => $fiche['type_lever_code'] ?? null];
    $pct = ($camp !== null && $camp['objective_coef_pct'] !== null) ? (float) $camp['objective_coef_pct'] : null;

    // Le trafic : la même mécanique que l'assistant et que la note, appelée et
    // non recopiée — trois écrans qui annoncent trois cibles différentes pour
    // la même campagne, c'est la fin de la confiance.
    // Le trafic peut être déjà lu par l'appelant — la note le fait. Le relire
    // coûterait dix appels au panel pour le même résultat.
    $kpi = $kpiDeja ?? mktKpiPeriode(['du' => $du, 'au' => $au, 'mesure' => 'trafic',
        'magasins' => implode(',', $perim)]);
    if (!empty($kpi['error'])) { return $vide; }
    $jours = (int) ($kpi['fenetres']['jours'] ?? mesJours($du, $au));

    // Le panier : les trois derniers mois CLOS, la fenêtre que le repli utilise
    // déjà. Un panier pris sur la période de campagne n'existe pas — elle n'a
    // pas eu lieu.
    $pDu = (string) ($kpi['fenetres']['repliDu'] ?? date('Y-m-01', strtotime('-3 month', strtotime(date('Y-m-01')))));
    $pAu = (string) ($kpi['fenetres']['repliAu'] ?? date('Y-m-t', strtotime('-1 month', strtotime(date('Y-m-01')))));
    $serie = mesSeriesJour($perim, $pDu, $pAu, $motifs);

    $mags = []; $tBase = 0.0; $tGain = 0.0; $nBase = 0; $nGain = 0;
    $tCliA1 = 0; $tCliPrevu = 0;
    foreach ($kpi['magasins'] as $m) {
        $sid = (string) $m['id'];
        $p = mesCumul($serie[$sid] ?? [], $pDu, $pAu);
        $panier = $p['panier'];

        // Les clients par jour visés en plus : la cible moins la référence.
        $ref = $m['valeurRetenue'];
        $plus = ($ref === null || $pct === null) ? null : round((float) $ref * $pct / 100, 1);
        $gain = ($panier === null || $plus === null) ? null : round($panier * $plus * $jours, 2);

        // La base : le chiffre d'affaires des mêmes dates l'an dernier. Un
        // magasin sans N-1 prend ses trois derniers mois ramenés à la durée de
        // la campagne — et le dit, c'est une autre chose.
        $base = null; $baseSrc = null;
        if ((float) ($m['pendant']['ca'] ?? 0) > 0 && (int) ($m['pendant']['jours'] ?? 0) > 0) {
            $base = round((float) $m['pendant']['ca'], 2); $baseSrc = 'n1';
        } elseif ((float) ($p['ca'] ?? 0) > 0 && (int) ($p['jours'] ?? 0) > 0) {
            $base = round((float) $p['ca'] / $p['jours'] * $jours, 2); $baseSrc = 'repli';
        }

        // Le nombre de CLIENTS sur la période, pas seulement la moyenne par
        // jour : c'est le chiffre qu'on annonce en boutique — « quatre mille
        // trois cents clients en septembre », pas « 145 par jour ».
        $cliA1 = null;
        if ((int) ($m['pendant']['tickets'] ?? 0) > 0) {
            $cliA1 = (int) $m['pendant']['tickets'];
        } elseif ((int) ($p['tickets'] ?? 0) > 0 && (int) ($p['jours'] ?? 0) > 0) {
            // Sans N-1 : les trois derniers mois ramenés à la durée visée.
            $cliA1 = (int) round($p['tickets'] / $p['jours'] * $jours);
        }
        $cliPrevu = ($ref === null) ? null
            : (int) round((float) $ref * (1 + ($pct ?? 0) / 100) * $jours);

        if ($base !== null) { $tBase += $base; $nBase++; }
        if ($gain !== null) { $tGain += $gain; $nGain++; }
        if ($cliA1 !== null) { $tCliA1 += $cliA1; }
        if ($cliPrevu !== null) { $tCliPrevu += $cliPrevu; }

        $mags[$sid] = [
            'panier' => $panier,
            'panierSource' => $panier === null ? null : 'caisse',
            'panierJours' => (int) $p['jours'],
            'clientsJour' => $ref,
            'plus' => $plus,
            'clientsA1' => $cliA1,
            'clientsA1Source' => $cliA1 === null ? null : ((int) ($m['pendant']['tickets'] ?? 0) > 0 ? 'n1' : 'repli'),
            'clientsPrevus' => $cliPrevu,
            'base' => $base, 'baseSource' => $baseSrc,
            'gain' => $gain,
        ];
    }

    return ['entete' => [
        'base' => $nBase > 0 ? round($tBase, 2) : null,
        'gain' => $nGain > 0 ? round($tGain, 2) : null,
        'attendu' => ($nBase > 0 && $nGain > 0) ? round($tBase + $tGain, 2) : null,
        'pct' => $pct, 'jours' => $jours,
        'clientsA1' => $tCliA1 > 0 ? $tCliA1 : null,
        'clientsPrevus' => $tCliPrevu > 0 ? $tCliPrevu : null,
        'panierDu' => $pDu, 'panierAu' => $pAu,
        'sansBase' => count($perim) - $nBase,
        'motifs' => $motifs,
    ], 'magasins' => $mags];
}
