<?php
declare(strict_types=1);

/**
 * Table KPI — le magasin de valeurs du réseau.
 *
 * Trois pièces :
 *  - l'ENCODAGE : chaque KPI se déclare à l'écran, rangé par catégorie ›
 *    sous-catégorie, avec sa SOURCE — l'endpoint de l'application qui le
 *    fournit, la liste et le champ à lire, la maille (jour ou mois) ;
 *  - le COLLECTEUR : au battement du cron horaire, chaque KPI « endpoint »
 *    est lu UNE fois et ses valeurs rangées dans `ceo_kpi_valeur`
 *    (kpi × magasin × période, plus la ligne réseau `*`). L'historique
 *    s'accumule tout seul — les rapports et les écrans lisent la table,
 *    plus personne ne recalcule dans son coin ;
 *  - la SONDE : avant d'enregistrer, l'endpoint choisi est appelé en vrai et
 *    la réponse inspectée — les champs numériques par magasin se proposent
 *    dans une liste, on ne tape pas un chemin à l'aveugle.
 *
 * Le référentiel reste `ceo_kpi_def` (seuils, sens, actif) : on lui ajoute
 * la catégorie, la sous-catégorie et la source. Les KPI historiques
 * (type bloc / derive) continuent leur vie sans y toucher.
 */

/** Les endpoints OFFERTS comme source — des GET JSON sans effet de bord. */
function kpiEndpointsOfferts(): array
{
    return [
        '/exploitation/reseau?periode=jour'    => 'Exploitation réseau — la journée (CA, tickets, panier, articles)',
        '/exploitation/reseau?periode=semaine' => 'Exploitation réseau — la semaine en cours',
        '/exploitation/reseau?periode=mois'    => 'Exploitation réseau — le mois en cours',
        '/pwa/tasks/heatmap/mois'              => 'Suivi des tâches — le mois en cours (part faite par magasin)',
        '/stores/kpis-annuels'                 => 'KPIs annuels — clients/jour, panier, articles par mois',
        '/produits/manque'                     => 'Manque à gagner — par magasin',
        '/reputation'                          => 'Réputation Google — notes et avis',
    ];
}

const KPI_TABLE_SCHEMA = 2;

function kpiTableTables(): void
{
    // Le DDL ne se rejoue PAS à chaque requête : un ALTER même sans effet
    // prend un verrou de métadonnées — si une requête traîne sur la table,
    // tout le monde s'empile derrière et l'application entière s'étouffe.
    // Une version de schéma en réglage, un SELECT par requête, rien de plus.
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    if ((int) setting('kpiTableSchema', 0) >= KPI_TABLE_SCHEMA) { return; }
    ensureKpiDefs();
    foreach (['ADD COLUMN categorie VARCHAR(60) NULL', 'ADD COLUMN sous_categorie VARCHAR(60) NULL',
              'ADD COLUMN source TEXT NULL', "ADD COLUMN agregat VARCHAR(10) NOT NULL DEFAULT 'somme'",
              'ADD COLUMN unite VARCHAR(20) NULL', 'ADD COLUMN au_rapport TINYINT NOT NULL DEFAULT 1',
              'ADD COLUMN echelle TEXT NULL'] as $a) {
        try { Db::exec('ALTER TABLE ceo_kpi_def ' . $a); } catch (PDOException $e) { /* déjà là */ }
    }
    // La FICHE MAGASIN : les données statiques d'un magasin (m², places
    // assises…), saisies une fois, utilisables comme opérandes des KPI
    // composés. Une ligne par magasin × attribut.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_magasin_fiche ('
        . 'id_shop INT NOT NULL,'
        . 'cle VARCHAR(40) NOT NULL,'
        . 'libelle VARCHAR(80) NOT NULL,'
        . 'valeur DECIMAL(14,4) NULL,'
        . 'PRIMARY KEY (id_shop, cle)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_kpi_valeur ('
        . 'code VARCHAR(40) NOT NULL,'
        . "shop VARCHAR(20) NOT NULL,"          // id magasin, ou * pour le réseau
        . 'jour DATE NOT NULL,'                 // la clé de période (1er du mois en maille mensuelle)
        . 'valeur DECIMAL(16,4) NULL,'
        . 'maj DATETIME NOT NULL,'
        . 'PRIMARY KEY (code, shop, jour),'
        . 'KEY idx_kv_code_jour (code, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    kpiTableSemer();
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['kpiTableSchema', json_encode(KPI_TABLE_SCHEMA)]);
}

