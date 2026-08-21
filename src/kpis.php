<?php
declare(strict_types=1);

/**
 * Référentiel des KPI — la table `ceo_kpi_def`, éditable à l'écran (Paramètres)
 * et par API. C'est elle qui alimente le catalogue du compositeur de rapports
 * et TOUS les seuils du générateur : un seuil changé ici s'applique partout.
 *
 * Deux familles de KPI :
 *
 *  - `calcul.type = 'bloc'`  : un calculateur écrit en PHP (la plomberie vers
 *    l'API du panel). Le référentiel pilote son NOM, ses SEUILS, son sens et
 *    son activation — jamais son calcul : une table n'invente pas une source.
 *  - `calcul.type = 'derive'` : une FORMULE sur les mesures existantes
 *    (numérateur ÷ dénominateur × échelle), créée depuis le formulaire du
 *    front sans une ligne de code. Évaluée par magasin sur le mois en cours.
 *
 * Pont de compatibilité : les seuils historiques de la table partagée `kpi`
 * (food, labour, overhead, ca_etp) restent lus par les écrans — quand on
 * modifie ici un KPI qui leur correspond, la table `kpi` est mise à jour dans
 * le même geste, pour que les écrans et les rapports disent le même chiffre.
 */

const KPI_MESURES = [
    'ca'        => 'Chiffre d’affaires (mois en cours)',
    'tickets'   => 'Tickets (mois en cours)',
    'produits'  => 'Articles vendus (mois en cours)',
    'margeBrute' => 'Marge brute € (mois en cours)',
    'coutMatiere' => 'Coût matière € (mois en cours)',
    'heures'    => 'Heures planifiées (mois en cours)',
    'etp'       => 'ETP planifiés (mois en cours)',
    'jours'     => 'Jours écoulés du mois',
];

const KPI_PONT_TABLE = [
    'food-cost-pct' => 'food', 'labour-pct' => 'labour',
    'overhead-pct' => 'overhead', 'ca-etp' => 'ca_etp',
];

