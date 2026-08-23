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
 */

/** Les trois tables de la mesure. Créées à la volée, comme le reste du cockpit. */
function ensureMesureTables(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_campagne_mesure ('
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
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_campagne_releve ('
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
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_campagne_snapshot ('
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
    try {
        foreach (Db::rows('SELECT oi.sku_ref, COALESCE(ci.label, oi.name) nom
                             FROM mar_campaign_offer co
                             JOIN mar_campaign_offer_item ci ON ci.campaign_offer_id = co.id
                             LEFT JOIN mar_offer_item oi ON oi.id = ci.offer_item_id
                            WHERE co.campaign_id = ?', [$campagneId]) as $r) {
            $sku = trim((string) ($r['sku_ref'] ?? ''));
            if ($sku === '') { continue; }
            $out[$sku] = ['sku' => $sku, 'nom' => (string) ($r['nom'] ?? $sku), 'source' => 'offre'];
        }
    } catch (PDOException $e) { /* offre absente : la saisie libre prend le relais */ }

    foreach (preg_split('/[\r\n]+/', (string) $libre) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '') { continue; }
        if (!preg_match('/^(\S+)\s*(.*)$/u', $ligne, $m)) { continue; }
        $sku = $m[1];
        $out[$sku] = ['sku' => $sku, 'nom' => $m[2] !== '' ? $m[2] : ($out[$sku]['nom'] ?? $sku), 'source' => 'saisie'];
    }
    return array_values($out);
}

/** GET /marketing/mesure — tout l'écran, les trois vues comprises. */
function ep_mesure(): array
{
    ensureMesureTables();
    $motifs = [];

    try {
        $camps = Db::rows('SELECT c.id, c.name, c.starts_on, c.ends_on, c.status_code,
                                  s.label AS statut, t.label AS type_label
                             FROM mar_campaign c
                             LEFT JOIN mar_campaign_status s ON s.code = c.status_code
                             LEFT JOIN mar_campaign_type t ON t.id = c.type_id
                            WHERE c.starts_on IS NOT NULL
                            ORDER BY c.starts_on DESC');
    } catch (PDOException $e) {
        return ['indispo' => true, 'raison' => 'Les tables du module marketing (mar_*) sont absentes de cette base.'];
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
    foreach (Db::rows('SELECT * FROM ceo_campagne_mesure WHERE campagne_id = ?', [$camp['id']]) as $r) { $p = $r; }

    $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    $nomDe = [];
    foreach ($shops as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }

    $perim = [];
    try {
        foreach (Db::rows('SELECT shop_id FROM mar_campaign_shop WHERE campaign_id = ?', [$camp['id']]) as $r) {
            $sid = (string) $r['shop_id'];
            if (isset($nomDe[$sid])) { $perim[] = $sid; }
        }
    } catch (PDOException $e) { /* périmètre absent : réseau entier */ }
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
    foreach (Db::rows('SELECT shop_id, phase, abonnes, releve_le FROM ceo_campagne_releve WHERE campagne_id = ?',
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
    foreach (Db::rows('SELECT kpi, shop_id, valeur, mesure_le FROM ceo_campagne_snapshot WHERE campagne_id = ?',
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
    try {
        $camp = Db::row('SELECT id, name FROM mar_campaign WHERE id = ?', [$id]);
    } catch (PDOException $e) {
        http_response_code(503);
        return ['error' => 'les tables du module marketing sont absentes de cette base'];
    }
    if ($camp === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
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
    $cur = Db::row('SELECT * FROM ceo_campagne_mesure WHERE campagne_id = ?', [$id]) ?? [];
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

    Db::exec('INSERT INTO ceo_campagne_mesure
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
        Db::exec('DELETE FROM ceo_campagne_releve WHERE campagne_id = ? AND shop_id = ? AND phase = ?', [$id, $sid, $phase]);
        return ['ok' => true, 'efface' => true];
    }
    $date = trim((string) ($b['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    Db::exec('INSERT INTO ceo_campagne_releve (campagne_id, shop_id, phase, abonnes, releve_le, maj)
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
        Db::exec('DELETE FROM ceo_campagne_snapshot WHERE campagne_id = ?', [$id]);
        Db::exec('UPDATE ceo_campagne_mesure SET gele_le = NULL WHERE campagne_id = ?', [$id]);
        return ['ok' => true, 'gele' => false];
    }
    $_GET['campagne'] = (string) $id;
    $d = ep_mesure();
    if (!empty($d['indispo']) || empty($d['lignes'])) {
        http_response_code(409);
        return ['error' => 'rien à geler : la fenêtre de référence ne rend aucune donnée'];
    }
    $now = date('Y-m-d H:i:s');
    Db::exec('DELETE FROM ceo_campagne_snapshot WHERE campagne_id = ?', [$id]);
    $pose = static function (string $kpi, string $sid, ?float $v) use ($id, $now) {
        if ($v === null) { return; }
        Db::exec('INSERT INTO ceo_campagne_snapshot (campagne_id, kpi, shop_id, valeur, mesure_le) VALUES (?,?,?,?,?)
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

    Db::exec('INSERT INTO ceo_campagne_mesure (campagne_id, gele_le, maj) VALUES (?,?,?)
              ON DUPLICATE KEY UPDATE gele_le = VALUES(gele_le), maj = VALUES(maj)', [$id, $now, $now]);
    journalAdd('CEO', 'Campagne', (string) ($d['campagne']['nom'] ?? $id),
        'Référence de mesure gelée (' . ($d['fenetres']['ref']['du'] ?? '') . ' → ' . ($d['fenetres']['ref']['au'] ?? '') . ')');
    return ['ok' => true, 'gele' => true, 'le' => $now];
}

/* ─────────────── Sonde : ce que les routes « consultant » rendent ───────────
 *
 * Une liste FIXE de chemins, lus tels quels, et rendus par leur FORME (clés,
 * nombre d'éléments, premier élément tronqué). Aucun chemin ne vient de la
 * requête : une sonde qui accepterait un chemin libre serait un proxy vers
 * toute l'API amont, et ce n'est pas ce qu'on veut ouvrir ici.
 */
function ep_panel_sonde_consultant(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $sid = (int) ($_GET['shop'] ?? 3);
    $du = date('Y-m-01', strtotime('-1 month'));
    $au = date('Y-m-t', strtotime('-1 month'));
    $an = date('Y');

    $chemins = [
        'note-types'        => '/consultant/note-types',
        'shop-notes'        => '/consultant/shops/' . $sid . '/notes',
        'tasks'             => '/consultant/tasks',
        'shop-tasks'        => '/consultant/shops/' . $sid . '/tasks',
        'network-tasks'     => '/consultant/network/tasks',
        'network-ranking'   => '/consultant/network/tasks/ranking',
        'targets'           => '/consultant/targets',
        'shop-targets'      => '/consultant/shops/' . $sid . '/targets',
        'shop-targets-range' => '/consultant/shops/' . $sid . '/targets/range?' . http_build_query(['date_from' => $an . '-01-01', 'date_to' => $an . '-12-31']),
        'pnl-daily'         => '/consultant/shops/' . $sid . '/pnl/daily?' . http_build_query(['date_from' => $du, 'date_to' => $au, 'from' => $du, 'to' => $au]),
        'pnl-monthly-shop'  => '/consultant/shops/' . $sid . '/pnl/monthly?' . http_build_query(['date_from' => $an . '-01-01', 'date_to' => $au]),
        'pnl-monthly-res'   => '/consultant/shops/pnl/monthly?' . http_build_query(['date_from' => $an . '-01-01', 'date_to' => $au]),
        'metric-definitions' => '/consultant/metric-definitions',
        'product-sectors'   => '/consultant/product-sectors',
        'responsibility-areas' => '/consultant/responsibility-areas',
        'material-complaints' => '/consultant/shops/material-complaints',
    ];

    $apercu = static function ($v, int $prof = 0) use (&$apercu) {
        if (!is_array($v)) { return is_scalar($v) ? mb_substr((string) $v, 0, 60) : gettype($v); }
        if (array_is_list($v)) {
            $out = ['liste' => count($v)];
            if ($v && is_array($v[0])) {
                $out['clés'] = array_keys($v[0]);
                if ($prof < 1) { $out['premier'] = $apercu($v[0], $prof + 1); }
            } elseif ($v) { $out['premier'] = $apercu($v[0], $prof + 1); }
            return $out;
        }
        $out = [];
        foreach ($v as $k => $x) {
            if (is_array($x)) { $out[$k] = $prof < 2 ? $apercu($x, $prof + 1) : (array_is_list($x) ? count($x) . ' éléments' : array_keys($x)); }
            else { $out[$k] = is_scalar($x) ? mb_substr((string) $x, 0, 60) : gettype($x); }
        }
        return $out;
    };

    $out = ['magasin' => $sid, 'fenetre' => $du . ' → ' . $au, 'routes' => []];
    foreach (PanelApi::getParallele($chemins) as $nom => $r) {
        $out['routes'][$nom] = ['chemin' => $chemins[$nom],
            'reponse' => $r === null ? 'aucune réponse' : $apercu($r)];
    }
    return $out;
}