/** Les premiers KPI encodés d'office — sources vérifiées, modifiables ensuite. */
function kpiTableSemer(): void
{
    $seed = [
        ['tk-ca-jour', 'CA de la journée', 'Ventes', 'Trafic', '€', 'somme',
            ['type' => 'endpoint', 'endpoint' => '/exploitation/reseau?periode=jour', 'liste' => 'magasins', 'cleShop' => 'shopId', 'champ' => 'n', 'grain' => 'jour']],
        ['tk-tickets-jour', 'Tickets de la journée', 'Ventes', 'Trafic', 'n', 'somme',
            ['type' => 'endpoint', 'endpoint' => '/exploitation/reseau?periode=jour', 'liste' => 'magasins', 'cleShop' => 'shopId', 'champ' => 'tickets', 'grain' => 'jour']],
        ['tk-panier-jour', 'Panier moyen', 'Ventes', 'Récurrence', '€', 'moyenne',
            ['type' => 'endpoint', 'endpoint' => '/exploitation/reseau?periode=jour', 'liste' => 'magasins', 'cleShop' => 'shopId', 'champ' => 'panier', 'grain' => 'jour']],
        ['tk-ca-mois', 'CA du mois (cumul)', 'Ventes', 'Trafic', '€', 'somme',
            ['type' => 'endpoint', 'endpoint' => '/exploitation/reseau?periode=mois', 'liste' => 'magasins', 'cleShop' => 'shopId', 'champ' => 'n', 'grain' => 'mois']],
        ['tk-taches-faites', 'Tâches faites (panel)', 'Opérations', 'Tâches & contrôles', '%', 'moyenne',
            ['type' => 'endpoint', 'endpoint' => '/pwa/tasks/heatmap/mois', 'liste' => 'lignes', 'cleShop' => 'shopId', 'champ' => 'part', 'grain' => 'mois']],
        // Le premier COMPOSÉ : le panier réseau PONDÉRÉ (CA ÷ tickets), le
        // vrai — pas la moyenne des paniers, où un petit magasin pèse autant
        // qu'un grand.
        ['tk-panier-pondere', 'Panier moyen pondéré (CA ÷ tickets)', 'Ventes', 'Récurrence', '€', 'formule',
            ['type' => 'compose', 'a' => 'tk-ca-jour', 'op1' => '/', 'b' => 'tk-tickets-jour', 'op2' => '', 'c' => '', 'grain' => 'jour']],
    ];
    foreach ($seed as [$code, $nom, $cat, $sscat, $unite, $agg, $src]) {
        if (Db::row('SELECT id FROM ceo_kpi_def WHERE code = ?', [$code]) !== null) { continue; }
        Db::exec('INSERT INTO ceo_kpi_def (code, nom, levier, calcul, sens, sortie, actif, ordre, categorie, sous_categorie, source, agregat, unite, au_rapport)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
            [$code, $nom, 'transverse', json_encode(['type' => 'table']), 'bas', 'tableau', 1, 50,
             $cat, $sscat, json_encode($src, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $agg, $unite]);
    }
}

/**
 * Appelle un endpoint INTERNE (le même chemin que le front) et rend le JSON.
 * Chemins offerts seulement — jamais un PDF, jamais un POST.
 */
function kpiAppelEndpoint(string $url): ?array
{
    $offerts = kpiEndpointsOfferts();
    $connu = isset($offerts[$url]);
    $p = parse_url($url);
    $chemin = (string) ($p['path'] ?? '');
    if (!$connu || $chemin === '' || str_contains($chemin, '.pdf')) { return null; }
    $get = [];
    parse_str((string) ($p['query'] ?? ''), $get);
    $avant = $_GET;
    $_GET = $get;
    try {
        $d = route('GET', $chemin);
        return is_array($d) ? $d : null;
    } catch (Throwable $e) {
        return null;
    } finally { $_GET = $avant; }
}

/** Lit une source {endpoint, liste, cleShop, champ} → [shopId => valeur]. */
function kpiSourceLit(array $src): ?array
{
    $d = kpiAppelEndpoint((string) ($src['endpoint'] ?? ''));
    if ($d === null) { return null; }
    $liste = $d[(string) ($src['liste'] ?? '')] ?? null;
    if (!is_array($liste)) { return null; }
    $out = [];
    foreach ($liste as $item) {
        if (!is_array($item)) { continue; }
        $sid = (string) ($item[(string) ($src['cleShop'] ?? '')] ?? '');
        $v = $item[(string) ($src['champ'] ?? '')] ?? null;
        if ($sid === '' || !is_numeric($v)) { continue; }
        $out[$sid] = (float) $v;
    }
    return $out === [] ? null : $out;
}

/** La clé de période d'un grain : le jour même, ou le 1er du mois. */
function kpiClePeriode(string $grain): string
{
    return $grain === 'mois' ? date('Y-m-01') : date('Y-m-d');
}

/**
 * La COLLECTE : chaque KPI « endpoint » actif est lu une fois, ses valeurs
 * écrites par magasin plus la ligne réseau `*` (somme ou moyenne, selon le
 * KPI). Rejouée à chaque battement : la valeur du jour se raffine au fil des
 * passages, la dernière écriture de la journée fait foi.
 */
function kpiCollecte(): array
{
    kpiTableTables();
    // Le périmètre est celui des magasins ACTIFS : une source peut rendre des
    // boutiques de plus (fermées, techniques) — elles fausseraient sommes et
    // moyennes réseau sans exister nulle part ailleurs dans le cockpit.
    $actifs = [];
    try {
        foreach (Db::rows('SELECT id FROM shops WHERE active = 1') as $s) { $actifs[(string) $s['id']] = true; }
    } catch (PDOException $e) { /* pas de filtre */ }
    $faits = 0; $rates = [];
    foreach (Db::rows('SELECT * FROM ceo_kpi_def WHERE actif = 1 AND source IS NOT NULL') as $def) {
        $src = json_decode((string) $def['source'], true);
        if (!is_array($src) || ($src['type'] ?? '') !== 'endpoint') { continue; }
        $vals = kpiSourceLit($src);
        if ($vals === null) { $rates[] = (string) $def['code']; continue; }
        if ($actifs !== []) { $vals = array_filter($vals, fn ($sid) => isset($actifs[(string) $sid]), ARRAY_FILTER_USE_KEY); }
        if ($vals === []) { $rates[] = (string) $def['code']; continue; }
        $cle = kpiClePeriode((string) ($src['grain'] ?? 'jour'));
        foreach ($vals as $sid => $v) {
            Db::exec('INSERT INTO ceo_kpi_valeur (code, shop, jour, valeur, maj) VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), maj = VALUES(maj)',
                [(string) $def['code'], (string) $sid, $cle, $v, date('Y-m-d H:i:s')]);
        }
        $reseau = ((string) $def['agregat']) === 'moyenne'
            ? array_sum($vals) / max(1, count($vals)) : array_sum($vals);
        Db::exec('INSERT INTO ceo_kpi_valeur (code, shop, jour, valeur, maj) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), maj = VALUES(maj)',
            [(string) $def['code'], '*', $cle, round($reseau, 4), date('Y-m-d H:i:s')]);
        $faits++;
    }
    // Les composés après les endpoints : leurs opérandes viennent d'être posées.
    $c2 = kpiCollecteComposes($actifs);
    return ['collectes' => $faits + $c2['faits'], 'rates' => array_merge($rates, $c2['rates'])];
}

/** Une opérande de KPI composé : un code de KPI, ou `fiche:<cle>`. */
function kpiOperande(string $ref, string $shop): ?float
{
    if (str_starts_with($ref, 'fiche:')) {
        if ($shop === '*') {
            $r = Db::row('SELECT SUM(valeur) v FROM ceo_magasin_fiche WHERE cle = ?', [substr($ref, 6)]);
        } else {
            $r = Db::row('SELECT valeur v FROM ceo_magasin_fiche WHERE cle = ? AND id_shop = ?', [substr($ref, 6), (int) $shop]);
        }
        return isset($r['v']) && $r['v'] !== null ? (float) $r['v'] : null;
    }
    $r = Db::row('SELECT valeur v FROM ceo_kpi_valeur WHERE code = ? AND shop = ? ORDER BY jour DESC LIMIT 1', [$ref, $shop]);
    return isset($r['v']) && $r['v'] !== null ? (float) $r['v'] : null;
}

/** Applique un opérateur — la division par zéro rend null, jamais l'infini. */
function kpiOpere(?float $a, string $op, ?float $b): ?float
{
    if ($a === null || $b === null) { return null; }
    return match ($op) {
        '/' => abs($b) < 1e-9 ? null : $a / $b,
        '*' => $a * $b,
        '+' => $a + $b,
        '-' => $a - $b,
        default => null,
    };
}

/**
 * La passe des KPI COMPOSÉS : chaque formule (A op B [op C]) s'évalue par
 * magasin ET pour le réseau — la ligne réseau applique la formule aux
 * valeurs réseau des opérandes, ce qui donne les ratios pondérés justes
 * (CA ÷ tickets = le vrai panier réseau, pas la moyenne des paniers).
 */
function kpiCollecteComposes(array $actifs): array
{
    $faits = 0; $rates = [];
    foreach (Db::rows('SELECT * FROM ceo_kpi_def WHERE actif = 1 AND source IS NOT NULL') as $def) {
        $src = json_decode((string) $def['source'], true);
        if (!is_array($src) || ($src['type'] ?? '') !== 'compose') { continue; }
        $cle = kpiClePeriode((string) ($src['grain'] ?? 'jour'));
        $shops = $actifs !== [] ? array_keys($actifs) : [];
        $ecrits = 0;
        foreach (array_merge($shops, ['*']) as $sid) {
            $v = kpiOpere(kpiOperande((string) ($src['a'] ?? ''), (string) $sid),
                (string) ($src['op1'] ?? '/'), kpiOperande((string) ($src['b'] ?? ''), (string) $sid));
            if (($src['c'] ?? '') !== '' && $src['c'] !== null) {
                $v = kpiOpere($v, (string) ($src['op2'] ?? '/'), kpiOperande((string) $src['c'], (string) $sid));
            }
            if ($v === null) { continue; }
            Db::exec('INSERT INTO ceo_kpi_valeur (code, shop, jour, valeur, maj) VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), maj = VALUES(maj)',
                [(string) $def['code'], (string) $sid, $cle, round($v, 4), date('Y-m-d H:i:s')]);
            $ecrits++;
        }
        if ($ecrits === 0) { $rates[] = (string) $def['code']; } else { $faits++; }
    }
    return ['faits' => $faits, 'rates' => $rates];
}

/** L'échelle en crans : la valeur face aux 4 bornes → −− à ++ (0 à 4). */
function kpiCran(?float $v, ?array $bornes): ?array
{
    if ($v === null || !is_array($bornes) || count($bornes) !== 4) { return null; }
    $i = 0;
    foreach ($bornes as $b2) { if ($v >= (float) $b2) { $i++; } }
    $libs = ['−−', '−', '=', '+', '++'];
    return ['cran' => $i, 'lib' => $libs[$i]];
}

/** Le battement du cron — au plus une collecte par heure, même si la route
 *  est appelée plus souvent (le bouton de l'écran, lui, force toujours). */
function kpiTableCron(): string
{
    $der = (string) setting('kpiCollecteLe', '');
    if ($der !== '' && strtotime($der) !== false && time() - strtotime($der) < 3000) {
        return 'collecte déjà faite à ' . substr($der, 11, 5);
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['kpiCollecteLe', json_encode(date('Y-m-d H:i:s'))]);
    $b = kpiCollecte();
    return $b['collectes'] . ' KPI collecté(s)' . ($b['rates'] !== [] ? ' — en échec : ' . implode(', ', $b['rates']) : '');
}

/** POST /kpi-table/collecte — collecter tout de suite, depuis l'écran. */
function wr_kpi_collecte(): array
{
    return ['ok' => true] + kpiCollecte();
}

/**
 * GET /kpi-table — tout ce que l'écran montre : les KPI par catégorie ›
 * sous-catégorie, la dernière valeur réseau et par magasin, douze points
 * d'historique réseau, et la liste des endpoints offerts à l'encodage.
 */
function ep_kpi_table(): array
{
    kpiTableTables();
    $nomDe = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
            $nomDe[(string) $s['id']] = (string) $s['name'];
        }
    } catch (PDOException $e) { /* vide */ }

    $kpis = [];
    foreach (Db::rows('SELECT * FROM ceo_kpi_def WHERE actif = 1 AND source IS NOT NULL ORDER BY categorie, sous_categorie, ordre, id') as $def) {
        $src = json_decode((string) $def['source'], true) ?: [];
        if (!in_array($src['type'] ?? '', ['endpoint', 'compose'], true)) { continue; }
        $bornes = json_decode((string) ($def['echelle'] ?? ''), true);
        $bornes = is_array($bornes) && count($bornes) === 4 ? array_map('floatval', $bornes) : null;
        $code = (string) $def['code'];
        $der = Db::row('SELECT jour, valeur, maj FROM ceo_kpi_valeur WHERE code = ? AND shop = ? ORDER BY jour DESC LIMIT 1', [$code, '*']);
        $serie = array_reverse(array_map(
            fn ($r2) => ['jour' => (string) $r2['jour'], 'valeur' => $r2['valeur'] !== null ? (float) $r2['valeur'] : null],
            Db::rows('SELECT jour, valeur FROM ceo_kpi_valeur WHERE code = ? AND shop = ? ORDER BY jour DESC LIMIT 12', [$code, '*'])));
        $parMag = [];
        if ($der !== null) {
            foreach (Db::rows('SELECT shop, valeur FROM ceo_kpi_valeur WHERE code = ? AND jour = ? AND shop <> ?', [$code, (string) $der['jour'], '*']) as $r2) {
                // Seuls les magasins ACTIFS s'affichent — une boutique fantôme
                // d'une source (fermée, technique) ne parle à personne.
                if (!isset($nomDe[(string) $r2['shop']])) { continue; }
                $v3 = $r2['valeur'] !== null ? (float) $r2['valeur'] : null;
                $parMag[] = ['shopId' => (string) $r2['shop'], 'magasin' => $nomDe[(string) $r2['shop']],
                    'valeur' => $v3, 'cran' => kpiCran($v3, $bornes)];
            }
        }
        $kpis[] = [
            'id' => (int) $def['id'], 'code' => $code, 'nom' => (string) $def['nom'],
            'categorie' => (string) ($def['categorie'] ?? '') ?: 'Sans catégorie',
            'sousCategorie' => (string) ($def['sous_categorie'] ?? ''),
            'unite' => (string) ($def['unite'] ?? ''), 'agregat' => (string) $def['agregat'],
            'auRapport' => (bool) (int) ($def['au_rapport'] ?? 1),
            'seuilAlerte' => $def['seuil_alerte'] !== null ? (float) $def['seuil_alerte'] : null,
            'sens' => (string) $def['sens'],
            'source' => ['type' => (string) $src['type'],
                'endpoint' => (string) ($src['endpoint'] ?? ''), 'liste' => (string) ($src['liste'] ?? ''),
                'cleShop' => (string) ($src['cleShop'] ?? ''), 'champ' => (string) ($src['champ'] ?? ''),
                'a' => (string) ($src['a'] ?? ''), 'op1' => (string) ($src['op1'] ?? ''),
                'b' => (string) ($src['b'] ?? ''), 'op2' => (string) ($src['op2'] ?? ''), 'c' => (string) ($src['c'] ?? ''),
                'grain' => (string) ($src['grain'] ?? 'jour')],
            'echelle' => $bornes,
            'derniere' => $der !== null ? ['jour' => (string) $der['jour'],
                'valeur' => $der['valeur'] !== null ? (float) $der['valeur'] : null,
                'maj' => substr((string) $der['maj'], 0, 16),
                'cran' => kpiCran($der['valeur'] !== null ? (float) $der['valeur'] : null, $bornes)] : null,
            'serie' => $serie,
            'parMagasin' => $parMag,
        ];
    }
    // La fiche magasin : les attributs et leurs valeurs, pour l'écran et
    // comme opérandes des composés (`fiche:<cle>`).
    $attrs = []; $valeursF = [];
    foreach (Db::rows('SELECT * FROM ceo_magasin_fiche ORDER BY cle') as $r2) {
        $attrs[(string) $r2['cle']] = (string) $r2['libelle'];
        $valeursF[(string) $r2['id_shop']][(string) $r2['cle']] = $r2['valeur'] !== null ? (float) $r2['valeur'] : null;
    }
    return ['kpis' => $kpis, 'endpoints' => kpiEndpointsOfferts(),
        'fiche' => ['attributs' => array_map(fn ($cle) => ['cle' => $cle, 'libelle' => $attrs[$cle]], array_keys($attrs)),
            'valeurs' => $valeursF],
        'magasins' => array_map(fn ($id) => ['id' => $id, 'nom' => $nomDe[$id]], array_keys($nomDe))];
}

