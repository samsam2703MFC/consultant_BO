<?php
declare(strict_types=1);

/*
 * L'assortiment obligatoire et ses saisons.
 *
 * Une référence obligatoire l'est TOUTE L'ANNÉE, ou PENDANT UNE SAISON : une
 * gamme saisonnière du panel (Noël, Automnale, Saint-Nicolas…), récurrente
 * chaque année. Pendant sa saison, elle est exigée en magasin comme les autres :
 * la visite la contrôle, la conformité la compte. Hors saison, elle n'est ni
 * exigée ni signalée manquante — une bûche absente en juillet n'est pas un
 * défaut d'assortiment.
 *
 * Les gammes (dates) sont lues dans la base partagée ; leur contenu (quels
 * produits dans quelle gamme) ne l'est QUE par l'API du panel : la table de
 * liaison de la base est vide (mesuré le 29/09/2026). Le contenu est donc lu
 * à l'API et gardé douze heures.
 */

const AO_CACHE_CLE = 'assortimentGammes';
const AO_CACHE_HEURES = 12;
const AO_MOIS = ['janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
    'juillet' => 7, 'août' => 8, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12];

/** La saison d'une obligatoire : une colonne de plus sur la fiche cockpit. */
function aoEnsure(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    // Lecture vide d'abord : l'ALTER ne part que sur une table d'avant.
    try { Db::rows('SELECT saison_id FROM ceo_prod_product LIMIT 0'); }
    catch (Throwable $e) {
        try { Db::exec('ALTER TABLE ceo_prod_product ADD COLUMN saison_id INT NULL'); } catch (Throwable $e2) { /* déjà là, ou table absente */ }
    }
}

/**
 * Les gammes du panel : dates et état, depuis la base partagée, sinon l'API.
 *
 * @return array<int,array{id:int,nomPanel:string,debut:?string,fin:?string,recurrente:bool,active:bool}>
 */
function aoGammesBrutes(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = [];
    try {
        foreach (Db::rows('SELECT id, name, start_date, end_date, is_recurring, is_active FROM product_availability_period') as $p) {
            $out[(int) $p['id']] = ['id' => (int) $p['id'], 'nomPanel' => trim((string) $p['name']),
                'debut' => $p['start_date'] !== null ? substr((string) $p['start_date'], 0, 10) : null,
                'fin' => $p['end_date'] !== null ? substr((string) $p['end_date'], 0, 10) : null,
                'recurrente' => (int) $p['is_recurring'] === 1, 'active' => (int) $p['is_active'] === 1];
        }
    } catch (Throwable $e) { /* base partagée absente : l'API prend le relais */ }
    if (!$out && class_exists('PanelApi') && PanelApi::configured() && method_exists('PanelApi', 'availabilityPeriods')) {
        foreach (PanelApi::availabilityPeriods() as $p) {
            if (!isset($p['id']) || !is_numeric($p['id'])) { continue; }
            $id = (int) $p['id'];
            $out[$id] = ['id' => $id, 'nomPanel' => trim((string) ($p['name'] ?? '')),
                'debut' => isset($p['start_date']) ? substr((string) $p['start_date'], 0, 10) : null,
                'fin' => isset($p['end_date']) ? substr((string) $p['end_date'], 0, 10) : null,
                'recurrente' => !empty($p['is_recurring']), 'active' => !isset($p['is_active']) || !empty($p['is_active'])];
        }
    }
    foreach ($out as &$g) {
        [$g['emoji'], $g['nom']] = aoNom($g['nomPanel']);
        $g['permanente'] = aoPermanente($g);
    }
    unset($g);
    return $cache = $out;
}

/**
 * « 🎄 Gamme Noël & Nouvel An (Décembre-Janvier) » → ['🎄', 'Noël & Nouvel An'].
 * Le nom du panel porte un pictogramme, « Gamme », des mois entre parenthèses :
 * l'écran veut le nom court, le pictogramme à part.
 *
 * @return array{0:string,1:string}
 */