function ensureKpiDefs(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_kpi_def ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'code VARCHAR(40) NOT NULL UNIQUE,'
        . 'nom VARCHAR(120) NOT NULL,'
        . 'description VARCHAR(300) NULL,'
        . 'levier VARCHAR(20) NOT NULL,'
        . 'calcul TEXT NOT NULL,'                       // JSON {type:bloc|derive|ecran, …}
        . 'seuil_alerte DECIMAL(12,2) NULL,'
        . 'seuil_critique DECIMAL(12,2) NULL,'
        . "sens ENUM('haut','bas') NOT NULL DEFAULT 'haut',"  // mauvais au-dessus / en dessous
        . "sortie VARCHAR(12) NOT NULL DEFAULT 'tableau',"    // tableau | heatmap | barres | treemap
        . 'actif TINYINT NOT NULL DEFAULT 1,'
        . 'ordre SMALLINT NOT NULL DEFAULT 100'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Base déjà en service : la colonne `sortie` est arrivée après la table.
    try { Db::exec("ALTER TABLE ceo_kpi_def ADD COLUMN sortie VARCHAR(12) NOT NULL DEFAULT 'tableau' AFTER sens"); }
    catch (PDOException $e) { /* déjà là */ }

    $n = Db::row('SELECT COUNT(*) n FROM ceo_kpi_def');
    if ((int) ($n['n'] ?? 0) > 0) { return; }

    // Les seuils historiques comme point de départ — la table `kpi` fait foi
    // au moment du semis, ensuite c'est le référentiel qui commande.
    $h = [];
    try {
        foreach (Db::rows('SELECT code, seuil_bas, seuil_haut FROM kpi WHERE code IS NOT NULL') as $k) {
            $h[$k['code']] = $k['seuil_haut'] !== null ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
        }
    } catch (PDOException $e) { /* défauts ci-dessous */ }

    $seed = [
        // code, nom, description, levier, calcul, alerte, critique, sens, ordre
        ['trafic-clients', 'Clients par jour vs réseau', 'Écart (%) du magasin à la moyenne réseau des clients/jour du mois.',
            'trafic', ['type' => 'bloc', 'bloc' => 'trafic-clients'], -10, -20, 'bas', 10],
        ['nvn1', 'CA N vs N-1', 'Écart (%) du CA de la période au même CA un an plus tôt.',
            'trafic', ['type' => 'bloc', 'bloc' => 'trafic-nvn1'], -5, -15, 'bas', 15],
        ['panier-ecart', 'Ticket moyen vs réseau', 'Écart (%) du ticket moyen et des articles/ticket à la moyenne réseau du mois.',
            'recurrence', ['type' => 'bloc', 'bloc' => 'recurrence-panier'], -5, -15, 'bas', 20],
        ['note-google', 'Note Google', 'Note de la fiche Google du magasin — la cible du réseau.',
            'recurrence', ['type' => 'bloc', 'bloc' => 'recurrence-avis'], (float) setting('reputationCible', 4.5), 4.0, 'bas', 25],
        ['taches', 'Note des tâches contrôlées', 'Une tâche checklist notée au-dessous passe dans le rapport, photo à l’appui.',
            'xp', ['type' => 'bloc', 'bloc' => 'xp-taches'], 3, 2, 'bas', 30],
        ['food-cost-pct', 'Food cost du mois (%)', 'Coût matière ÷ CA, par magasin, depuis la heatmap de marge.',
            'food-cost', ['type' => 'bloc', 'bloc' => 'food-cost'], $h['food'] ?? 32, ($h['food'] ?? 32) + 4, 'haut', 35],
        ['labour-pct', 'Labour cost (%)', 'Seuil du ratio main-d’œuvre — écran Marge & coûts.',
            'labour-cost', ['type' => 'ecran', 'ecran' => 'Marge & coûts'], $h['labour'] ?? 33, ($h['labour'] ?? 33) + 3, 'haut', 40],
        ['ca-etp', 'CA par ETP (€)', 'CA du mois ÷ ETP planifiés (plannings réels du panel).',
            'labour-cost', ['type' => 'bloc', 'bloc' => 'labour-caetp'], $h['ca_etp'] ?? 13000, 10000, 'bas', 45],
        ['overhead-pct', 'Overhead (%)', 'Seuil des frais fixes — écran Marge & coûts.',
            'overhead-cost', ['type' => 'ecran', 'ecran' => 'Marge & coûts'], $h['overhead'] ?? 13.5, ($h['overhead'] ?? 13.5) + 2.5, 'haut', 50],
        ['jours-rouges', 'Jours à résultat net négatif', 'Nombre de jours de la semaine en résultat net négatif (rentabilité par jour).',
            'overhead-cost', ['type' => 'bloc', 'bloc' => 'overhead-jours'], 3, 5, 'haut', 55],
        // — Les KPI DÉRIVÉS : créés par formule, modifiables et supprimables au
        //   formulaire. Quatre exemples qui fonctionnent dès aujourd'hui.
        ['panier-moyen', 'Ticket moyen (€)', 'CA ÷ tickets, par magasin sur le mois en cours.',
            'recurrence', ['type' => 'derive', 'num' => 'ca', 'den' => 'tickets', 'echelle' => 'eur'], 13, 11, 'bas', 60, 'barres'],
        ['articles-ticket', 'Articles par ticket', 'Articles vendus ÷ tickets, par magasin sur le mois en cours.',
            'recurrence', ['type' => 'derive', 'num' => 'produits', 'den' => 'tickets', 'echelle' => 'unite'], 3.6, 3.2, 'bas', 65, 'heatmap'],
        ['marge-brute-pct', 'Marge brute (%)', 'Marge brute ÷ CA × 100, par magasin sur le mois en cours.',
            'food-cost', ['type' => 'derive', 'num' => 'margeBrute', 'den' => 'ca', 'echelle' => 'pct'], 63, 60, 'bas', 70, 'heatmap'],
        ['ca-par-heure', 'CA par heure planifiée (€)', 'CA ÷ heures planifiées, par magasin sur le mois en cours.',
            'labour-cost', ['type' => 'derive', 'num' => 'ca', 'den' => 'heures', 'echelle' => 'eur'], 95, 80, 'bas', 75, 'treemap'],
    ];
    foreach ($seed as $s) {
        Db::exec('INSERT INTO ceo_kpi_def (code, nom, description, levier, calcul, seuil_alerte, seuil_critique, sens, sortie, actif, ordre)
                  VALUES (?,?,?,?,?,?,?,?,?,1,?)',
            [$s[0], $s[1], $s[2], $s[3], json_encode($s[4], JSON_UNESCAPED_UNICODE), $s[5], $s[6], $s[7], $s[9] ?? 'tableau', $s[8]]);
    }
}

