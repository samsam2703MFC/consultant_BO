<?php
declare(strict_types=1);
/**
 * OBJECTIFS PRODUITS D'UNE CAMPAGNE.
 *
 * « Sombreffe doit vendre 500 tartes aux pommes en octobre. » La campagne
 * porte la liste des produits qui comptent — pommes tranches, amandes, crème,
 * tout ce qu'on coche — ; chaque magasin porte son objectif EN PIÈCES sur la
 * période ; le dashboard du magasin dit où il en est, jour après jour, face à
 * ce qu'il devrait avoir vendu à ce jour.
 *
 * Les ventes viennent des tickets déjà gravés jour par jour pour le dashboard
 * (svP…, un magasin, un jour, ses produits heure par heure) : la jauge ne
 * coûte aucun appel de plus au panel, hors la journée en cours.
 *
 * L'attendu est linéaire sur les JOURS OUVERTS : un dimanche fermé n'est pas
 * un jour de retard. Les jours passés disent eux-mêmes s'ils ont vendu ; les
 * jours à venir suivent le rythme de semaine observé sur les quatre dernières.
 */

const OP_MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function ensureObjectifsProduits(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_campagne_produit ('
        . 'campagne_id INT NOT NULL,'
        . 'product_id INT NOT NULL,'
        . 'nom VARCHAR(200) NOT NULL DEFAULT \'\','
        . 'categorie VARCHAR(120) NULL,'
        . 'PRIMARY KEY (campagne_id, product_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_campagne_objectif_produit ('
        . 'campagne_id INT NOT NULL,'
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'pieces INT NULL,'
        . 'maj DATETIME NULL,'
        . 'PRIMARY KEY (campagne_id, shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function opDateValide(string $d): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
}

function opLendemain(string $d): string { return date('Y-m-d', strtotime($d . ' +1 day')); }

/** Les magasins actifs — [id => nom]. */
function opMagasins(): array
{
    $out = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $r) { $out[(string) $r['id']] = (string) $r['name']; }
    } catch (PDOException $e) { /* table partagée absente */ }
    return $out;
}