function aoNom(string $n): array
{
    $n = trim($n);
    $emoji = '';
    if (preg_match('/^([^\p{L}\p{N}]+)(.*)$/u', $n, $m)) { $emoji = trim($m[1]); $n = trim($m[2]); }
    $n = preg_replace('/^Ic[ôo]ne\s*[-–]\s*/iu', '', $n) ?? $n;
    $n = preg_replace('/^Gamme\s+/iu', '', $n) ?? $n;
    $n = trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $n) ?? $n);
    $n = trim(explode(' – ', $n)[0]);
    return [$emoji, $n !== '' ? $n : trim($emoji)];
}

/** Une gamme qui couvre l'année (Standard, B2B) n'est pas une saison. */
function aoPermanente(array $g): bool
{
    if ($g['debut'] === null || $g['fin'] === null) { return true; }
    $d = substr($g['debut'], 5, 5); $f = substr($g['fin'], 5, 5);
    $an = (strtotime($g['fin']) - strtotime($g['debut'])) >= 364 * 86400;
    // Récurrente : l'année entière (01/01 → 31/12), ou une date qui revient sur elle-même un an plus
    // tard ou plus (B2B : 17/03/2026 → 17/03/2036). Une gamme d'un seul jour reste une saison ;
    // « Fête des Mères » saisie du 07/05/2025 au 14/05/2026 reste la semaine du 07/05 au 14/05.
    if ($g['recurrente']) { return ($d === '01-01' && $f === '12-31') || ($d === $f && $an); }
    return $an;
}

/** Une date « année-MM-JJ » valide (le 29 février d'une année courte devient le 28). */
function aoDate(int $annee, string $md): string
{
    [$m, $j] = array_map('intval', explode('-', $md));
    if (!checkdate($m, $j, $annee)) { $j = (int) date('t', mktime(0, 0, 0, $m, 1, $annee)); }
    return sprintf('%04d-%02d-%02d', $annee, $m, $j);
}

/**
 * La fenêtre d'une saison autour d'un jour : en cours, sinon la prochaine.
 * Une gamme récurrente ne compte que le jour et le mois ; l'hiver passe l'année.
 * La fin est comprise.
 *
 * @return array{du:string,au:string,ouverte:bool,jours:int}|null  null pour une gamme permanente
 */
function aoFenetre(array $g, string $jour): ?array
{
    if (!empty($g['permanente'])) { return null; }
    $t = strtotime($jour);
    if (!$g['recurrente']) {
        $du = $g['debut']; $au = $g['fin'];
        // Terminée et jamais récurrente : elle ne rouvrira pas.
        if ($jour > $au) { return ['du' => $du, 'au' => $au, 'ouverte' => false, 'jours' => 0, 'passee' => true]; }
        $ouverte = $jour >= $du;
        return ['du' => $du, 'au' => $au, 'ouverte' => $ouverte,
            'jours' => (int) round((strtotime($ouverte ? $au : $du) - $t) / 86400)];
    }
    $md1 = substr($g['debut'], 5, 5); $md2 = substr($g['fin'], 5, 5);
    $y = (int) substr($jour, 0, 4);
    foreach ([$y - 1, $y, $y + 1] as $a) {
        $du = aoDate($a, $md1);
        $au = aoDate($md2 < $md1 ? $a + 1 : $a, $md2);
        if ($au >= $jour) {
            $ouverte = $du <= $jour;
            return ['du' => $du, 'au' => $au, 'ouverte' => $ouverte,
                'jours' => (int) round((strtotime($ouverte ? $au : $du) - $t) / 86400)];
        }
    }
    return null;
}

/**
 * Une date de début dont le jour et le mois semblent inversés au panel :
 * « Glace (Avril - Septembre) » qui commence le 04/01. Signalé, jamais corrigé.
 */