const KPI_SORTIES = ['tableau' => 'Tableau', 'heatmap' => 'Heatmap', 'barres' => 'Barres', 'treemap' => 'Treemap'];

/** Le rendu email d'un KPI dérivé selon sa sortie — cellules et divs à fond plein. */
function kpiRenduVisuel(array $def, array $lignes): string
{
    $coul = fn (int $n) => $n >= 2 ? '#8D1D2C' : ($n === 1 ? '#C17A2A' : '#3D8B44');
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    if ($lignes === []) { return ''; }
    $max = max(array_map(fn ($l) => $l['valeur'], $lignes)) ?: 1;
    $somme = array_sum(array_map(fn ($l) => max(0, $l['valeur']), $lignes)) ?: 1;
    switch ($def['sortie'] ?? 'tableau') {
        case 'heatmap': {
            $cells = '';
            foreach ($lignes as $l) {
                $cells .= '<td style="background:' . $coul($l['niveau']) . ';color:#fff;border-radius:6px;padding:7px 10px;text-align:center;font-family:sans-serif;font-size:11px">'
                    . '<div style="font-size:9px;opacity:0.85">' . $e($l['magasin']) . '</div>'
                    . '<div style="font-weight:700;margin-top:1px">' . $e(kpiFormatValeur($def, $l['valeur'])) . '</div></td>';
            }
            return '<table cellpadding="0" cellspacing="3" style="border-collapse:separate;margin:4px 0"><tr>' . $cells . '</tr></table>';
        }
        case 'barres': {
            $h = '';
            foreach ($lignes as $l) {
                $w = max(4, (int) round($l['valeur'] / $max * 100));
                $h .= '<div style="display:flex;align-items:center;gap:8px;margin:4px 0;font-size:11.5px;font-family:sans-serif">'
                    . '<span style="width:170px;flex:none">' . $e($l['magasin']) . '</span>'
                    . '<span style="flex:1;background:#EFE9DF;border-radius:5px"><span style="display:block;width:' . $w . '%;background:' . $coul($l['niveau']) . ';border-radius:5px;color:#fff;font-size:10.5px;font-weight:700;padding:3px 7px;white-space:nowrap;box-sizing:border-box">' . $e(kpiFormatValeur($def, $l['valeur'])) . '</span></span></div>';
            }
            return '<div style="margin:5px 0">' . $h . '</div>';
        }
        case 'treemap': {
            $h = '';
            foreach ($lignes as $l) {
                $w = max(9, (int) round(max(0, $l['valeur']) / $somme * 100));
                $h .= '<div style="width:' . $w . '%;background:' . $coul($l['niveau']) . ';color:#fff;padding:11px 8px;box-sizing:border-box;font-family:sans-serif;overflow:hidden;border-radius:6px">'
                    . '<div style="font-size:9px;opacity:0.85;white-space:nowrap">' . $e($l['magasin']) . '</div>'
                    . '<div style="font-size:11.5px;font-weight:700;white-space:nowrap">' . $e(kpiFormatValeur($def, $l['valeur'])) . '</div></div>';
            }
            return '<div style="display:flex;gap:3px;margin:5px 0">' . $h . '</div>'
                . '<div style="font-size:10px;color:#6E645A;font-family:sans-serif">Largeur ∝ part de la valeur · couleur = position vs seuils</div>';
        }
    }
    return '';
}