/**
 * POST /kpi-table/fiche — la fiche magasin entière : les attributs (clé,
 * libellé) et la grille magasin × attribut. Remplacement complet, simple.
 */
function wr_kpi_fiche(): array
{
    kpiTableTables();
    $b = body();
    $attrs = is_array($b['attributs'] ?? null) ? $b['attributs'] : [];
    $vals = is_array($b['valeurs'] ?? null) ? $b['valeurs'] : [];
    Db::exec('DELETE FROM ceo_magasin_fiche');
    $n = 0;
    foreach ($attrs as $a2) {
        $cle = mktSlug(trim((string) ($a2['cle'] ?? $a2['libelle'] ?? '')));
        $lib = trim((string) ($a2['libelle'] ?? $cle));
        if ($cle === '' || $lib === '') { continue; }
        foreach ($vals as $sid => $deCle) {
            $v = $deCle[$cle] ?? ($deCle[(string) ($a2['cle'] ?? '')] ?? null);
            Db::exec('INSERT INTO ceo_magasin_fiche (id_shop, cle, libelle, valeur) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE libelle = VALUES(libelle), valeur = VALUES(valeur)',
                [(int) $sid, $cle, $lib, is_numeric($v) ? (float) $v : null]);
            $n++;
        }
    }
    journalAdd('CEO', 'Paramètre', 'Table KPI', 'Fiche magasin enregistrée — ' . count($attrs) . ' attribut(s)');
    return ['ok' => true, 'lignes' => $n];
}