function aoAlerteDates(array $g): ?string
{
    if ($g['debut'] === null || !preg_match('/\(([^)]*)\)/u', $g['nomPanel'], $m)) { return null; }
    $premier = null;
    if (preg_match_all('/\p{L}+/u', mb_strtolower($m[1]), $mm)) {
        foreach ($mm[0] as $w) { if (isset(AO_MOIS[$w])) { $premier = AO_MOIS[$w]; break; } }
    }
    if ($premier === null) { return null; }
    $mois = (int) substr($g['debut'], 5, 2); $j = (int) substr($g['debut'], 8, 2);
    if ($mois !== $premier && $j === $premier) {
        return sprintf('%s %s commence le %02d/%02d alors que son nom dit « %s » : jour et mois inversés au panel ?',
            $g['emoji'], $g['nom'], $j, $mois, array_search($premier, AO_MOIS, true));
    }
    return null;
}

/**
 * Le contenu des gammes : identifiant produit du panel → gammes.
 * API du panel (seule source en service), gardée douze heures ; à défaut, la
 * table de liaison de la base. Une gamme que le panel ne rend pas garde sa
 * dernière lecture réussie.
 *
 * @return array{le:?string,source:?string,gammes:array<string,array{lu:bool,ids:list<int>,erreur:?string}>}
 */
function aoContenu(bool $forcer = false, bool $cacheSuffit = false): array
{
    $c = setting(AO_CACHE_CLE);
    $c = is_array($c) ? $c : ['le' => null, 'source' => null, 'gammes' => []];
    $frais = !empty($c['le']) && strtotime((string) $c['le']) > time() - AO_CACHE_HEURES * 3600;
    if ($frais && !$forcer) { return $c; }
    // Le catalogue se charge au démarrage du cockpit : il se contente d'une lecture
    // ancienne plutôt que d'attendre le panel (l'écran des saisons, lui, rafraîchit).
    $luQuelque = static fn (array $x) => (bool) array_filter($x['gammes'] ?? [], static fn ($g) => !empty($g['lu']));
    if ($cacheSuffit && !$forcer && $luQuelque($c)) { return $c; }

    $gammes = aoGammesBrutes();
    $neuf = ['le' => date('Y-m-d H:i:s'), 'source' => null, 'gammes' => []];
    if ($gammes && class_exists('PanelApi') && PanelApi::configured()) {
        $chemins = [];
        foreach ($gammes as $id => $g) { if ($g['active']) { $chemins[(string) $id] = '/product-availability-periods/' . $id . '/products'; } }
        $rep = PanelApi::getParallele($chemins, 4);
        // Tout muet : souvent un jeton expiré, que la lecture en parallèle ne renouvelle pas. Une
        // lecture simple (qui se reconnecte) sur une gamme ; si elle répond, on relit toutes les gammes.
        if ($chemins && !array_filter($rep, static fn ($r) => is_array($r)) && method_exists('PanelApi', 'periodProducts')) {
            if (PanelApi::periodProducts((int) array_key_first($chemins)) !== []) { $rep = PanelApi::getParallele($chemins, 4); }
        }
        foreach ($chemins as $id => $ch) {
            $r = $rep[$id] ?? null;
            $l = is_array($r) ? (method_exists('PanelApi', 'liste') ? PanelApi::liste($r) : (array_is_list($r) ? $r : [])) : null;
            if ($l === null) {
                $ancien = $c['gammes'][$id] ?? null;
                $neuf['gammes'][$id] = $ancien !== null && !empty($ancien['lu'])
                    ? array_merge($ancien, ['erreur' => 'panel muet — lecture du ' . substr((string) ($ancien['luLe'] ?? $c['le']), 0, 10) . ' gardée'])
                    : ['lu' => false, 'ids' => [], 'erreur' => 'le panel ne rend pas les produits de cette gamme'];
                continue;
            }
            $ids = [];
            foreach ($l as $p) {
                foreach (['id', 'id_product', 'product_id'] as $k) {
                    if (isset($p[$k]) && is_numeric($p[$k])) { $ids[] = (int) $p[$k]; break; }
                }
            }
            $neuf['gammes'][$id] = ['lu' => true, 'ids' => array_values(array_unique($ids)), 'erreur' => null, 'luLe' => $neuf['le']];
        }
        $neuf['source'] = 'api';
    }
    if (!$neuf['gammes'] || !array_filter($neuf['gammes'], static fn ($g) => $g['ids'] !== [])) {
        // Repli : la table de liaison de la base (vide en service, remplie au banc).
        try {
            $parG = [];
            foreach (Db::rows('SELECT id_product, id_period FROM product_availability_period_connection') as $k) {
                $parG[(string) (int) $k['id_period']][] = (int) $k['id_product'];
            }
            if ($parG) {
                $neuf['gammes'] = [];
                foreach ($gammes as $id => $g) { $neuf['gammes'][(string) $id] = ['lu' => true, 'ids' => $parG[(string) $id] ?? [], 'erreur' => null, 'luLe' => $neuf['le']]; }
                $neuf['source'] = 'base';
            }
        } catch (Throwable $e) { /* pas de liaison lisible */ }
    }
    if (!$neuf['gammes']) { return $c; }
    // Rien de lu : on le garde un quart d'heure seulement, pour réessayer bientôt sans marteler le panel.
    if (!$luQuelque($neuf)) { $neuf['le'] = date('Y-m-d H:i:s', time() - AO_CACHE_HEURES * 3600 + 900); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [AO_CACHE_CLE, json_encode($neuf, JSON_UNESCAPED_UNICODE)]);
    return $neuf;
}