/** Le référentiel, décodé — une lecture par requête. */
function kpiDefs(): array
{
    static $defs = null;
    if ($defs !== null) { return $defs; }
    ensureKpiDefs();
    $defs = [];
    foreach (Db::rows('SELECT * FROM ceo_kpi_def ORDER BY ordre, id') as $r) {
        $r['calcul'] = json_decode((string) $r['calcul'], true) ?: [];
        $r['seuil_alerte'] = $r['seuil_alerte'] !== null ? (float) $r['seuil_alerte'] : null;
        $r['seuil_critique'] = $r['seuil_critique'] !== null ? (float) $r['seuil_critique'] : null;
        $r['actif'] = (bool) $r['actif'];
        $defs[$r['code']] = $r;
    }
    return $defs;
}

/** Le seuil d'alerte d'un KPI du référentiel — le défaut si la ligne manque. */
function kpiSeuil(string $code, float $defaut): float
{
    $d = kpiDefs()[$code] ?? null;
    return $d !== null && $d['seuil_alerte'] !== null ? $d['seuil_alerte'] : $defaut;
}

/**
 * Les mesures du mois en cours, par magasin — la matière des KPI dérivés.
 * Chaque mesure vient d'une lecture existante ; une mesure sans source rend
 * null, jamais zéro.
 */
function kpiMesures(): array
{
    return rapCtx('kpi-mesures', function () {
        $out = [];
        $r = rapReseau('mois');
        foreach ((array) (is_array($r) ? ($r['magasins'] ?? []) : []) as $m) {
            $sid = (string) $m['shopId'];
            $out[$sid] = ['nom' => $m['magasin'], 'ca' => $m['n'], 'tickets' => $m['tickets'],
                'produits' => $m['produits'] ?? null, 'margeBrute' => null, 'coutMatiere' => null,
                'heures' => null, 'etp' => null, 'jours' => (int) date('j')];
        }
        foreach ((array) rapEtp() as $e) {
            if ((int) $e['annee'] === (int) date('Y') && (int) $e['mois'] === (int) date('n')
                && isset($out[(string) $e['storeId']])) {
                $out[(string) $e['storeId']]['heures'] = (float) $e['heures'];
                $out[(string) $e['storeId']]['etp'] = (float) $e['etp'];
            }
        }
        foreach (array_keys($out) as $sid) {
            $hm = rapCtx('hm-mois-' . $sid, fn () => PanelApi::marginHeatmapEntre((int) $sid, date('Y-m-01'), date('Y-m-d')));
            $t = is_array($hm) ? ($hm['totals'] ?? []) : [];
            if (($t['ca'] ?? null) !== null) {
                $out[$sid]['margeBrute'] = (float) $t['margin_value'];
                $out[$sid]['coutMatiere'] = round((float) $t['ca'] - (float) $t['margin_value'], 2);
            }
        }
        return $out;
    }) ?? [];
}

/** Évalue un KPI dérivé pour chaque magasin : [nom, valeur, niveau 0|1|2]. */
function kpiEvalDerive(array $def): array
{
    $c = $def['calcul'];
    $fac = ($c['echelle'] ?? '') === 'pct' ? 100.0 : 1.0;
    $lignes = [];
    foreach (kpiMesures() as $m) {
        $num = $m[$c['num'] ?? ''] ?? null;
        $den = $m[$c['den'] ?? ''] ?? null;
        if ($num === null || $den === null || (float) $den == 0.0) { continue; }
        $v = (float) $num / (float) $den * $fac;
        $niveau = 0;
        if ($def['sens'] === 'haut') {
            if ($def['seuil_critique'] !== null && $v >= $def['seuil_critique']) { $niveau = 2; }
            elseif ($def['seuil_alerte'] !== null && $v >= $def['seuil_alerte']) { $niveau = 1; }
        } else {
            if ($def['seuil_critique'] !== null && $v <= $def['seuil_critique']) { $niveau = 2; }
            elseif ($def['seuil_alerte'] !== null && $v <= $def['seuil_alerte']) { $niveau = 1; }
        }
        $lignes[] = ['magasin' => $m['nom'], 'valeur' => round($v, 2), 'niveau' => $niveau];
    }
    return $lignes;
}

