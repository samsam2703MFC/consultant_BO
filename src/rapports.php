<?php
declare(strict_types=1);

/**
 * Générateur de rapports — un rapport = un POSTE (le destinataire) × une
 * FRÉQUENCE × des BLOCS À SEUIL, le tout classé par levier.
 *
 * Les règles, décidées avec le CEO :
 *  - un bloc ne s'imprime que si son seuil est franchi : le rapport est une
 *    liste d'actions, pas une réédition des écrans ;
 *  - l'ordre des leviers ne change jamais : Trafic → Récurrence → Expérience
 *    Client → Food → Labour → Overhead → Transverse. Une section sans matière
 *    est absente, pas vide ;
 *  - un rapport entièrement sans matière n'est PAS envoyé (le run est
 *    journalisé « vide ») ;
 *  - une donnée indisponible se dit (« donnée indisponible ce jour ») — jamais
 *    un zéro silencieux, la règle du cockpit ;
 *  - tout le contenu vient des lectures existantes du cockpit : ce fichier
 *    ASSEMBLE, il ne recalcule rien de neuf.
 *
 * Les définitions vivent dans `ceo_rapport` (poste, fréquence, blocs,
 * destinataires) et chaque génération laisse une trace dans `ceo_rapport_run`
 * (résumé + HTML complet, relisible depuis l'écran Reporting).
 */

const RAP_LEVIERS = [
    'trafic'     => ['nom' => 'Trafic',            'couleur' => '#6366f1'],
    'recurrence' => ['nom' => 'Récurrence',        'couleur' => '#ec4899'],
    'xp'         => ['nom' => 'Expérience Client', 'couleur' => '#f59e0b'],
    'food-cost'  => ['nom' => 'Food Cost',         'couleur' => '#10b981'],
    'labour-cost' => ['nom' => 'Labour Cost',      'couleur' => '#8b5cf6'],
    'overhead-cost' => ['nom' => 'Overhead Cost',  'couleur' => '#64748b'],
    'transverse' => ['nom' => 'Transverse',        'couleur' => '#8D1D2C'],
];

function ensureRapports(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_rapport ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'code VARCHAR(40) NOT NULL UNIQUE,'
        . 'nom VARCHAR(120) NOT NULL,'
        . 'poste VARCHAR(60) NOT NULL,'
        . "frequence ENUM('quotidien','hebdo','mensuel') NOT NULL,"
        . 'heure TINYINT NOT NULL DEFAULT 7,'
        . 'jour TINYINT NOT NULL DEFAULT 1,'          // hebdo : 1=lundi ; mensuel : jour du mois
        . 'blocs TEXT NOT NULL,'                       // JSON [slugs]
        . 'destinataires TEXT NULL,'                   // JSON [emails]
        . 'par_magasin TINYINT NOT NULL DEFAULT 0,'
        . 'actif TINYINT NOT NULL DEFAULT 1'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_rapport_run ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'rapport_id INT NOT NULL,'
        . 'genere_le DATETIME NOT NULL,'
        . 'periode_du DATE NULL, periode_au DATE NULL,'
        . "statut ENUM('genere','envoye','vide','erreur') NOT NULL,"
        . 'resume VARCHAR(400) NULL,'
        . 'envoye_a TEXT NULL,'
        . 'html MEDIUMTEXT NULL,'
        . 'KEY idx_run_rapport (rapport_id, genere_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $n = Db::row('SELECT COUNT(*) n FROM ceo_rapport');
    if ((int) ($n['n'] ?? 0) > 0) {
        // Évolution des semis : la heatmap de rentabilité rejoint les rapports
        // CEO et franchisé déjà en base — une fois, sans toucher au reste.
        foreach (['ceo-hebdo', 'franchise-hebdo'] as $code) {
            $r = Db::row('SELECT id, blocs FROM ceo_rapport WHERE code = ?', [$code]);
            if ($r === null) { continue; }
            $blocs = json_decode((string) $r['blocs'], true) ?: [];
            if (!in_array('rentab-heatmap', $blocs, true)) {
                $blocs[] = 'rentab-heatmap';
                Db::exec('UPDATE ceo_rapport SET blocs = ? WHERE id = ?', [json_encode($blocs), (int) $r['id']]);
            }
        }
        return;
    }
    // Les cinq rapports proposés et validés — modifiables ensuite à l'écran.
    $seed = [
        ['consultant-quotidien', 'Exceptions de la veille', 'Consultant réseau', 'quotidien', 7, 1,
            ['xp-taches', 'recurrence-avis', 'food-stock'], 0],
        ['franchise-hebdo', 'Votre semaine, par levier', 'Franchisé', 'hebdo', 8, 1,
            ['trafic-clients', 'recurrence-panier', 'recurrence-avis', 'xp-taches', 'overhead-jours', 'rentab-heatmap'], 1],
        ['ceo-hebdo', 'Synthèse réseau de la semaine', 'CEO / direction réseau', 'hebdo', 7, 1,
            ['trafic-nvn1', 'trafic-clients', 'recurrence-panier', 'recurrence-avis', 'food-cost', 'labour-caetp', 'overhead-jours', 'rentab-heatmap', 'royalties-retard'], 0],
        ['ceo-mensuel', 'Clôture mensuelle du réseau', 'CEO / direction réseau', 'mensuel', 7, 3,
            ['trafic-nvn1', 'trafic-clients', 'recurrence-panier', 'food-cost', 'labour-caetp', 'overhead-jours', 'royalties-retard'], 0],
        ['centrale-hebdo', 'Approvisionnement et factures', 'Centrale d’achat', 'hebdo', 7, 4,
            ['centrale-commandes', 'food-stock', 'royalties-retard'], 0],
    ];
    foreach ($seed as $s) {
        Db::exec('INSERT INTO ceo_rapport (code, nom, poste, frequence, heure, jour, blocs, destinataires, par_magasin, actif)
                  VALUES (?,?,?,?,?,?,?,?,?,1)',
            [$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], json_encode($s[6]), json_encode([]), $s[7]]);
    }
}