/** @return array<int,list<int>> identifiant produit du panel → gammes */
function aoParProduit(array $contenu): array
{
    $out = [];
    foreach ($contenu['gammes'] as $gid => $g) { foreach ($g['ids'] as $pid) { $out[(int) $pid][] = (int) $gid; } }
    return $out;
}

/** Saison de chaque obligatoire saisonnière : ref → saison_id. */
function aoSaisonsFiches(): array
{
    aoEnsure();
    $out = [];
    try {
        foreach (Db::rows('SELECT ref, saison_id FROM ceo_prod_product WHERE saison_id IS NOT NULL') as $r) {
            $out[(string) $r['ref']] = (int) $r['saison_id'];
        }
    } catch (Throwable $e) { /* colonne absente : tout est « toute l'année » */ }
    return $out;
}

/** Exigée ce jour-là ? Toute l'année : oui. Saisonnière : pendant sa saison seulement. */
function aoExigible(bool $must, ?int $saison, string $jour, array $gammes): bool
{
    if (!$must) { return false; }
    if ($saison === null) { return true; }
    $g = $gammes[$saison] ?? null;
    // Saison disparue ou désactivée au panel : plus rien à exiger (l'écran le dit, et propose d'en choisir une autre).
    if ($g === null || !$g['active']) { return false; }
    $f = aoFenetre($g, $jour);
    return $f !== null ? $f['ouverte'] : true;   // gamme devenue permanente : toute l'année
}

/**
 * Le catalogue, complété : gammes du produit, saison de l'obligatoire, exigible aujourd'hui.
 * Ajoute `saisons` (gammes saisonnières du produit), `standard` (il est aussi dans une
 * gamme permanente), `saisonnier`, `saison`, `exigible`, `saisonFenetre` ; remplit
 * `periods` (noms des gammes) quand la base n'a pu le faire.
 */