function kpiFormatValeur(array $def, float $v): string
{
    $e = $def['calcul']['echelle'] ?? '';
    if ($e === 'eur') { return number_format($v, 2, ',', ' ') . ' €'; }
    if ($e === 'pct') { return str_replace('.', ',', (string) round($v, 1)) . ' %'; }
    return str_replace('.', ',', (string) round($v, 2));
}

/* --- Endpoints --------------------------------------------------------------- */

/** GET /kpis — le référentiel + le catalogue des mesures et des leviers. */
function ep_kpis(): array
{
    ensureKpiDefs();
    $defs = array_values(kpiDefs());
    return [
        'kpis' => array_map(fn ($d) => [
            'id' => (int) $d['id'], 'code' => $d['code'], 'nom' => $d['nom'],
            'description' => $d['description'], 'levier' => $d['levier'],
            'calcul' => $d['calcul'],
            'seuilAlerte' => $d['seuil_alerte'], 'seuilCritique' => $d['seuil_critique'],
            'sens' => $d['sens'], 'sortie' => $d['sortie'] ?? 'tableau', 'actif' => $d['actif'],
            'modifiable' => true,
            'supprimable' => ($d['calcul']['type'] ?? '') === 'derive',
        ], $defs),
        'mesures' => KPI_MESURES,
        'leviers' => RAP_LEVIERS,
        'sorties' => KPI_SORTIES,
    ];
}

/** POST /kpis — créer un KPI dérivé depuis le formulaire. */
function wr_kpi(): array
{
    ensureKpiDefs();
    $b = body();
    $nom = trim((string) ($b['nom'] ?? ''));
    $num = (string) ($b['num'] ?? ''); $den = (string) ($b['den'] ?? '');
    $levier = (string) ($b['levier'] ?? 'transverse');
    if ($nom === '' || !isset(KPI_MESURES[$num]) || !isset(KPI_MESURES[$den])) {
        http_response_code(422);
        return ['error' => 'nom, numérateur et dénominateur (mesures connues) sont requis'];
    }
    if (!isset(RAP_LEVIERS[$levier])) { $levier = 'transverse'; }
    $code = 'kpi-' . preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($nom));
    $code = trim(mb_substr($code, 0, 38), '-');
    if (Db::row('SELECT id FROM ceo_kpi_def WHERE code = ?', [$code]) !== null) { $code .= '-' . random_int(10, 99); }
    $calcul = ['type' => 'derive', 'num' => $num, 'den' => $den,
        'echelle' => in_array($b['echelle'] ?? '', ['pct', 'eur', 'unite'], true) ? $b['echelle'] : 'unite'];
    Db::exec('INSERT INTO ceo_kpi_def (code, nom, description, levier, calcul, seuil_alerte, seuil_critique, sens, sortie, actif, ordre)
              VALUES (?,?,?,?,?,?,?,?,?,1,200)',
        [$code, $nom, trim((string) ($b['description'] ?? '')) ?: (KPI_MESURES[$num] . ' ÷ ' . KPI_MESURES[$den]),
         $levier, json_encode($calcul, JSON_UNESCAPED_UNICODE),
         is_numeric($b['seuilAlerte'] ?? null) ? (float) $b['seuilAlerte'] : null,
         is_numeric($b['seuilCritique'] ?? null) ? (float) $b['seuilCritique'] : null,
         ($b['sens'] ?? '') === 'bas' ? 'bas' : 'haut',
         isset(KPI_SORTIES[$b['sortie'] ?? '']) ? $b['sortie'] : 'tableau']);
    journalAdd('CEO', 'Paramètre', $nom, 'KPI dérivé créé (' . $code . ')');
    return ['ok' => true, 'code' => $code];
}