/** Les seuils — ceux du cockpit (table kpi + cible Google), pas un doublon. */
function rapportSeuils(): array
{
    $s = [];
    try {
        foreach (Db::rows('SELECT code, seuil_bas, seuil_haut FROM kpi WHERE code IS NOT NULL') as $k) {
            $s[$k['code']] = $k['seuil_haut'] !== null ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
        }
    } catch (PDOException $e) { /* défauts ci-dessous */ }
    return [
        'food' => $s['food'] ?? 32.0, 'labour' => $s['labour'] ?? 33.0,
        'overhead' => $s['overhead'] ?? 13.5, 'caEtp' => $s['ca_etp'] ?? 13000.0,
        'cibleGoogle' => (float) setting('reputationCible', 4.5),
        'tacheNote' => 3,          // tâche « sous le seuil » : note ≤ 3/5
        'ecartTrafic' => -10.0,    // clients/jour vs moyenne réseau, en %
        'ecartPanier' => -5.0,     // ticket moyen et articles/ticket, en %
        'ecartNvn1' => -5.0,       // CA N vs N-1, en %
        'joursRouges' => 3,        // jours à résultat net négatif sur la semaine
    ];
}

/** Cache de contexte : chaque source n'est lue qu'une fois par génération. */
function rapCtx(string $cle, callable $fabrique)
{
    static $ctx = [];
    if (!array_key_exists($cle, $ctx)) {
        try { $ctx[$cle] = $fabrique(); }
        catch (Throwable $e) { $ctx[$cle] = null; }
    }
    return $ctx[$cle];
}

/** Appelle un endpoint existant en posant ses paramètres GET, puis les rend. */
function rapAppel(callable $ep, array $get = [])
{
    $avant = $_GET;
    foreach ($get as $k => $v) { $_GET[$k] = $v; }
    try { return $ep(); } finally { $_GET = $avant; }
}

function rapKpis()   { return rapCtx('kpis', fn () => ep_stores_kpis_annuels()); }
function rapReput()  { return rapCtx('reput', fn () => ep_reputation()); }
function rapRentab() { return rapCtx('rentab', fn () => rapAppel('ep_exploitation_rentabilite', ['periode' => 'semaine'])); }
function rapReseau(string $per) { return rapCtx('reseau-' . $per, fn () => rapAppel('ep_exploitation_reseau', ['periode' => $per])); }
function rapEtp()    { return rapCtx('etp', fn () => rapAppel('ep_stores_etp', ['annees' => date('Y')])); }
function rapStock()  { return rapCtx('stock', fn () => ep_ca_stock()); }
function rapCommandes() { return rapCtx('commandes', fn () => ep_ca_commandes()); }
function rapFactu()  { return rapCtx('factu', fn () => ep_ca_facturation()); }

/** Les tâches du panel sur une plage de dates — l'appel est lent, borné à 7 j. */
function rapTaches(string $du, string $au): array
{
    return rapCtx('taches-' . $du . '-' . $au, function () use ($du, $au) {
        $out = [];
        $d = new DateTimeImmutable($du);
        $fin = new DateTimeImmutable($au);
        for ($i = 0; $i < 7 && $d <= $fin; $i++, $d = $d->modify('+1 day')) {
            $r = rapAppel('ep_pwa_tasks', ['date' => $d->format('Y-m-d')]);
            foreach ((array) ($r['shops'] ?? []) as $sh) {
                foreach ((array) ($sh['taches'] ?? []) as $t) {
                    $t['magasin'] = (string) ($sh['shop'] ?? '');
                    $out[] = $t;
                }
            }
        }
        return $out;
    }) ?? [];
}

/** Le mois courant des KPIs annuels, par magasin + référence réseau. */
function rapKpisMois(): array
{
    $k = rapKpis();
    if (!is_array($k) || !empty($k['indispo'])) { return ['motif' => $k['motif'] ?? 'API panel indisponible']; }
    $m = (int) ($k['moisMax'] ?? 0);
    $res = ($k['reseau'] ?? [])[$m] ?? [];
    $mags = [];
    foreach ((array) ($k['magasins'] ?? []) as $mg) {
        $mags[] = ['nom' => $mg['nom'], 'v' => ($mg['mois'][$m] ?? [])];
    }
    $actifs = count(array_filter($mags, fn ($x) => ($x['v']['clientsJour'] ?? 0) > 0));
    return ['mois' => $m, 'reseau' => $res, 'magasins' => $mags, 'actifs' => max(1, $actifs)];
}

/* --------------------------------------------------------------------------
 * Les blocs. Chacun rend :
 *   ['levier' =>, 'titre' =>, 'action' =>, 'lignes' => [[magasin, texte, grave]],
 *    'infos' => lignes toujours affichées (franchisé), 'motif' => indisponibilité]
 * Un bloc sans lignes NI infos NI motif est simplement omis du rapport.
 * ------------------------------------------------------------------------ */

function rapBlocDefs(): array
{
    return [
        'trafic-clients' => ['levier' => 'trafic', 'nom' => 'Clients par jour vs moyenne réseau'],
        'trafic-nvn1' => ['levier' => 'trafic', 'nom' => 'CA N vs N-1'],
        'recurrence-panier' => ['levier' => 'recurrence', 'nom' => 'Ticket moyen et articles par ticket'],
        'recurrence-avis' => ['levier' => 'recurrence', 'nom' => 'Réputation Google'],
        'xp-taches' => ['levier' => 'xp', 'nom' => 'Tâches sous le seuil'],
        'food-cost' => ['levier' => 'food-cost', 'nom' => 'Food cost du mois'],
        'food-stock' => ['levier' => 'food-cost', 'nom' => 'Stocks négatifs'],
        'labour-caetp' => ['levier' => 'labour-cost', 'nom' => 'CA par ETP'],
        'overhead-jours' => ['levier' => 'overhead-cost', 'nom' => 'Jours à résultat net négatif'],
        'rentab-heatmap' => ['levier' => 'overhead-cost', 'nom' => 'Rentabilité par jour (heatmap)'],
        'royalties-retard' => ['levier' => 'transverse', 'nom' => 'Redevances en retard'],
        'centrale-commandes' => ['levier' => 'transverse', 'nom' => 'Commandes fournisseurs à passer'],
    ];
}