function aoEnrichir(array $cat, ?string $jour = null): array
{
    $jour = $jour ?? date('Y-m-d');
    // Chaque source à part : un panel muet ne doit pas faire disparaître la saison des obligatoires,
    // sans quoi le premier minimum saisi la réécrirait « toute l'année ».
    $sf = aoSaisonsFiches();
    try { $gammes = aoGammesBrutes(); } catch (Throwable $e) { $gammes = []; }
    try { $parP = aoParProduit(aoContenu(false, true)); } catch (Throwable $e) { $parP = []; }
    foreach ($cat as &$p) {
        $pid = isset($p['pwaId']) && $p['pwaId'] !== null ? (int) $p['pwaId'] : (ctype_digit((string) $p['ref']) ? (int) $p['ref'] : 0);
        $gs = array_values(array_filter($parP[$pid] ?? [], static fn ($g) => isset($gammes[$g]) && $gammes[$g]['active']));
        $sais = array_values(array_filter($gs, static fn ($g) => !$gammes[$g]['permanente']));
        $p['saisons'] = $sais;
        $p['standard'] = count($sais) < count($gs);
        $p['saisonnier'] = $sais !== [] && !$p['standard'];
        if (empty($p['periods']) || !is_array($p['periods'])) {
            $p['periods'] = array_map(static fn ($g) => trim($gammes[$g]['emoji'] . ' ' . $gammes[$g]['nom']), $gs);
        }
        $s = !empty($p['must']) ? ($sf[(string) $p['ref']] ?? null) : null;
        if ($s !== null && isset($gammes[$s]) && $gammes[$s]['permanente']) { $s = null; }
        $p['saison'] = $s;
        $p['exigible'] = aoExigible(!empty($p['must']), $s, $jour, $gammes);
        $p['saisonFenetre'] = $s !== null && isset($gammes[$s]) ? aoFenetre($gammes[$s], $jour) : null;
    }
    unset($p);
    return $cat;
}

/**
 * Les obligatoires exigées un jour donné (visites, conformité), et celles hors saison.
 *
 * @return array{exigibles:list<array{ref:string,nom:string,pwa:?string,saison:?int}>,horsSaison:list<array{ref:string,nom:string,saison:int}>}
 */
function aoObligatoires(string $jour): array
{
    aoEnsure();
    $gammes = aoGammesBrutes();
    $out = ['exigibles' => [], 'horsSaison' => []];
    try {
        foreach (Db::rows('SELECT ref, nom, pwa_id, saison_id FROM ceo_prod_product WHERE must = 1 AND actif = 1 ORDER BY nom') as $r) {
            $s = $r['saison_id'] !== null ? (int) $r['saison_id'] : null;
            $pwa = $r['pwa_id'] === null || (string) $r['pwa_id'] === '' ? null : (string) $r['pwa_id'];
            if (aoExigible(true, $s, $jour, $gammes)) {
                $f = $s !== null && isset($gammes[$s]) ? aoFenetre($gammes[$s], $jour) : null;
                $out['exigibles'][] = ['ref' => (string) $r['ref'], 'nom' => (string) $r['nom'], 'pwa' => $pwa, 'saison' => $s,
                    'ouverteLe' => $f !== null ? $f['du'] : null];
            } else {
                $out['horsSaison'][] = ['ref' => (string) $r['ref'], 'nom' => (string) $r['nom'], 'saison' => (int) $s];
            }
        }
    } catch (Throwable $e) { /* pas de catalogue de production : la conformité se taira */ }
    return $out;
}

/**
 * GET /production/saisons[?date=YYYY-MM-DD][&rafraichir=1]
 * Les gammes saisonnières du panel, leur fenêtre autour du jour, leurs produits
 * (références du catalogue) et les obligatoires qui s'y rattachent.
 */