/**
 * GET /kpi-table/sonde?endpoint=… — appelle l'endpoint EN VRAI et propose ce
 * qui s'y lit : la liste, la clé magasin, les champs numériques, et un aperçu
 * des valeurs par magasin pour chaque champ.
 */
function ep_kpi_sonde(): array
{
    $url = trim((string) ($_GET['endpoint'] ?? ''));
    if (!isset(kpiEndpointsOfferts()[$url])) {
        http_response_code(422);
        return ['error' => 'endpoint hors de la liste offerte'];
    }
    $d = kpiAppelEndpoint($url);
    if ($d === null) { return ['ok' => false, 'error' => 'l’endpoint n’a pas répondu']; }
    $out = [];
    foreach ($d as $cle => $v) {
        if (!is_array($v) || $v === [] || !isset($v[0]) || !is_array($v[0])) { continue; }
        $item = $v[0];
        $cleShop = null;
        foreach (['shopId', 'id', 'shop', 'magasin', 'nom'] as $c2) {
            if (isset($item[$c2]) && !is_array($item[$c2])) { $cleShop = $c2; break; }
        }
        if ($cleShop === null) { continue; }
        $champs = [];
        foreach ($item as $c2 => $vv) {
            if ($c2 === $cleShop || !is_numeric($vv)) { continue; }
            $apercu = [];
            foreach (array_slice($v, 0, 6) as $it2) {
                if (!is_array($it2)) { continue; }
                $apercu[] = ['magasin' => (string) ($it2['magasin'] ?? $it2['shop'] ?? $it2['nom'] ?? $it2[$cleShop] ?? ''),
                    'valeur' => is_numeric($it2[$c2] ?? null) ? (float) $it2[$c2] : null];
            }
            $champs[] = ['champ' => (string) $c2, 'apercu' => $apercu];
        }
        if ($champs !== []) { $out[] = ['liste' => (string) $cle, 'cleShop' => $cleShop, 'champs' => $champs]; }
    }
    return ['ok' => $out !== [], 'listes' => $out];
}