/** Les campagnes datées, de la plus récente à la plus ancienne. Vide si le module marketing manque. */
function opCampagnes(): array
{
    try {
        $rows = Db::rows('SELECT c.id, c.name, c.starts_on, c.ends_on, c.status_code, s.label AS statut
                            FROM mar_campaign c LEFT JOIN mar_campaign_status s ON s.code = c.status_code
                           WHERE c.starts_on IS NOT NULL ORDER BY c.starts_on DESC, c.id DESC');
    } catch (PDOException $e) {
        try {
            $rows = Db::rows('SELECT id, name, starts_on, ends_on, status_code, NULL AS statut FROM mar_campaign
                               WHERE starts_on IS NOT NULL ORDER BY starts_on DESC, id DESC');
        } catch (PDOException $e2) { return []; }
    }
    return array_map(static fn ($c) => ['id' => (int) $c['id'], 'nom' => (string) $c['name'],
        'debut' => substr((string) $c['starts_on'], 0, 10), 'fin' => substr((string) ($c['ends_on'] ?: $c['starts_on']), 0, 10),
        'statut' => (string) ($c['statut'] ?? $c['status_code'] ?? '')], $rows);
}

/** Le périmètre d'une campagne : ses magasins, ou tout le réseau. */
function opPerimetre(int $cid, array $nomDe): array
{
    $ids = [];
    try {
        foreach (Db::rows('SELECT shop_id FROM mar_campaign_shop WHERE campaign_id = ?', [$cid]) as $r) {
            $s = (string) $r['shop_id'];
            if (isset($nomDe[$s])) { $ids[] = $s; }
        }
    } catch (PDOException $e) { /* sans table : tout le réseau */ }
    // Les clés numériques d'un tableau PHP redeviennent des entiers : on les
    // remet en chaînes, sinon `in_array(…, true)` ne retrouve plus « 5 ».
    return $ids === [] ? array_map('strval', array_keys($nomDe)) : $ids;
}

function opProduits(int $cid): array
{
    return array_map(static fn ($r) => ['id' => (int) $r['product_id'], 'nom' => (string) $r['nom'], 'categorie' => (string) ($r['categorie'] ?? '')],
        Db::rows('SELECT product_id, nom, categorie FROM ceo_campagne_produit WHERE campagne_id = ? ORDER BY categorie, nom', [$cid]));
}

function opObjectifs(int $cid): array
{
    $out = [];
    foreach (Db::rows('SELECT shop_id, pieces FROM ceo_campagne_objectif_produit WHERE campagne_id = ?', [$cid]) as $r) {
        if ($r['pieces'] !== null && (int) $r['pieces'] > 0) { $out[(string) $r['shop_id']] = (int) $r['pieces']; }
    }
    return $out;
}

/**
 * Les clients d'un mois pour un magasin — l'ordre de grandeur qu'on a en tête
 * quand on pose un objectif en pièces : « 500 tartes pour 4 300 clients ».
 * Le dernier mois clos encodé (ceo_shop_month_perf), sinon les trente derniers
 * jours des heures gravées — sans appel au panel, on ne lit que la base.
 */
function opClientsMois(int $sid): ?array
{
    try {
        $r = Db::row('SELECT year, month, tickets FROM ceo_shop_month_perf
                       WHERE shop_id = ? AND tickets IS NOT NULL AND tickets > 0 AND (year < ? OR (year = ? AND month < ?))
                       ORDER BY year DESC, month DESC LIMIT 1', [(string) $sid, (int) date('Y'), (int) date('Y'), (int) date('n')]);
        if ($r !== null) {
            return ['clients' => (int) $r['tickets'], 'periode' => OP_MOIS[(int) $r['month']] . ' ' . (int) $r['year'], 'source' => 'mois'];
        }
    } catch (PDOException $e) { /* table absente */ }
    $n = 0; $jours = 0;
    for ($i = 1; $i <= 30; $i++) {
        $c = setting('svH' . $sid . ':' . date('Y-m-d', strtotime('-' . $i . ' days')));
        if (!is_array($c) || !isset($c['h']) || !is_array($c['h'])) { continue; }
        foreach ($c['h'] as $l) { $n += (int) ($l['tickets'] ?? 0); }
        $jours++;
    }
    return $n > 0 ? ['clients' => $n, 'periode' => '30 derniers jours', 'source' => 'jours'] : null;
}

/**
 * Les jours d'ouverture d'un magasin sur la campagne — [date => ouvert].
 *
 * Un jour passé est ouvert s'il a vendu (heures gravées). Un jour à venir suit
 * le rythme de semaine des quatre semaines qui précèdent la date regardée :
 * un magasin fermé le dimanche n'a pas de dimanche à rattraper.
 */
function opJoursOuverts(int $sid, string $du, string $au, string $date): array
{
    $ref = min($date, date('Y-m-d'));
    $lu = [];
    for ($i = 28; $i >= 1; $i--) { $lu[] = date('Y-m-d', strtotime($ref . ' -' . $i . ' days')); }
    $camp = [];
    for ($d = $du; $d < $ref && $d <= $au; $d = opLendemain($d)) { $camp[] = $d; }
    $heures = svHeuresJours($sid, array_values(array_unique(array_merge($lu, $camp))));
    $vend = static function ($hs): bool {
        foreach ((array) $hs as $l) { if ((float) ($l['ca'] ?? 0) > 0) { return true; } }
        return false;
    };
    $wd = [];
    foreach ($lu as $d) { if (isset($heures[$d]) && $vend($heures[$d])) { $wd[(int) date('N', strtotime($d))] = true; } }
    // Sans historique (magasin tout neuf, panel muet) : ouvert sauf le dimanche.
    if ($wd === []) { $wd = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true]; }
    $ouverts = [];
    for ($d = $du; $d <= $au; $d = opLendemain($d)) {
        $ouverts[$d] = $d < $ref ? (isset($heures[$d]) && $vend($heures[$d])) : isset($wd[(int) date('N', strtotime($d))]);
    }
    return $ouverts;
}

/** Les ventes des produits d'une campagne, jour par jour et produit par produit, depuis les tickets gravés. */
function opVentes(int $sid, array $pids, string $du, string $au, int &$cout, int $budget): array
{
    $set = array_fill_keys(array_map('intval', $pids), true);
    $parJour = []; $parProduit = []; $manquants = [];
    for ($d = $du; $d <= $au; $d = opLendemain($d)) {
        if ($d < SV_DEBUT) { $manquants[] = $d; continue; }
        $p = svProduitsJour($sid, $d, $cout, $budget);
        if ($p === null) { $manquants[] = $d; continue; }
        $q = 0.0;
        foreach ($p as $lst) {
            foreach ((array) $lst as $pid => $x) {
                $pid = (int) $pid;
                if (!isset($set[$pid])) { continue; }
                $q += (float) $x[1];
                $parProduit[$pid] ??= ['id' => $pid, 'nom' => svNomProduit($pid, (string) $x[0]), 'q' => 0.0];
                $parProduit[$pid]['q'] += (float) $x[1];
            }
        }
        $parJour[$d] = round($q, 2);
    }
    return ['parJour' => $parJour, 'parProduit' => $parProduit, 'manquants' => $manquants];
}

/**
 * Le bilan d'un magasin sur sa campagne : vendu, attendu à ce jour, rythme,
 * ce qu'il faut par jour, projection, état — la jauge et ses quatre chiffres.
 */
function opBilan(int $sid, array $pids, ?int $objectif, string $du, string $au, string $date, int &$cout, int $budget): array
{
    $auj = date('Y-m-d');
    $ouverts = opJoursOuverts($sid, $du, $au, $date);
    $v = opVentes($sid, $pids, $du, min($au, $date), $cout, $budget);
    $vendu = (int) round(array_sum($v['parJour']));
    $passes = 0; $total = 0; $restants = 0;
    foreach ($ouverts as $d => $o) {
        if (!$o) { continue; }
        $total++;
        if ($d <= $date) { $passes++; } else { $restants++; }
    }
    $attendu = $total > 0 ? round(100 * $passes / $total, 1) : null;
    // Le rythme : les sept derniers jours ouverts entièrement écoulés — la
    // journée en cours n'est pas finie, elle tirerait la moyenne vers le bas.
    $der = [];
    foreach (array_reverse(array_keys($v['parJour'])) as $d) {
        if ($d >= $auj || empty($ouverts[$d])) { continue; }
        $der[] = $v['parJour'][$d];
        if (count($der) >= 7) { break; }
    }
    $rythme = $der !== [] ? array_sum($der) / count($der) : null;
    $reste = $objectif !== null ? max(0, $objectif - $vendu) : null;
    $ilFaut = ($reste !== null && $restants > 0) ? $reste / $restants : null;
    $projection = $rythme !== null ? (int) round($vendu + $rythme * $restants) : null;
    $pct = ($objectif !== null && $objectif > 0) ? round(100 * $vendu / $objectif, 1) : null;
    $etat = 'sans';
    if ($pct !== null) {
        if ($vendu >= $objectif) { $etat = 'atteint'; }
        elseif ($attendu === null) { $etat = 'sans'; }
        elseif ($pct >= $attendu) { $etat = 'avance'; }
        elseif ($pct >= $attendu - 8) { $etat = 'clous'; }
        else { $etat = 'retard'; }
    }
    $parProduit = array_values($v['parProduit']);
    usort($parProduit, static fn ($a, $b) => $b['q'] <=> $a['q']);
    foreach ($parProduit as &$p) { $p['q'] = (int) round($p['q']); }
    unset($p);
    return ['objectif' => $objectif, 'vendu' => $vendu, 'ceJour' => (int) round($v['parJour'][$date] ?? 0),
        'pct' => $pct, 'attendu' => $attendu, 'etat' => $etat, 'reste' => $reste,
        'ilFaut' => $ilFaut !== null ? round($ilFaut, 1) : null, 'rythme' => $rythme !== null ? round($rythme, 1) : null,
        'projection' => $projection,
        'jours' => ['ouverts' => $total, 'passes' => $passes, 'restants' => $restants, 'calendrier' => count($ouverts)],
        'parProduit' => $parProduit, 'parJour' => $v['parJour'], 'ouverts' => $ouverts,
        'manquants' => count($v['manquants']), 'aSuivre' => $v['manquants'] !== []];
}

/**
 * GET /marketing/objectifs-produits?campagne=&date= — l'écran du cockpit :
 * la campagne, ses produits, l'objectif de chaque magasin avec ses clients du
 * mois en regard, et le suivi magasin par magasin et pour le réseau.
 */
function ep_objectifs_produits(): array
{
    ensureObjectifsProduits();
    $auj = date('Y-m-d');
    $date = (string) ($_GET['date'] ?? $auj);
    if (!opDateValide($date) || $date > $auj) { $date = $auj; }
    $camps = opCampagnes();
    if ($camps === []) { return ['date' => $date, 'campagnes' => [], 'campagne' => null, 'vide' => 'Aucune campagne datée — ou module marketing absent de cette base.']; }
    $choisie = (int) ($_GET['campagne'] ?? 0);
    if ($choisie <= 0) {
        foreach ($camps as $c) { if ($c['debut'] <= $date && $c['fin'] >= $date) { $choisie = $c['id']; break; } }
        if ($choisie <= 0) { foreach ($camps as $c) { if ($c['debut'] <= $date) { $choisie = $c['id']; break; } } }
        if ($choisie <= 0) { $choisie = $camps[0]['id']; }
    }
    $camp = null;
    foreach ($camps as $c) { if ($c['id'] === $choisie) { $camp = $c; } }
    if ($camp === null) { $camp = $camps[0]; }

    @set_time_limit(120);
    $nomDe = opMagasins();
    // Tous les magasins actifs, pas seulement le périmètre déclaré de la
    // campagne : un objectif se pose là où on le décide, et le périmètre du
    // module marketing est parfois d'un autre âge. Le magasin hors périmètre
    // est dit tel, pas caché.
    $perim = opPerimetre($camp['id'], $nomDe);
    $perimExplicite = count($perim) < count($nomDe);
    $produits = opProduits($camp['id']);
    $pids = array_column($produits, 'id');
    $obj = opObjectifs($camp['id']);
    $cout = 0; $budget = SV_BUDGET_DEMANDE;
    $mags = [];
    $R = ['objectif' => 0, 'vendu' => 0, 'ceJour' => 0, 'rythme' => 0.0, 'ilFaut' => 0.0, 'projection' => 0, 'attenduPond' => 0.0, 'attenduPoids' => 0, 'parProduit' => [], 'parJour' => [], 'aSuivre' => false, 'retard' => 0, 'avecObjectif' => 0];
    foreach (array_keys($nomDe) as $sid) {
        $sid = (string) $sid;
        $o = $obj[$sid] ?? null;
        $b = $pids !== [] ? opBilan((int) $sid, $pids, $o, $camp['debut'], $camp['fin'], $date, $cout, $budget) : null;
        if ($b !== null) { unset($b['ouverts']); }
        $mags[] = ['id' => $sid, 'nom' => $nomDe[$sid], 'clientsMois' => opClientsMois((int) $sid), 'objectif' => $o, 'bilan' => $b,
            'horsPerimetre' => $perimExplicite && !in_array($sid, $perim, true)];
        if ($b === null) { continue; }
        $R['vendu'] += $b['vendu']; $R['ceJour'] += $b['ceJour'];
        if ($b['rythme'] !== null) { $R['rythme'] += $b['rythme']; }
        if ($b['projection'] !== null) { $R['projection'] += $b['projection']; }
        if ($b['aSuivre']) { $R['aSuivre'] = true; }
        foreach ($b['parProduit'] as $p) { $R['parProduit'][$p['id']] ??= ['id' => $p['id'], 'nom' => $p['nom'], 'q' => 0]; $R['parProduit'][$p['id']]['q'] += $p['q']; }
        foreach ($b['parJour'] as $d => $q) { $R['parJour'][$d] = ($R['parJour'][$d] ?? 0) + $q; }
        if ($o !== null) {
            $R['objectif'] += $o; $R['avecObjectif']++;
            if ($b['ilFaut'] !== null) { $R['ilFaut'] += $b['ilFaut']; }
            if ($b['attendu'] !== null) { $R['attenduPond'] += $b['attendu'] * $o; $R['attenduPoids'] += $o; }
            if ($b['etat'] === 'retard') { $R['retard']++; }
        }
    }
    ksort($R['parJour']);
    $parProduit = array_values($R['parProduit']);
    usort($parProduit, static fn ($a, $b) => $b['q'] <=> $a['q']);
    $attendu = $R['attenduPoids'] > 0 ? round($R['attenduPond'] / $R['attenduPoids'], 1) : null;
    $pct = $R['objectif'] > 0 ? round(100 * $R['vendu'] / $R['objectif'], 1) : null;
    $etat = 'sans';
    if ($pct !== null) {
        $etat = $R['vendu'] >= $R['objectif'] ? 'atteint' : ($attendu === null ? 'sans' : ($pct >= $attendu ? 'avance' : ($pct >= $attendu - 8 ? 'clous' : 'retard')));
    }
    $cumul = []; $acc = 0;
    foreach ($R['parJour'] as $d => $q) { $acc += $q; $cumul[] = ['date' => $d, 'jour' => (int) round($q), 'cumul' => (int) round($acc)]; }
    $reseau = ['objectif' => $R['objectif'], 'vendu' => $R['vendu'], 'ceJour' => $R['ceJour'], 'pct' => $pct, 'attendu' => $attendu, 'etat' => $etat,
        'rythme' => round($R['rythme'], 1), 'ilFaut' => round($R['ilFaut'], 1), 'projection' => $R['projection'],
        'enRetard' => $R['retard'], 'avecObjectif' => $R['avecObjectif'], 'parProduit' => $parProduit, 'cumul' => $cumul, 'aSuivre' => $R['aSuivre']];
    return ['date' => $date, 'aujourdhui' => $auj, 'campagnes' => $camps, 'campagne' => $camp, 'produits' => $produits, 'magasins' => $mags, 'reseau' => $reseau,
        'ticketsLus' => $cout, 'source' => 'tickets du panel gravés jour par jour (svP) · jours ouverts d’après les heures gravées (svH)'];
}

/** Les volumes du dernier mois clos sur le réseau — [pid => [nom, cat, vol]], tranches déjà gravées pour l'analyse produits. */
function opVolumesMoisClos(): array
{
    $finTs = strtotime(date('Y-m-01') . ' -1 day');
    $du = date('Y-m-01', $finTs); $au = date('Y-m-t', $finTs);
    $out = [];
    if (!function_exists('apTranches2') || !class_exists('PanelApi') || !PanelApi::configured()) { return $out; }
    try {
        $couples = [];
        foreach (array_keys(opMagasins()) as $sid) { $couples[] = [(int) $sid, $du, $au]; }
        foreach (apTranches2($couples) as $p) {
            if (!is_array($p)) { continue; }
            foreach ($p as $pid => $x) {
                $pid = (int) $pid;
                $out[$pid] ??= ['nom' => (string) $x[0], 'cat' => trim((string) $x[1]), 'vol' => 0.0];
                $out[$pid]['vol'] += (float) $x[2];
            }
        }
    } catch (Throwable $e) { /* la recherche vit sans les volumes */ }
    return $out;
}

/**
 * GET /marketing/catalogue?q=pomme — la recherche du multiselect : les
 * produits du catalogue dont le nom contient le mot, avec leur catégorie et
 * ce que le réseau en a vendu le mois dernier, pour cocher en connaissance.
 */
function ep_catalogue_produits(): array
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $finTs = strtotime(date('Y-m-01') . ' -1 day');
    $mois = OP_MOIS[(int) date('n', $finTs)];
    if (mb_strlen($q) < 2) { return ['q' => $q, 'mois' => $mois, 'produits' => []]; }
    $cats = function_exists('catalogueCategories') ? (catalogueCategories() ?? []) : [];
    $rows = [];
    // Le catalogue du panel d'abord (products/available), la copie `product` si le panel se tait.
    foreach (function_exists('panelCatalogue') ? panelCatalogue()['produits'] : [] as $x) {
        if (!empty($x['actif']) && mb_stripos((string) $x['nom'], $q) !== false) { $rows[] = ['id' => $x['id'], 'name' => $x['nom'], 'id_category' => $x['catId'] ?? 0, 'cat' => $x['cat'] ?? '']; }
    }
    if ($rows === []) {
        try { $rows = Db::rows('SELECT id, name, id_category FROM product WHERE is_active = 1 AND name LIKE ? ORDER BY name LIMIT 300', ['%' . $q . '%']); }
        catch (PDOException $e) { $rows = []; }
    }
    $vol = opVolumesMoisClos();
    $out = [];
    foreach ($rows as $r) {
        $pid = (int) $r['id'];
        if ($pid <= 0) { continue; }
        $cat = trim((string) ($r['cat'] ?? ''));
        if ($cat === '') { $cat = trim((string) ($cats[(int) ($r['id_category'] ?? 0)]['nom'] ?? '')); }
        if ($cat === '') { $cat = (string) ($vol[$pid]['cat'] ?? ''); }
        $out[$pid] = ['id' => $pid, 'nom' => trim((string) $r['name']), 'categorie' => $cat, 'volume' => (int) round($vol[$pid]['vol'] ?? 0)];
    }
    // Ce que les tranches du panel connaissent et que le catalogue local n'a pas rendu.
    foreach ($vol as $pid => $x) {
        if (isset($out[$pid]) || mb_stripos($x['nom'], $q) === false) { continue; }
        $out[$pid] = ['id' => $pid, 'nom' => $x['nom'], 'categorie' => $x['cat'], 'volume' => (int) round($x['vol'])];
    }
    $out = array_values($out);
    usort($out, static fn ($a, $b) => [$a['categorie'], -$a['volume'], $a['nom']] <=> [$b['categorie'], -$b['volume'], $b['nom']]);
    return ['q' => $q, 'mois' => $mois, 'produits' => array_slice($out, 0, 80)];
}

/**
 * PUT /marketing/campagnes/{id}/produits — { produits: [{id, nom, categorie}], objectifs: { shopId: pieces } }.
 * Chaque clé absente laisse l'autre intacte : l'écran écrit les produits à un
 * clic, les objectifs à une pause de frappe. Un objectif vide ou nul s'efface.
 */
function wr_objectifs_produits(int $id): array
{
    ensureObjectifsProduits();
    try { $camp = Db::row('SELECT id, name FROM mar_campaign WHERE id = ?', [$id]); }
    catch (PDOException $e) { http_response_code(503); return ['error' => 'les tables du module marketing sont absentes de cette base']; }
    if ($camp === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $b = body();
    $nomDe = opMagasins();
    $nP = null; $nO = 0; $nE = 0;
    if (array_key_exists('produits', $b) && is_array($b['produits'])) {
        Db::exec('DELETE FROM ceo_campagne_produit WHERE campagne_id = ?', [$id]);
        $vus = [];
        foreach ($b['produits'] as $p) {
            $pid = (int) (is_array($p) ? ($p['id'] ?? 0) : $p);
            if ($pid <= 0 || isset($vus[$pid])) { continue; }
            $vus[$pid] = true;
            $cat = mb_substr(trim((string) (is_array($p) ? ($p['categorie'] ?? '') : '')), 0, 120);
            Db::exec('INSERT INTO ceo_campagne_produit (campagne_id, product_id, nom, categorie) VALUES (?,?,?,?)',
                [$id, $pid, mb_substr(trim((string) (is_array($p) ? ($p['nom'] ?? '') : '')), 0, 200), $cat !== '' ? $cat : null]);
        }
        $nP = count($vus);
    }
    if (array_key_exists('objectifs', $b) && is_array($b['objectifs'])) {
        foreach ($b['objectifs'] as $shopId => $pieces) {
            $sid = (string) $shopId;
            if ($sid === '' || !isset($nomDe[$sid])) { continue; }
            $n = (int) round((float) str_replace(',', '.', trim((string) $pieces)));
            if ($n <= 0) {
                Db::exec('DELETE FROM ceo_campagne_objectif_produit WHERE campagne_id = ? AND shop_id = ?', [$id, $sid]);
                $nE++;
                continue;
            }
            Db::exec('INSERT INTO ceo_campagne_objectif_produit (campagne_id, shop_id, pieces, maj) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE pieces = VALUES(pieces), maj = VALUES(maj)', [$id, $sid, $n, date('Y-m-d H:i:s')]);
            $nO++;
        }
    }
    if (($_GET['journal'] ?? '') !== '0') {
        journalAdd('CEO', 'Campagne', (string) $camp['name'], 'Objectifs produits — '
            . ($nP !== null ? $nP . ' produit(s), ' : '') . $nO . ' objectif(s) posé(s)' . ($nE ? ', ' . $nE . ' effacé(s)' : ''));
    }
    return ['ok' => true, 'produits' => $nP, 'objectifs' => $nO, 'effaces' => $nE];
}

/**
 * GET /exploitation/objectifs-produits?shop=&date= — le dashboard du magasin :
 * les campagnes en cours ce jour-là où le magasin a un objectif, avec sa jauge.
 */
function ep_objectifs_produits_magasin(): array
{
    ensureObjectifsProduits();
    $shop = trim((string) ($_GET['shop'] ?? ''));
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($shop === '' || !opDateValide($date)) { http_response_code(400); return ['error' => 'magasin ou date manquant']; }
    $out = ['shop' => $shop, 'date' => $date, 'aujourdhui' => date('Y-m-d'), 'campagnes' => []];
    $nomDe = opMagasins();
    $cout = 0; $budget = SV_BUDGET_DEMANDE;
    if (!isset($nomDe[$shop])) { return $out; }
    // L'objectif posé fait foi, pas le périmètre déclaré de la campagne.
    foreach (opCampagnes() as $c) {
        if ($c['debut'] > $date || $c['fin'] < $date) { continue; }
        $o = opObjectifs($c['id'])[$shop] ?? null;
        if ($o === null) { continue; }
        $produits = opProduits($c['id']);
        if ($produits === []) { continue; }
        $b = opBilan((int) $shop, array_column($produits, 'id'), $o, $c['debut'], $c['fin'], $date, $cout, $budget);
        unset($b['ouverts'], $b['parJour']);
        $out['campagnes'][] = ['id' => $c['id'], 'nom' => $c['nom'], 'debut' => $c['debut'], 'fin' => $c['fin'], 'produits' => $produits] + $b;
    }
    return $out;
}