function ep_prod_saisons(): array
{
    aoEnsure();
    $jour = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) { http_response_code(400); return ['error' => 'date au format AAAA-MM-JJ']; }
    $gammes = aoGammesBrutes();
    $contenu = aoContenu(!empty($_GET['rafraichir']));

    // Identifiant du panel → référence du catalogue (la fiche cockpit peut porter une autre référence).
    $refDe = [];
    try { foreach (Db::rows('SELECT ref, pwa_id FROM ceo_prod_product WHERE pwa_id IS NOT NULL') as $r) { $refDe[(int) $r['pwa_id']] = (string) $r['ref']; } }
    catch (Throwable $e) { /* références = identifiants du panel */ }

    $oblig = []; $annee = 0; $exig = 0; $inconnue = 0;
    try {
        foreach (Db::rows('SELECT ref, saison_id FROM ceo_prod_product WHERE must = 1 AND actif = 1') as $r) {
            $s = $r['saison_id'] !== null ? (int) $r['saison_id'] : null;
            if ($s === null || (isset($gammes[$s]) && $gammes[$s]['permanente'])) { $annee++; }
            else { $oblig[$s] = ($oblig[$s] ?? 0) + 1; if (!isset($gammes[$s]) || !$gammes[$s]['active']) { $inconnue++; } }
            if (aoExigible(true, $s, $jour, $gammes)) { $exig++; }
        }
    } catch (Throwable $e) { /* pas de fiches cockpit */ }

    $saisons = []; $permanentes = []; $alertes = [];
    foreach ($gammes as $id => $g) {
        if (!$g['active']) { continue; }
        $c = $contenu['gammes'][(string) $id] ?? null;
        $refs = $c !== null ? array_map(static fn ($pid) => $refDe[$pid] ?? (string) $pid, $c['ids']) : [];
        if ($g['permanente']) { $permanentes[] = ['id' => $id, 'emoji' => $g['emoji'], 'nom' => $g['nom'], 'produits' => count($refs)]; continue; }
        $fen = aoFenetre($g, $jour);
        if ($fen !== null && !empty($fen['passee'])) { continue; }   // non récurrente et terminée : ne rouvrira pas
        $al = aoAlerteDates($g);
        if ($al !== null) { $alertes[] = $al; }
        if ($c !== null && !$c['lu']) { $alertes[] = $g['emoji'] . ' ' . $g['nom'] . ' : ' . ($c['erreur'] ?? 'produits non lus'); }
        $saisons[] = ['id' => $id, 'emoji' => $g['emoji'], 'nom' => $g['nom'], 'nomPanel' => $g['nomPanel'],
            'debut' => $g['debut'] !== null ? substr($g['debut'], 5, 5) : null, 'fin' => $g['fin'] !== null ? substr($g['fin'], 5, 5) : null,
            'debutDate' => $g['debut'], 'finDate' => $g['fin'],
            'recurrente' => $g['recurrente'], 'fenetre' => $fen,
            'produits' => $refs, 'lu' => $c !== null && $c['lu'], 'alerte' => $al, 'obligatoires' => $oblig[$id] ?? 0];
    }
    // Les saisons ouvertes d'abord (celle qui ferme le plus tôt en tête), puis celles qui ouvrent.
    usort($saisons, static function ($a, $b) {
        $fa = $a['fenetre']; $fb = $b['fenetre'];
        if ($fa === null || $fb === null) { return ($fa === null) <=> ($fb === null); }
        return ($fb['ouverte'] <=> $fa['ouverte']) ?: ($fa['jours'] <=> $fb['jours']);
    });
    if ($inconnue > 0) { $alertes[] = $inconnue . ' obligatoire(s) rattachée(s) à une saison désactivée ou disparue au panel : elles ne sont plus exigées.'; }
    return ['aujourdhui' => $jour, 'saisons' => $saisons, 'permanentes' => $permanentes,
        'obligatoires' => ['annee' => $annee, 'parSaison' => (object) $oblig, 'exigibles' => $exig, 'saisonInconnue' => $inconnue],
        'alertes' => $alertes, 'contenuLe' => $contenu['le'], 'contenuSource' => $contenu['source'],
        'source' => 'gammes saisonnières du panel (dates : base partagée ; produits : API du panel, gardés ' . AO_CACHE_HEURES . ' h)'];
}

/**
 * PUT /production/obligatoire/{ref} — {must: bool, saison: id|null, qmin?: int}
 * Ne touche que l'obligatoire, sa saison et son minimum : la fiche de production
 * (prix, coût, temps) reste ce qu'elle est — la route de la fiche réécrit tout.
 */