/** POST /kpi-table — créer ou modifier un KPI encodé ; DELETE via actif=0. */
function wr_kpi_table(): array
{
    kpiTableTables();
    $b = body();
    $id = (int) ($b['id'] ?? 0);
    if (!empty($b['supprimer']) && $id > 0) {
        Db::exec('UPDATE ceo_kpi_def SET actif = 0 WHERE id = ?', [$id]);
        journalAdd('CEO', 'Paramètre', 'Table KPI', 'KPI désactivé (#' . $id . ')');
        return ['ok' => true];
    }
    $nom = trim((string) ($b['nom'] ?? ''));
    $src = is_array($b['source'] ?? null) ? $b['source'] : [];
    $grain = in_array($src['grain'] ?? '', ['jour', 'mois'], true) ? $src['grain'] : 'jour';
    if (($src['type'] ?? '') === 'compose') {
        // Un COMPOSÉ : A op B [op C], chaque opérande = un KPI ou `fiche:<cle>`.
        $ops = ['/', '*', '+', '-'];
        $a2 = trim((string) ($src['a'] ?? '')); $b2 = trim((string) ($src['b'] ?? '')); $c2 = trim((string) ($src['c'] ?? ''));
        if ($nom === '' || $a2 === '' || $b2 === '' || !in_array($src['op1'] ?? '', $ops, true)
            || ($c2 !== '' && !in_array($src['op2'] ?? '', $ops, true))) {
            http_response_code(422);
            return ['error' => 'il faut un nom, deux opérandes au moins et leurs opérateurs'];
        }
        $source = json_encode(['type' => 'compose', 'a' => $a2, 'op1' => (string) $src['op1'],
            'b' => $b2, 'op2' => $c2 !== '' ? (string) ($src['op2'] ?? '/') : '', 'c' => $c2, 'grain' => $grain],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        $endpoint = trim((string) ($src['endpoint'] ?? ''));
        if ($nom === '' || !isset(kpiEndpointsOfferts()[$endpoint])
            || trim((string) ($src['liste'] ?? '')) === '' || trim((string) ($src['champ'] ?? '')) === '') {
            http_response_code(422);
            return ['error' => 'il faut un nom, un endpoint offert, la liste et le champ à lire'];
        }
        $source = json_encode(['type' => 'endpoint', 'endpoint' => $endpoint,
            'liste' => trim((string) $src['liste']), 'cleShop' => trim((string) ($src['cleShop'] ?? 'shopId')),
            'champ' => trim((string) $src['champ']), 'grain' => $grain],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    // L'échelle en crans : 4 bornes croissantes → −− / − / = / + / ++.
    $echelle = null;
    if (is_array($b['echelle'] ?? null)) {
        $bs = array_values(array_filter(array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $b['echelle']), fn ($v) => $v !== null));
        if (count($bs) === 4) { sort($bs); $echelle = json_encode($bs); }
    }
    $cat = trim((string) ($b['categorie'] ?? '')) ?: 'Sans catégorie';
    $sscat = trim((string) ($b['sousCategorie'] ?? ''));
    $unite = trim((string) ($b['unite'] ?? ''));
    $agg = ($b['agregat'] ?? '') === 'moyenne' ? 'moyenne' : 'somme';
    $seuil = is_numeric($b['seuilAlerte'] ?? null) ? (float) $b['seuilAlerte'] : null;
    $sens = ($b['sens'] ?? '') === 'haut' ? 'haut' : 'bas';
    if ($id > 0) {
        Db::exec('UPDATE ceo_kpi_def SET nom = ?, categorie = ?, sous_categorie = ?, unite = ?, agregat = ?, source = ?, seuil_alerte = ?, sens = ?, au_rapport = ?, echelle = ? WHERE id = ?',
            [$nom, $cat, $sscat, $unite, $agg, $source, $seuil, $sens, !empty($b['auRapport']) ? 1 : 0, $echelle, $id]);
        journalAdd('CEO', 'Paramètre', 'Table KPI', 'KPI modifié — ' . $nom);
        return ['ok' => true, 'id' => $id];
    }
    $code = 'tk-' . mktSlug(mb_substr($nom, 0, 30));
    if (Db::row('SELECT id FROM ceo_kpi_def WHERE code = ?', [$code]) !== null) { $code .= '-' . random_int(10, 99); }
    Db::exec('INSERT INTO ceo_kpi_def (code, nom, levier, calcul, sens, sortie, actif, ordre, categorie, sous_categorie, source, agregat, unite, seuil_alerte, au_rapport, echelle)
              VALUES (?,?,?,?,?,?,1,50,?,?,?,?,?,?,1,?)',
        [$code, $nom, 'transverse', json_encode(['type' => 'table']), $sens, 'tableau',
         $cat, $sscat, $source, $agg, $unite, $seuil, $echelle]);
    journalAdd('CEO', 'Paramètre', 'Table KPI', 'KPI encodé — ' . $nom
        . (($src['type'] ?? '') === 'compose' ? ' (composé)' : ' (' . ($src['endpoint'] ?? '') . ' › ' . ($src['champ'] ?? '') . ')'));
    return ['ok' => true, 'code' => $code];
}