function rapBloc(string $slug, array $seuils, array $periode): array
{
    $fmtE = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' €';
    $fmtP = fn ($v) => str_replace('.', ',', (string) round((float) $v, 1)) . ' %';
    $b = ['slug' => $slug, 'lignes' => [], 'infos' => [], 'htmlPar' => [], 'motif' => null, 'action' => ''];

    switch ($slug) {
        case 'trafic-clients': {
            $b['action'] = 'Visibilité locale, vitrine, animations, signalétique.';
            $k = rapKpisMois();
            if (isset($k['motif'])) { $b['motif'] = $k['motif']; break; }
            $ref = ($k['reseau']['clientsJour'] ?? null);
            $ref = $ref !== null ? $ref / $k['actifs'] : null;
            foreach ($k['magasins'] as $m) {
                $v = $m['v']['clientsJour'] ?? null;
                if ($v === null || $ref === null || $ref <= 0) { continue; }
                $e = ($v / $ref - 1) * 100;
                if ($e < $seuils['ecartTrafic']) {
                    $b['lignes'][] = [$m['nom'], str_replace('.', ',', (string) round($v, 1)) . ' clients/jour, '
                        . $fmtP($e) . ' sous la moyenne réseau (' . str_replace('.', ',', (string) round($ref, 1)) . ')', true];
                }
            }
            break;
        }
        case 'trafic-nvn1': {
            $b['action'] = 'Comparer au marché local, vérifier les horaires et l’offre du moment.';
            $r = rapReseau($periode['nvn1'] ?? 'semaine');
            if (!is_array($r) || ($r['etat'] ?? '') !== 'ok') { $b['motif'] = $r['motif'] ?? 'API panel indisponible'; break; }
            foreach ((array) $r['magasins'] as $m) {
                if ($m['ecart'] !== null && $m['ecart'] < $seuils['ecartNvn1']) {
                    $b['lignes'][] = [$m['magasin'], 'CA ' . $fmtE($m['n']) . ' contre ' . $fmtE($m['n1']) . ' un an plus tôt (' . $fmtP($m['ecart']) . ')', true];
                }
            }
            break;
        }
        case 'recurrence-panier': {
            $b['action'] = 'Vente additionnelle au comptoir, offres de retour, fidélisation.';
            $k = rapKpisMois();
            if (isset($k['motif'])) { $b['motif'] = $k['motif']; break; }
            foreach ([['panier', 'ticket moyen', fn ($v) => $fmtE($v)], ['items', 'articles/ticket', fn ($v) => str_replace('.', ',', (string) $v)]] as [$cle, $lib, $f]) {
                $ref = $k['reseau'][$cle] ?? null;
                foreach ($k['magasins'] as $m) {
                    $v = $m['v'][$cle] ?? null;
                    if ($v === null || $ref === null || $ref <= 0) { continue; }
                    $e = ($v / $ref - 1) * 100;
                    if ($e < $seuils['ecartPanier']) {
                        $b['lignes'][] = [$m['nom'], ucfirst($lib) . ' ' . $f($v) . ', ' . $fmtP($e) . ' sous la moyenne réseau (' . $f($ref) . ')', false];
                    }
                }
            }
            break;
        }
        case 'recurrence-avis': {
            $b['action'] = 'Répondre aux avis, corriger la cause, solliciter les clients satisfaits.';
            $r = rapReput();
            if (!is_array($r) || !empty($r['indispo'])) { $b['motif'] = $r['raison'] ?? 'tables de réputation absentes'; break; }
            $cible = (float) ($r['cible'] ?? $seuils['cibleGoogle']);
            foreach ((array) $r['magasins'] as $m) {
                if ($m['note'] !== null && $m['note'] < $cible) {
                    $b['lignes'][] = [$m['nom'], 'Note Google ' . str_replace('.', ',', (string) $m['note']) . ' (' . $m['avis'] . ' avis) — '
                        . ($m['avis5Requis'] !== null ? $m['avis5Requis'] . ' avis 5★ pour atteindre ' . str_replace('.', ',', (string) $cible) : 'sous la cible'), false];
                }
                foreach ((array) ($m['derniers'] ?? []) as $a) {
                    if ((int) $a['note'] <= 3) {
                        $b['lignes'][] = [$m['nom'], 'Avis ' . $a['note'] . '★ de ' . $a['auteur'] . ' (' . $a['le'] . ') : « '
                            . mb_substr((string) ($a['texte'] ?? ''), 0, 220) . (mb_strlen((string) ($a['texte'] ?? '')) > 220 ? '…' : '') . ' »', (int) $a['note'] <= 2];
                    }
                }
                if (!empty($periode['avisComplets'])) {
                    foreach (array_slice((array) ($m['derniers'] ?? []), 0, 5) as $a) {
                        $b['infos'][] = [$m['nom'], $a['note'] . '★ · ' . $a['auteur'] . ' (' . $a['le'] . ') : « '
                            . mb_substr((string) ($a['texte'] ?? ''), 0, 180) . (mb_strlen((string) ($a['texte'] ?? '')) > 180 ? '…' : '') . ' »'];
                    }
                }
            }
            break;
        }
        case 'xp-taches': {
            $b['action'] = 'Photo annotée et commentaire dans le cockpit — à revoir avec l’équipe.';
            $ts = rapTaches($periode['du'], $periode['au']);
            if ($ts === []) { $b['motif'] = 'aucune tâche lue sur la période (API panel)'; break; }
            foreach ($ts as $t) {
                $note = $t['note'] ?? null;
                if ($note !== null && (int) $note <= $seuils['tacheNote']) {
                    $b['lignes'][] = [$t['magasin'], '« ' . ($t['tache'] ?? ('Tâche #' . ($t['taskId'] ?? '?'))) . ' » notée ' . $note . '/5 le '
                        . substr((string) ($t['date'] ?? ''), 5) . ($t['comment'] ? ' — ' . mb_substr((string) $t['comment'], 0, 160) : ''), (int) $note <= 2];
                }
            }
            break;
        }
        case 'food-cost': {
            $b['action'] = 'Revoir fiches techniques, contrôle réception ProdAtelier et gestion casse.';
            if (!PanelApi::configured()) { $b['motif'] = 'compte consultant non configuré'; break; }
            try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
            catch (PDOException $e) { $b['motif'] = 'référentiel magasins indisponible'; break; }
            foreach ($shops as $s) {
                $hm = rapCtx('hm-mois-' . $s['id'], fn () => PanelApi::marginHeatmapEntre((int) $s['id'], date('Y-m-01'), date('Y-m-d')));
                $t = is_array($hm) ? ($hm['totals'] ?? []) : [];
                $ca = (float) ($t['ca'] ?? 0); $mb2 = (float) ($t['margin_value'] ?? 0);
                if ($ca <= 0) { continue; }
                $fc = ($ca - $mb2) / $ca * 100;
                if ($fc > $seuils['food']) {
                    $b['lignes'][] = [$s['name'], 'Food cost ' . $fmtP($fc) . ' sur le mois en cours (seuil ' . $fmtP($seuils['food']) . ')', $fc > $seuils['food'] + 4];
                }
            }
            break;
        }
        case 'food-stock': {
            $b['action'] = 'Réapprovisionner ou corriger l’inventaire — un stock négatif est une saisie fausse.';
            $st = rapStock();
            if (!is_array($st) || ($st['etat'] ?? '') !== 'ok') { $b['motif'] = 'inventaire indisponible (API panel)'; break; }
            $neg = array_values(array_filter((array) $st['lignes'], fn ($l) => (float) $l['stock'] < 0));
            $parMag = [];
            foreach ($neg as $l) { $parMag[$l['magasin']][] = $l; }
            foreach ($parMag as $mag => $ls) {
                usort($ls, fn ($a, $c) => $a['stock'] <=> $c['stock']);
                $ex = array_slice(array_map(fn ($l) => $l['ref'] . ' (' . str_replace('.', ',', (string) $l['stock']) . ' ' . $l['unite'] . ')', $ls), 0, 3);
                $b['lignes'][] = [$mag, count($ls) . ' référence(s) en stock négatif — ' . implode(' · ', $ex) . (count($ls) > 3 ? ' …' : ''), count($ls) >= 10];
            }
            break;
        }
        case 'labour-caetp': {
            $b['action'] = 'Revoir le dimensionnement d’équipe et la productivité horaire.';
            $r = rapReseau('mois');
            $etp = rapEtp();
            if (!is_array($r) || ($r['etat'] ?? '') !== 'ok') { $b['motif'] = $r['motif'] ?? 'API panel indisponible'; break; }
            $eParShop = [];
            foreach ((array) $etp as $e) {
                if ((int) $e['annee'] === (int) date('Y') && (int) $e['mois'] === (int) date('n')) {
                    $eParShop[(string) $e['storeId']] = (float) $e['etp'];
                }
            }
            if ($eParShop === []) { $b['motif'] = 'aucun planning du mois (ETP inconnus) — jamais un ETP deviné'; break; }
            foreach ((array) $r['magasins'] as $m) {
                $e = $eParShop[(string) $m['shopId']] ?? null;
                if ($e === null || $e <= 0 || $m['n'] === null) { continue; }
                $ce = $m['n'] / $e;
                if ($ce < $seuils['caEtp']) {
                    $b['lignes'][] = [$m['magasin'], 'CA/ETP ' . $fmtE($ce) . ' sur le mois (' . str_replace('.', ',', (string) $e) . ' ETP planifiés, minimum ' . $fmtE($seuils['caEtp']) . ')', true];
                }
            }
            break;
        }
        case 'overhead-jours': {
            $b['action'] = 'Auditer loyer, énergies et abonnements ; renégocier les contrats.';
            $r = rapRentab();
            if (!is_array($r) || !empty($r['indispo'])) { $b['motif'] = $r['motif'] ?? 'API panel indisponible'; break; }
            foreach ((array) $r['magasins'] as $m) {
                if (!empty($m['indispo'])) { continue; }
                $rouges = array_values(array_filter((array) $m['jours'], fn ($j) => $j['net'] !== null && $j['net'] < 0));
                if (count($rouges) >= $seuils['joursRouges']) {
                    $pire = null;
                    foreach ($rouges as $j) { if ($pire === null || $j['netPct'] < $pire['netPct']) { $pire = $j; } }
                    $b['lignes'][] = [$m['nom'], count($rouges) . ' jours à résultat net négatif sur la semaine '
                        . date('d/m', strtotime($r['du'])) . ' → ' . date('d/m', strtotime($r['au']))
                        . ($pire ? ' (jusqu’à ' . $fmtP($pire['netPct']) . ')' : ''), count($rouges) >= 5];
                }
            }
            break;
        }
        case 'rentab-heatmap': {
            // La heatmap de l'écran P&L, en version email : un tableau à
            // cellules colorées (les clients mail ne lisent ni CSS externe ni
            // SVG — des <td> à fond plein passent partout). Bloc informatif :
            // il s'affiche toujours, il ne dépend d'aucun seuil.
            $b['action'] = '';
            $r = rapRentab();
            if (!is_array($r) || !empty($r['indispo'])) { $b['motif'] = $r['motif'] ?? 'API panel indisponible'; break; }
            $JR = [1 => 'LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'];
            $teinte = function (?float $p): array {
                if ($p === null) { return ['#EEE9E1', '#8a8177']; }
                if ($p < 0) { return ['#8D1D2C', '#ffffff']; }
                if ($p < 5) { return ['#C17A2A', '#ffffff']; }
                if ($p < 10) { return ['#A8B545', '#ffffff']; }
                if ($p < 15) { return ['#7CB342', '#ffffff']; }
                if ($p < 25) { return ['#3D8B44', '#ffffff']; }
                return ['#C9A227', '#ffffff'];
            };
            foreach ((array) $r['magasins'] as $m) {
                if (!empty($m['indispo'])) { continue; }
                $cells = '';
                foreach ((array) $m['jours'] as $j) {
                    [$fond, $txt] = $teinte($j['ouvert'] ? $j['netPct'] : null);
                    $val = !$j['ouvert'] ? 'fermé'
                        : ($j['netPct'] === null ? '—' : str_replace('.', ',', (string) $j['netPct']) . ' %');
                    $cells .= '<td style="background:' . $fond . ';color:' . $txt . ';border-radius:6px;padding:6px 9px;'
                        . 'text-align:center;font-family:sans-serif;font-size:11px;white-space:nowrap">'
                        . '<div style="font-size:9px;letter-spacing:0.04em;opacity:0.85">' . ($JR[(int) $j['wd']] ?? '') . '</div>'
                        . '<div style="font-weight:700;margin-top:1px">' . $val . '</div></td>';
                }
                $tot = $m['total'] ?? [];
                $sous = isset($tot['netPct']) && $tot['netPct'] !== null
                    ? 'semaine : ' . str_replace('.', ',', (string) $tot['netPct']) . ' % de résultat net' : '';
                $b['htmlPar'][] = [$m['nom'],
                    '<table cellpadding="0" cellspacing="3" style="border-collapse:separate;margin:4px 0 2px"><tr>' . $cells . '</tr></table>'
                    . ($sous !== '' ? '<div style="font-size:11px;color:#6E645A">' . htmlspecialchars($sous) . '</div>' : '')];
            }
            break;
        }
        case 'royalties-retard': {
            $b['action'] = 'Relancer — la relance et la date de paiement se suivent dans Facturation magasins.';
            $f = rapFactu();
            if (!is_array($f) || ($f['etat'] ?? '') !== 'ok') { $b['motif'] = 'compte admin ERP non configuré'; break; }
            $auj = date('Y-m-d');
            foreach ((array) ($f['redevances'] ?? []) as $r2) {
                // Une facture à 0 € « en retard » est un artefact d'émission,
                // pas une créance : elle ne mérite pas une ligne d'action.
                if ((float) ($r2['montant'] ?? 0) <= 0) { continue; }
                if (($r2['paiement'] ?? '') !== 'paid' && ($r2['echeance'] ?? '') !== '' && $r2['echeance'] < $auj) {
                    $b['lignes'][] = [$r2['magasin'], 'Facture ' . $r2['numero'] . ' de ' . $fmtE($r2['montant'])
                        . ' échue le ' . $r2['echeance'] . ($r2['relanceLe'] ? ' (relancée le ' . substr((string) $r2['relanceLe'], 0, 10) . ')' : ' — jamais relancée'), true];
                }
            }
            break;
        }
        case 'centrale-commandes': {
            $b['action'] = 'Une commande par fournisseur — montants estimés sur les besoins courants.';
            $c = rapCommandes();
            if (!is_array($c) || ($c['etat'] ?? '') !== 'ok') { $b['motif'] = 'API panel indisponible'; break; }
            foreach ((array) ($c['aCommander'] ?? []) as $l) {
                $b['lignes'][] = [$l['magasin'], $l['fournisseur'] . ' — ' . $l['nbRefs'] . ' référence(s), ' . $fmtE($l['montant']), false];
            }
            break;
        }
    }
    return $b;
}