function wr_prod_obligatoire(string $ref): array
{
    aoEnsure();
    $b = body();
    $ref = trim($ref);
    if ($ref === '' || !preg_match('/^[\w-]{1,24}$/', $ref)) { http_response_code(400); return ['error' => 'référence requise']; }
    $must = !empty($b['must']);
    $ancien = null;
    try { $ancien = Db::row('SELECT qmin, saison_id FROM ceo_prod_product WHERE ref = ?', [$ref]); } catch (Throwable $e) { /* table absente */ }
    $saison = null;
    if ($must && !array_key_exists('saison', $b)) {
        // Sans clé « saison » (une quantité seule), la saison en place est gardée.
        $saison = $ancien !== null && $ancien['saison_id'] !== null ? (int) $ancien['saison_id'] : null;
    } elseif ($must && $b['saison'] !== null && $b['saison'] !== '') {
        if (!is_numeric($b['saison'])) { http_response_code(422); return ['error' => 'saison inconnue']; }
        $saison = (int) $b['saison'];
        $g = aoGammesBrutes()[$saison] ?? null;
        if ($g === null || !$g['active']) { http_response_code(422); return ['error' => 'saison inconnue du panel']; }
        $fen = aoFenetre($g, date('Y-m-d'));
        if ($fen !== null && !empty($fen['passee'])) { http_response_code(422); return ['error' => 'cette gamme est terminée et ne rouvrira pas']; }
        if ($g['permanente']) { $saison = null; }   // « Standard » = toute l'année
    }
    $qmin = 0;
    if ($must) {
        $q = $b['qmin'] ?? ($ancien['qmin'] ?? 0);
        if (!is_numeric($q) || (float) $q < 0 || (float) $q > 9999) { http_response_code(422); return ['error' => 'quantité minimale : un nombre entre 0 et 9 999']; }
        $qmin = (int) round((float) $q);
    }

    // Intitulé et catégorie depuis le catalogue, comme la fiche : la ligne doit se relire sans la base partagée.
    $nom = ''; $cat = '';
    try {
        $p = Db::rows('SELECT p.name, c.name AS cat FROM product p LEFT JOIN product_category c ON c.id = p.id_category WHERE p.id = ?', [(int) $ref]);
        if ($p) { $nom = trim((string) $p[0]['name']); $cat = (string) ($p[0]['cat'] ?? ''); }
    } catch (Throwable $e) { /* catalogue indisponible */ }
    if ($nom === '') { $nom = trim((string) ($b['nom'] ?? $ref)); }

    Db::exec('INSERT INTO ceo_prod_product (ref, nom, categorie, pwa_id, must, qmin, saison_id, actif) VALUES (?,?,?,?,?,?,?,1)
              ON DUPLICATE KEY UPDATE must = VALUES(must), qmin = VALUES(qmin), saison_id = VALUES(saison_id)',
        [$ref, mb_substr($nom, 0, 160), mb_substr($cat, 0, 60), ctype_digit($ref) ? (int) $ref : null, $must ? 1 : 0, $qmin, $saison]);

    $gammes = aoGammesBrutes();
    $g = $saison !== null ? ($gammes[$saison] ?? null) : null;
    $quand = !$must ? 'retirée de l’assortiment obligatoire'
        : ($g !== null ? 'obligatoire pendant ' . $g['nom'] : 'obligatoire toute l’année') . ($qmin > 0 ? ' — minimum ' . $qmin : '');
    journalAdd('CEO', 'Assortiment obligatoire', $nom, $quand);
    $jour = date('Y-m-d');
    return ['ok' => true, 'ref' => $ref, 'must' => $must, 'qmin' => $qmin, 'saison' => $saison,
        'exigible' => aoExigible($must, $saison, $jour, $gammes), 'saisonFenetre' => $g !== null ? aoFenetre($g, $jour) : null];
}