/** PUT /kpis/{id} — nom, description, seuils, sens, actif (et formule si dérivé). */
function wr_kpi_patch(int $id): array
{
    ensureKpiDefs();
    $d = Db::row('SELECT * FROM ceo_kpi_def WHERE id = ?', [$id]);
    if ($d === null) { http_response_code(404); return ['error' => 'KPI inconnu']; }
    $b = body();
    $set = []; $args = [];
    foreach ([['nom', 'nom'], ['description', 'description']] as [$k, $col]) {
        if (isset($b[$k]) && trim((string) $b[$k]) !== '') { $set[] = $col . ' = ?'; $args[] = trim((string) $b[$k]); }
    }
    foreach ([['seuilAlerte', 'seuil_alerte'], ['seuilCritique', 'seuil_critique']] as [$k, $col]) {
        if (array_key_exists($k, $b)) {
            $set[] = $col . ' = ?';
            $args[] = is_numeric($b[$k]) ? (float) $b[$k] : null;
        }
    }
    if (isset($b['sens']) && in_array($b['sens'], ['haut', 'bas'], true)) { $set[] = 'sens = ?'; $args[] = $b['sens']; }
    if (isset($b['sortie']) && isset(KPI_SORTIES[$b['sortie']])) { $set[] = 'sortie = ?'; $args[] = $b['sortie']; }
    if (array_key_exists('actif', $b)) { $set[] = 'actif = ?'; $args[] = !empty($b['actif']) ? 1 : 0; }
    if (isset($b['levier']) && isset(RAP_LEVIERS[$b['levier']])) { $set[] = 'levier = ?'; $args[] = $b['levier']; }
    $calcul = json_decode((string) $d['calcul'], true) ?: [];
    if (($calcul['type'] ?? '') === 'derive') {
        $chg = false;
        foreach (['num', 'den'] as $k) {
            if (isset($b[$k]) && isset(KPI_MESURES[$b[$k]])) { $calcul[$k] = $b[$k]; $chg = true; }
        }
        if (isset($b['echelle']) && in_array($b['echelle'], ['pct', 'eur', 'unite'], true)) { $calcul['echelle'] = $b['echelle']; $chg = true; }
        if ($chg) { $set[] = 'calcul = ?'; $args[] = json_encode($calcul, JSON_UNESCAPED_UNICODE); }
    }
    if ($set === []) { return ['ok' => true, 'rien' => true]; }
    $args[] = $id;
    Db::exec('UPDATE ceo_kpi_def SET ' . implode(', ', $set) . ' WHERE id = ?', $args);

    // Pont : les seuils historiques que les écrans lisent encore suivent.
    $pont = KPI_PONT_TABLE[(string) $d['code']] ?? null;
    if ($pont !== null && array_key_exists('seuilAlerte', $b) && is_numeric($b['seuilAlerte'])) {
        try { Db::exec('UPDATE kpi SET seuil_haut = ? WHERE code = ?', [(float) $b['seuilAlerte'], $pont]); }
        catch (PDOException $e) { /* table absente : rien à synchroniser */ }
    }
    if ((string) $d['code'] === 'note-google' && array_key_exists('seuilAlerte', $b) && is_numeric($b['seuilAlerte'])) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['reputationCible', json_encode((float) $b['seuilAlerte'])]);
    }
    journalAdd('CEO', 'Paramètre', (string) $d['nom'], 'KPI mis à jour');
    return ['ok' => true];
}

/** DELETE /kpis/{id} — seulement les KPI dérivés ; le reste se désactive. */
function wr_kpi_suppr(int $id): array
{
    ensureKpiDefs();
    $d = Db::row('SELECT * FROM ceo_kpi_def WHERE id = ?', [$id]);
    if ($d === null) { http_response_code(404); return ['error' => 'KPI inconnu']; }
    $calcul = json_decode((string) $d['calcul'], true) ?: [];
    if (($calcul['type'] ?? '') !== 'derive') {
        http_response_code(422);
        return ['error' => 'un KPI câblé ne se supprime pas — désactivez-le'];
    }
    Db::exec('DELETE FROM ceo_kpi_def WHERE id = ?', [$id]);
    journalAdd('CEO', 'Paramètre', (string) $d['nom'], 'KPI dérivé supprimé');
    return ['ok' => true];
}