/* --------------------------------------------------------------------------
 * Génération, rendu, envoi, cron.
 * ------------------------------------------------------------------------ */

function rapPeriode(string $freq): array
{
    $auj = new DateTimeImmutable('today');
    if ($freq === 'quotidien') {
        $h = $auj->modify('-1 day')->format('Y-m-d');
        return ['du' => $h, 'au' => $h, 'nvn1' => 'jour', 'label' => 'journée du ' . $h];
    }
    if ($freq === 'mensuel') {
        $du = $auj->modify('first day of last month');
        return ['du' => $du->format('Y-m-d'), 'au' => $du->modify('last day of this month')->format('Y-m-d'),
            'nvn1' => 'mois', 'label' => 'mois de ' . $du->format('m/Y')];
    }
    $lundi = $auj->modify('monday this week')->modify('-7 days');
    return ['du' => $lundi->format('Y-m-d'), 'au' => $lundi->modify('+6 days')->format('Y-m-d'),
        'nvn1' => 'semaine', 'label' => 'semaine ' . $lundi->format('d/m') . ' → ' . $lundi->modify('+6 days')->format('d/m')];
}

function rapportGenerer(array $rep): array
{
    ensureRapports();
    $seuils = rapportSeuils();
    $periode = rapPeriode((string) $rep['frequence']);
    if ((string) $rep['code'] === 'franchise-hebdo') { $periode['avisComplets'] = true; }

    $blocs = json_decode((string) $rep['blocs'], true) ?: [];
    $defs = rapBlocDefs();
    $sections = [];
    foreach ($blocs as $slug) {
        if (!isset($defs[$slug])) { continue; }
        $b = rapBloc($slug, $seuils, $periode);
        $b['nom'] = $defs[$slug]['nom'];
        $b['levier'] = $defs[$slug]['levier'];
        if ($b['lignes'] !== [] || $b['infos'] !== [] || $b['htmlPar'] !== [] || $b['motif'] !== null) { $sections[] = $b; }
    }
    $ordre = array_keys(RAP_LEVIERS);
    usort($sections, fn ($a, $b2) => array_search($a['levier'], $ordre, true) <=> array_search($b2['levier'], $ordre, true));

    $nPoints = array_sum(array_map(fn ($s) => count($s['lignes']), $sections));
    $nMotifs = count(array_filter($sections, fn ($s) => $s['motif'] !== null));
    $resume = $nPoints . ' point(s) à traiter'
        . ($nMotifs ? ' · ' . $nMotifs . ' bloc(s) sans donnée' : '')
        . ' — ' . $periode['label'];
    $vide = $nPoints === 0 && !array_filter($sections, fn ($s) => $s['infos'] !== []);

    $html = rapportHtml($rep, $sections, $periode, $seuils, $resume);
    Db::exec('INSERT INTO ceo_rapport_run (rapport_id, genere_le, periode_du, periode_au, statut, resume, html)
              VALUES (?,?,?,?,?,?,?)',
        [(int) $rep['id'], date('Y-m-d H:i:s'), $periode['du'], $periode['au'],
         $vide ? 'vide' : 'genere', $resume, $html]);
    $runId = (int) Db::pdo()->lastInsertId();
    journalAdd('CEO', 'Rapport', $rep['nom'], 'Généré — ' . $resume);
    return ['runId' => $runId, 'statut' => $vide ? 'vide' : 'genere', 'resume' => $resume];
}

function rapportHtml(array $rep, array $sections, array $periode, array $seuils, string $resume): string
{
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $parMagasin = !empty($rep['par_magasin']);
    $reseau = setting('reseau', []);
    $marque = is_array($reseau) ? ($reseau['nom'] ?? 'Réseau') : 'Réseau';

    $rendSections = function (array $secs, ?string $magasin) use ($e): string {
        $h = '';
        foreach ($secs as $s) {
            $lignes = $magasin === null ? $s['lignes'] : array_values(array_filter($s['lignes'], fn ($l) => $l[0] === $magasin));
            $infos = $magasin === null ? $s['infos'] : array_values(array_filter($s['infos'], fn ($l) => $l[0] === $magasin));
            $htmls = $magasin === null ? ($s['htmlPar'] ?? []) : array_values(array_filter($s['htmlPar'] ?? [], fn ($l) => $l[0] === $magasin));
            if ($lignes === [] && $infos === [] && $htmls === [] && $s['motif'] === null) { continue; }
            $lev = RAP_LEVIERS[$s['levier']];
            $h .= '<div style="margin:18px 0 6px"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:' . $lev['couleur'] . ';margin-right:7px"></span>'
                . '<b style="font-size:14px">' . $e($lev['nom']) . ' — ' . $e($s['nom']) . '</b></div>';
            if ($s['motif'] !== null) {
                $h .= '<div style="color:#8a5a13;background:#FBEFE0;border:1px solid #E8C9A0;border-radius:8px;padding:7px 11px;font-size:12.5px">Donnée indisponible : ' . $e($s['motif']) . '</div>';
            }
            foreach ($lignes as $l) {
                $h .= '<div style="padding:7px 0;border-bottom:1px solid #EDE7DE;font-size:13px">'
                    . ($l[2] ? '<span style="color:#8D1D2C;font-weight:700">● </span>' : '<span style="color:#C17A2A;font-weight:700">● </span>')
                    . ($magasin === null ? '<b>' . $e($l[0]) . '</b> — ' : '') . $e($l[1]) . '</div>';
            }
            foreach ($infos as $l) {
                $h .= '<div style="padding:6px 0;border-bottom:1px solid #F3EEE6;font-size:12.5px;color:#6E645A">'
                    . ($magasin === null ? '<b>' . $e($l[0]) . '</b> — ' : '') . $e($l[1]) . '</div>';
            }
            foreach ($htmls as $l) {
                // Contenu déjà en HTML (heatmap) : construit par nos soins,
                // jamais issu d'une saisie — il ne passe pas par l'échappement.
                $h .= ($magasin === null ? '<div style="font-size:12.5px;font-weight:600;margin-top:8px">' . $e($l[0]) . '</div>' : '')
                    . $l[1];
            }
            if ($lignes !== [] && $s['action'] !== '') {
                $h .= '<div style="font-size:12px;color:#6E645A;padding:6px 0 0">Action : ' . $e($s['action']) . '</div>';
            }
        }
        return $h !== '' ? $h : '<div style="padding:12px 0;color:#2d7a3e;font-size:13px">Rien à signaler — tous les seuils sont respectés.</div>';
    };

    $corps = '';
    if ($parMagasin) {
        try { $shops = Db::rows('SELECT name FROM shops WHERE active = 1 ORDER BY name'); }
        catch (PDOException $ex) { $shops = []; }
        foreach ($shops as $s) {
            $corps .= '<h2 style="font-size:16px;margin:26px 0 2px;border-top:2px solid #E4DCD0;padding-top:16px">' . $e($s['name']) . '</h2>'
                . $rendSections($sections, (string) $s['name']);
        }
    } else {
        $corps = $rendSections($sections, null);
    }

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<title>' . $e($rep['nom']) . ' — ' . $e($periode['label']) . '</title></head>'
        . '<body style="margin:0;background:#F6F2EB;padding:26px 14px;font-family:\'Avenir Next\',\'Segoe UI\',system-ui,sans-serif;color:#221E1A">'
        . '<div style="max-width:760px;margin:0 auto;background:#fff;border:1px solid #E4DCD0;border-radius:12px;padding:26px 30px">'
        . '<div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#6E645A">' . $e($marque) . ' · ' . $e($rep['poste']) . '</div>'
        . '<h1 style="font-size:21px;margin:4px 0 2px">' . $e($rep['nom']) . '</h1>'
        . '<div style="font-size:12.5px;color:#6E645A;margin-bottom:6px">' . $e($periode['label']) . ' · généré le ' . date('d/m/Y à H:i') . ' · ' . $e($resume) . '</div>'
        . $corps
        . '<div style="margin-top:22px;padding-top:12px;border-top:1px solid #E4DCD0;font-size:11px;color:#6E645A">'
        . 'Seuils : food ' . $seuils['food'] . ' % · labour ' . $seuils['labour'] . ' % · overhead ' . $seuils['overhead']
        . ' % · CA/ETP ' . number_format($seuils['caEtp'], 0, ',', ' ') . ' € · tâches ≤ ' . $seuils['tacheNote'] . '/5 · cible Google '
        . str_replace('.', ',', (string) $seuils['cibleGoogle']) . ' — réglables dans le cockpit. Le détail vit dans le cockpit ; ce rapport ne liste que les seuils franchis.'
        . '</div></div></body></html>';
}

function rapportEnvoyer(array $rep, int $runId): array
{
    $run = Db::row('SELECT * FROM ceo_rapport_run WHERE id = ? AND rapport_id = ?', [$runId, (int) $rep['id']]);
    if ($run === null) { return ['ok' => false, 'error' => 'run inconnu']; }
    $dests = json_decode((string) ($rep['destinataires'] ?? '[]'), true) ?: [];
    $dests = array_values(array_filter($dests, fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
    if ($dests === []) { return ['ok' => false, 'error' => 'aucun destinataire valide — renseignez les emails sur la ligne du rapport']; }
    if (($run['statut'] ?? '') === 'vide') { return ['ok' => false, 'error' => 'rapport sans matière — non envoyé, c’est la règle']; }

    $sujet = '[' . $rep['poste'] . '] ' . $rep['nom'] . ' — ' . ($run['resume'] ?? '');
    // SMTP configuré (Paramètres) d'abord ; mail() de PHP en repli seulement.
    $viaSmtp = Smtp::configured();
    $ok = []; $derniereErreur = null;
    foreach ($dests as $d) {
        if ($viaSmtp) {
            $ok[$d] = Smtp::envoyer($d, $sujet, (string) $run['html']);
            if (!$ok[$d]) { $derniereErreur = Smtp::$lastError; }
        } else {
            $exp = (string) setting('rapportsExpediteur', 'cockpit@' . ($_SERVER['HTTP_HOST'] ?? 'atelierby.local'));
            $entetes = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . $exp . "\r\n";
            $ok[$d] = function_exists('mail') && @mail($d, '=?UTF-8?B?' . base64_encode($sujet) . '?=', (string) $run['html'], $entetes);
            if (!$ok[$d]) { $derniereErreur = 'mail() a refusé — configurez le SMTP dans Paramètres'; }
        }
    }
    $tous = !in_array(false, $ok, true);
    Db::exec('UPDATE ceo_rapport_run SET statut = ?, envoye_a = ? WHERE id = ?',
        [$tous ? 'envoye' : $run['statut'], json_encode(array_keys(array_filter($ok))), $runId]);
    journalAdd('CEO', 'Rapport', $rep['nom'], $tous ? 'Envoyé (' . ($viaSmtp ? 'SMTP' : 'mail()') . ') à ' . implode(', ', $dests)
        : 'Envoi échoué — ' . ($derniereErreur ?? implode(', ', array_keys(array_filter($ok, fn ($v) => !$v)))));
    return ['ok' => $tous, 'envoyes' => array_keys(array_filter($ok)),
        'echecs' => array_keys(array_filter($ok, fn ($v) => !$v)),
        'via' => $viaSmtp ? 'smtp' : 'mail',
        'note' => $tous ? null : $derniereErreur];
}

/* --- Endpoints -------------------------------------------------------------- */

function ep_rapports(): array
{
    ensureRapports();
    $reps = Db::rows('SELECT * FROM ceo_rapport ORDER BY id');
    $runs = Db::rows('SELECT r.id, r.rapport_id, r.genere_le, r.statut, r.resume, p.nom
                        FROM ceo_rapport_run r JOIN ceo_rapport p ON p.id = r.rapport_id
                       ORDER BY r.id DESC LIMIT 12');
    $dernier = [];
    foreach (Db::rows('SELECT rapport_id, MAX(id) mid FROM ceo_rapport_run GROUP BY rapport_id') as $x) {
        $dernier[(int) $x['rapport_id']] = (int) $x['mid'];
    }
    $parRun = [];
    foreach ($runs as $r) { $parRun[(int) $r['id']] = $r; }
    return [
        'rapports' => array_map(function ($r) use ($dernier, $parRun) {
            $mid = $dernier[(int) $r['id']] ?? null;
            $d = $mid !== null ? ($parRun[$mid] ?? Db::row('SELECT id, genere_le, statut, resume FROM ceo_rapport_run WHERE id = ?', [$mid])) : null;
            return ['id' => (int) $r['id'], 'code' => $r['code'], 'nom' => $r['nom'], 'poste' => $r['poste'],
                'frequence' => $r['frequence'], 'heure' => (int) $r['heure'], 'jour' => (int) $r['jour'],
                'blocs' => json_decode((string) $r['blocs'], true) ?: [],
                'destinataires' => json_decode((string) ($r['destinataires'] ?? '[]'), true) ?: [],
                'parMagasin' => (bool) $r['par_magasin'], 'actif' => (bool) $r['actif'],
                'dernier' => $d ? ['runId' => (int) $d['id'], 'le' => substr((string) $d['genere_le'], 0, 16),
                    'statut' => $d['statut'], 'resume' => $d['resume']] : null];
        }, $reps),
        'runs' => array_map(fn ($r) => ['runId' => (int) $r['id'], 'rapport' => $r['nom'],
            'le' => substr((string) $r['genere_le'], 0, 16), 'statut' => $r['statut'], 'resume' => $r['resume']], $runs),
        'blocs' => rapBlocDefs(),
        'leviers' => RAP_LEVIERS,
    ];
}

function wr_rapport_patch(int $id): array
{
    ensureRapports();
    $rep = Db::row('SELECT * FROM ceo_rapport WHERE id = ?', [$id]);
    if ($rep === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    $b = body();
    if (array_key_exists('actif', $b)) {
        Db::exec('UPDATE ceo_rapport SET actif = ? WHERE id = ?', [!empty($b['actif']) ? 1 : 0, $id]);
    }
    if (array_key_exists('destinataires', $b) && is_array($b['destinataires'])) {
        $d = array_values(array_filter(array_map('trim', $b['destinataires']), fn ($x) => $x !== ''));
        Db::exec('UPDATE ceo_rapport SET destinataires = ? WHERE id = ?', [json_encode($d), $id]);
    }
    if (isset($b['heure']) && is_numeric($b['heure'])) {
        Db::exec('UPDATE ceo_rapport SET heure = ? WHERE id = ?', [max(0, min(23, (int) $b['heure'])), $id]);
    }
    journalAdd('CEO', 'Rapport', (string) $rep['nom'], 'Réglages mis à jour');
    return ['ok' => true];
}

function wr_rapport_generer(int $id): array
{
    ensureRapports();
    $rep = Db::row('SELECT * FROM ceo_rapport WHERE id = ?', [$id]);
    if ($rep === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    return ['ok' => true] + rapportGenerer($rep);
}

function wr_rapport_envoyer(int $id): array
{
    ensureRapports();
    $rep = Db::row('SELECT * FROM ceo_rapport WHERE id = ?', [$id]);
    if ($rep === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    $g = rapportGenerer($rep);
    if ($g['statut'] === 'vide') { return ['ok' => false, 'runId' => $g['runId'], 'error' => 'rapport sans matière — non envoyé']; }
    return ['runId' => $g['runId']] + rapportEnvoyer($rep, $g['runId']);
}

/** GET /rapports/run/{id} — la page HTML du rapport, telle qu'envoyée. */
function ep_rapport_run(int $id): array
{
    ensureRapports();
    $run = Db::row('SELECT html FROM ceo_rapport_run WHERE id = ?', [$id]);
    if ($run === null || $run['html'] === null) { http_response_code(404); return ['error' => 'run inconnu']; }
    header('Content-Type: text/html; charset=UTF-8');
    echo (string) $run['html'];
    exit;
}

/**
 * GET /rapports/cron — à appeler chaque heure (cron serveur). Génère et envoie
 * ce qui est dû à l'heure courante ; un rapport déjà généré aujourd'hui ne
 * repart pas. Protégé par le réglage `rapportsJeton` (Paramètres → à créer) ;
 * sans jeton configuré, la route refuse — pas de générateur ouvert au monde.
 */
function ep_rapports_cron(): array
{
    ensureRapports();
    $jeton = (string) setting('rapportsJeton', '');
    if ($jeton === '' || !hash_equals($jeton, (string) ($_GET['jeton'] ?? ''))) {
        http_response_code(403);
        return ['error' => 'jeton absent ou invalide — poser ceo_app_setting.rapportsJeton'];
    }
    $h = (int) date('G'); $dow = (int) date('N'); $dom = (int) date('j');
    $faits = [];
    foreach (Db::rows('SELECT * FROM ceo_rapport WHERE actif = 1') as $rep) {
        $du = ((string) $rep['frequence'] === 'quotidien' && (int) $rep['heure'] === $h)
            || ((string) $rep['frequence'] === 'hebdo' && (int) $rep['jour'] === $dow && (int) $rep['heure'] === $h)
            || ((string) $rep['frequence'] === 'mensuel' && (int) $rep['jour'] === $dom && (int) $rep['heure'] === $h);
        if (!$du) { continue; }
        $deja = Db::row('SELECT id FROM ceo_rapport_run WHERE rapport_id = ? AND genere_le >= ? AND statut <> ?',
            [(int) $rep['id'], date('Y-m-d 00:00:00'), 'erreur']);
        if ($deja !== null) { continue; }
        $g = rapportGenerer($rep);
        $env = null;
        if ($g['statut'] !== 'vide') { $env = rapportEnvoyer($rep, $g['runId']); }
        $faits[] = ['rapport' => $rep['nom'], 'runId' => $g['runId'], 'statut' => $g['statut'],
            'envoi' => $env ? ($env['ok'] ? 'envoyé' : ($env['error'] ?? 'échec')) : 'non dû'];
    }
    return ['ok' => true, 'heure' => $h, 'faits' => $faits];
}

/* --- Machine d'envoi (SMTP) — réglages dans Paramètres ---------------------- */

/** GET /parametres/smtp — l'état, jamais le mot de passe. */
function ep_smtp(): array
{
    return Smtp::statut();
}

/** PUT /parametres/smtp — hôte, port, sécurité, utilisateur, mot de passe, expéditeur. */
function wr_smtp(): array
{
    $b = body();
    $cur = setting('smtp');
    if (!is_array($cur)) { $cur = []; }
    foreach (['hote', 'utilisateur', 'expediteur'] as $k) {
        if (array_key_exists($k, $b)) { $cur[$k] = trim((string) $b[$k]); }
    }
    if (isset($b['port']) && is_numeric($b['port'])) { $cur['port'] = max(1, min(65535, (int) $b['port'])); }
    if (isset($b['securite']) && in_array($b['securite'], ['ssl', 'tls', 'aucune'], true)) { $cur['securite'] = $b['securite']; }
    // Le mot de passe n'est réécrit que s'il est saisi — un champ vide garde
    // l'ancien (l'écran ne le relit jamais, il ne peut pas le renvoyer).
    if (isset($b['motDePasse']) && (string) $b['motDePasse'] !== '') { $cur['motDePasse'] = (string) $b['motDePasse']; }
    if (!empty($b['effacerMotDePasse'])) { unset($cur['motDePasse']); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['smtp', json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', '—', 'Machine d’envoi SMTP mise à jour (' . ($cur['hote'] ?? '—') . ')');
    return ['ok' => true] + Smtp::statut();
}

/** POST /parametres/smtp/test {a} — un email d'essai, verdict honnête. */
function wr_smtp_test(): array
{
    $b = body();
    $a = trim((string) ($b['a'] ?? ''));
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        return ['ok' => false, 'error' => 'adresse de test invalide'];
    }
    if (!Smtp::configured()) {
        return ['ok' => false, 'error' => 'SMTP non configuré — renseignez au moins l’hôte et l’expéditeur'];
    }
    $ok = Smtp::envoyer($a, 'Cockpit — test d’envoi SMTP',
        '<p>Ce message confirme que la machine d’envoi du cockpit fonctionne.</p>'
        . '<p style="color:#6E645A;font-size:12px">Envoyé le ' . date('d/m/Y à H:i') . ' depuis les Paramètres.</p>');
    journalAdd('CEO', 'Paramètre', '—', $ok ? 'Test SMTP réussi vers ' . $a : 'Test SMTP échoué — ' . (Smtp::$lastError ?? '?'));
    return $ok ? ['ok' => true, 'message' => 'Envoyé à ' . $a . ' — vérifiez la boîte (et les spams).']
        : ['ok' => false, 'error' => Smtp::$lastError ?? 'échec sans détail'];
}
