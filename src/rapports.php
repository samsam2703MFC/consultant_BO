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
    // Colonnes nées avec le COMPOSITEUR : jours cochés (semaine et calendrier
    // du mois), filtre de magasins, période explicite, mode complet ou
    // dépassements par bloc. Sur une base déjà en service, on complète.
    foreach (['ADD COLUMN jours TEXT NULL', 'ADD COLUMN magasins TEXT NULL',
              "ADD COLUMN periode VARCHAR(14) NULL", 'ADD COLUMN periode_du DATE NULL',
              'ADD COLUMN periode_au DATE NULL', 'ADD COLUMN modes TEXT NULL',
              "ADD COLUMN envoi_mode VARCHAR(12) NOT NULL DEFAULT 'groupe'",
              'ADD COLUMN dest_par_magasin TEXT NULL'] as $alter) {
        try { Db::exec('ALTER TABLE ceo_rapport ' . $alter); } catch (PDOException $e) { /* déjà là */ }
    }

    // Le jeton du cron naît tout seul : personne ne doit inventer un secret à
    // la main. L'écran Reporting affiche l'URL complète, prête pour crontab.
    if ((string) setting('rapportsJeton', '') === '') {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['rapportsJeton', json_encode(bin2hex(random_bytes(24)))]);
    }

    $n = Db::row('SELECT COUNT(*) n FROM ceo_rapport');
    if ((int) ($n['n'] ?? 0) > 0) {
        // Évolution des semis : les blocs nés après le premier semis rejoignent
        // les rapports déjà en base — une fois, sans toucher au reste.
        $evolutions = [
            'ceo-hebdo' => ['rentab-heatmap', 'kpi-derives'],
            'franchise-hebdo' => ['rentab-heatmap'],
        ];
        foreach ($evolutions as $code => $nouveaux) {
            $r = Db::row('SELECT id, blocs FROM ceo_rapport WHERE code = ?', [$code]);
            if ($r === null) { continue; }
            $blocs = json_decode((string) $r['blocs'], true) ?: [];
            $avant = count($blocs);
            foreach ($nouveaux as $nb) {
                if (!in_array($nb, $blocs, true)) { $blocs[] = $nb; }
            }
            if (count($blocs) > $avant) {
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
            ['trafic-nvn1', 'trafic-clients', 'recurrence-panier', 'recurrence-avis', 'food-cost', 'labour-caetp', 'overhead-jours', 'rentab-heatmap', 'kpi-derives', 'royalties-retard'], 0],
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

/**
 * Les seuils — lus dans le RÉFÉRENTIEL des KPI (ceo_kpi_def, éditable dans
 * Paramètres). Plus rien en dur : changer un seuil à l'écran change les
 * rapports au prochain run. Les défauts ne servent que si une ligne manque.
 */
function rapportSeuils(): array
{
    return [
        'food' => kpiSeuil('food-cost-pct', 32.0), 'labour' => kpiSeuil('labour-pct', 33.0),
        'overhead' => kpiSeuil('overhead-pct', 13.5), 'caEtp' => kpiSeuil('ca-etp', 13000.0),
        'cibleGoogle' => kpiSeuil('note-google', (float) setting('reputationCible', 4.5)),
        'tacheNote' => (int) kpiSeuil('taches', 3),
        'ecartTrafic' => kpiSeuil('trafic-clients', -10.0),
        'ecartPanier' => kpiSeuil('panier-ecart', -5.0),
        'ecartNvn1' => kpiSeuil('nvn1', -5.0),
        'joursRouges' => (int) kpiSeuil('jours-rouges', 3),
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
                    $t['shopId'] = (string) ($sh['shopId'] ?? '');
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
        'kpi-derives' => ['levier' => 'transverse', 'nom' => 'KPI personnalisés'],
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
            $vCible = str_replace('.', ',', (string) $cible);
            // Le chiffre qui rend la note actionnable : COMBIEN d'avis 5★ il
            // faut obtenir pour repasser la cible. Sans lui, « 4,1 sous 4,5 »
            // ne dit pas si l'effort est de dix avis ou de mille.
            $res = (array) ($r['reseau'] ?? []);
            if (($res['moyenne'] ?? null) !== null && (float) $res['moyenne'] < $cible) {
                $b['lignes'][] = ['Réseau', 'Moyenne ' . str_replace('.', ',', (string) $res['moyenne'])
                    . ' sur ' . (int) ($res['avis'] ?? 0) . ' avis — '
                    . (($res['avis5Requis'] ?? null) !== null
                        ? $res['avis5Requis'] . ' avis 5★ à obtenir pour revenir à ' . $vCible
                        : 'cible hors d’atteinte par ajout d’avis'), false];
            }
            foreach ((array) $r['magasins'] as $m) {
                if ($m['note'] !== null && $m['note'] < $cible) {
                    $b['lignes'][] = [$m['nom'], 'Note Google ' . str_replace('.', ',', (string) $m['note']) . ' (' . $m['avis'] . ' avis) — '
                        . ($m['avis5Requis'] !== null ? $m['avis5Requis'] . ' avis 5★ à obtenir pour atteindre ' . $vCible : 'sous la cible'), false];
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
            if ($b['lignes'] !== []) {
                $b['infos'][] = ['Méthode', 'Avis 5★ à obtenir = avis × (cible − note) ÷ (5 − cible), arrondi au supérieur — '
                    . 'le nombre d’avis parfaits qui ramène la moyenne à ' . $vCible . '. Cible réglable dans Paramètres.'];
            }
            break;
        }
        case 'xp-taches': {
            $b['action'] = 'Photo annotée et commentaire dans le cockpit — à revoir avec l’équipe.';
            $ts = rapTaches($periode['du'], $periode['au']);
            if ($ts === []) { $b['motif'] = 'aucune tâche lue sur la période (API panel)'; break; }
            $fiches = 0; $cartesParMag = [];
            foreach ($ts as $t) {
                $note = $t['note'] ?? null;
                if ($note !== null && (int) $note <= $seuils['tacheNote']) {
                    $b['lignes'][] = [$t['magasin'], '« ' . ($t['tache'] ?? ('Tâche #' . ($t['taskId'] ?? '?'))) . ' » notée ' . $note . '/5 le '
                        . substr((string) ($t['date'] ?? ''), 5) . ($t['comment'] ? ' — ' . mb_substr((string) $t['comment'], 0, 160) : ''), (int) $note <= 2];
                    // La FICHE : photo annotée des repères + référence attendue.
                    // Bornée à 8 — au-delà, le rapport le dit plutôt que de
                    // peser plusieurs mégaoctets dans une boîte mail.
                    if ($fiches < 8 && ($t['shopId'] ?? '') !== '') {
                        $fiche = rapFicheTache((string) $t['shopId'], (string) ($t['taskId'] ?? ''), (string) ($t['date'] ?? ''),
                            (string) ($t['tache'] ?? ''), (string) $t['magasin'], (int) $note, (string) ($t['comment'] ?? ''));
                        if ($fiche !== '') { $cartesParMag[(string) $t['magasin']][] = $fiche; $fiches++; }
                    } elseif ($fiches === 8) {
                        $b['infos'][] = [(string) $t['magasin'], 'Photos limitées aux 8 premières tâches — le reste se consulte dans le cockpit.'];
                        $fiches++;
                    }
                }
            }
            // Deux cartes par rangée, magasin par magasin — la grille A4.
            foreach ($cartesParMag ?? [] as $mag => $cartes) {
                $b['htmlPar'][] = [$mag, rapFichesGrille($cartes)];
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
        case 'kpi-derives': {
            // Les KPI créés au formulaire (Paramètres → Catalogue des KPI) :
            // chacun évalué par magasin sur le mois en cours, seuls les
            // dépassements s'impriment — même règle que les blocs câblés.
            $b['action'] = 'Seuils, formules et types de sortie dans Paramètres → Catalogue des KPI.';
            $aucunActif = true;
            foreach (kpiDefs() as $def) {
                if (!$def['actif'] || ($def['calcul']['type'] ?? '') !== 'derive') { continue; }
                $aucunActif = false;
                $lignes = kpiEvalDerive($def);
                if (($def['sortie'] ?? 'tableau') !== 'tableau') {
                    // Sortie visuelle (heatmap, barres, treemap) : tous les
                    // magasins s'affichent, la couleur porte les seuils.
                    $visu = kpiRenduVisuel($def, $lignes);
                    if ($visu !== '') { $b['htmlPar'][] = [$def['nom'], $visu]; }
                    continue;
                }
                foreach ($lignes as $l) {
                    if ($l['niveau'] === 0) { continue; }
                    $b['lignes'][] = [$l['magasin'], $def['nom'] . ' : ' . kpiFormatValeur($def, $l['valeur'])
                        . ' (' . ($def['sens'] === 'haut' ? 'seuil' : 'minimum') . ' '
                        . kpiFormatValeur($def, (float) $def['seuil_alerte']) . ')', $l['niveau'] >= 2];
                }
            }
            if ($aucunActif) { $b['motif'] = 'aucun KPI dérivé actif — créez-en dans Paramètres'; }
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

function rapPeriode(string $freq, ?string $periode = null, ?string $du = null, ?string $au = null): array
{
    $auj = new DateTimeImmutable('today');
    if ($periode === 'libre' && $du !== null && $au !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) && $du <= $au) {
        $n = (new DateTimeImmutable($du))->diff(new DateTimeImmutable($au))->days + 1;
        return ['du' => $du, 'au' => $au, 'nvn1' => $n <= 1 ? 'jour' : ($n <= 7 ? 'semaine' : 'mois'),
            'label' => 'du ' . date('d/m/Y', strtotime($du)) . ' au ' . date('d/m/Y', strtotime($au))];
    }
    if ($periode === 'hier' || ($periode === null && $freq === 'quotidien')) {
        $h = $auj->modify('-1 day')->format('Y-m-d');
        return ['du' => $h, 'au' => $h, 'nvn1' => 'jour', 'label' => 'journée du ' . $h];
    }
    if ($periode === 'mois-passe' || ($periode === null && $freq === 'mensuel')) {
        $du2 = $auj->modify('first day of last month');
        return ['du' => $du2->format('Y-m-d'), 'au' => $du2->modify('last day of this month')->format('Y-m-d'),
            'nvn1' => 'mois', 'label' => 'mois de ' . $du2->format('m/Y')];
    }
    if ($periode === 'mois-en-cours') {
        return ['du' => $auj->format('Y-m-01'), 'au' => $auj->format('Y-m-d'),
            'nvn1' => 'mois', 'label' => 'mois en cours (au ' . $auj->format('d/m') . ')'];
    }
    $lundi = $auj->modify('monday this week')->modify('-7 days');
    return ['du' => $lundi->format('Y-m-d'), 'au' => $lundi->modify('+6 days')->format('Y-m-d'),
        'nvn1' => 'semaine', 'label' => 'semaine ' . $lundi->format('d/m') . ' → ' . $lundi->modify('+6 days')->format('d/m')];
}

function rapportGenerer(array $rep): array
{
    ensureRapports();
    $seuils = rapportSeuils();
    $periode = rapPeriode((string) $rep['frequence'], $rep['periode'] ?? null,
        $rep['periode_du'] ?? null, $rep['periode_au'] ?? null);
    if ((string) $rep['code'] === 'franchise-hebdo') { $periode['avisComplets'] = true; }

    $blocs = json_decode((string) $rep['blocs'], true) ?: [];
    $modes = json_decode((string) ($rep['modes'] ?? ''), true) ?: [];
    $filtre = json_decode((string) ($rep['magasins'] ?? ''), true) ?: [];
    $defs = rapBlocDefs();
    $sections = [];
    foreach ($blocs as $slug) {
        if (!isset($defs[$slug])) { continue; }
        if (($modes[$slug] ?? '') === 'complet') { $periode['avisComplets'] = true; }
        $b = rapBloc($slug, $seuils, $periode);
        $b['nom'] = $defs[$slug]['nom'];
        $b['levier'] = $defs[$slug]['levier'];
        // Mode « tableau complet » : le tableau tel qu'à l'écran remplace la
        // liste des seuls dépassements. Un bloc sans rendu complet le dit.
        if (($modes[$slug] ?? '') === 'complet') {
            $complet = rapBlocComplet($slug, $seuils, $periode, $filtre);
            // Les dépassements restent comptés dans la pastille de résumé même
            // quand le tableau complet remplace leurs lignes.
            if ($complet !== null) { $b['htmlPar'] = array_merge($complet, $b['htmlPar']); $b['pointsCaches'] = $b['lignes']; $b['lignes'] = []; }
        }
        // Filtre de magasins (compositeur) : les lignes des autres sortent.
        if ($filtre !== []) {
            $b['lignes'] = array_values(array_filter($b['lignes'], fn ($l) => in_array($l[0], $filtre, true)));
            $b['infos'] = array_values(array_filter($b['infos'], fn ($l) => in_array($l[0], $filtre, true)));
            $b['htmlPar'] = array_values(array_filter($b['htmlPar'], fn ($l) => $l[0] === '' || in_array($l[0], $filtre, true)));
            $b['pointsCaches'] = array_values(array_filter($b['pointsCaches'] ?? [], fn ($l) => in_array($l[0], $filtre, true)));
        }
        if ($b['lignes'] !== [] || $b['infos'] !== [] || $b['htmlPar'] !== [] || $b['motif'] !== null) { $sections[] = $b; }
    }
    $ordre = array_keys(RAP_LEVIERS);
    usort($sections, fn ($a, $b2) => array_search($a['levier'], $ordre, true) <=> array_search($b2['levier'], $ordre, true));

    $nPoints = array_sum(array_map(fn ($s) => count($s['lignes']) + count($s['pointsCaches'] ?? []), $sections));
    $nMotifs = count(array_filter($sections, fn ($s) => $s['motif'] !== null));
    $resume = $nPoints . ' point(s) à traiter'
        . ($nMotifs ? ' · ' . $nMotifs . ' bloc(s) sans donnée' : '')
        . ' — ' . $periode['label'];
    $vide = $nPoints === 0 && !array_filter($sections, fn ($s) => $s['infos'] !== [] || $s['htmlPar'] !== []);

    // Le run d'abord, le HTML ensuite : le pied du rapport porte un lien
    // absolu vers sa propre page, qui exige l'identifiant.
    Db::exec('INSERT INTO ceo_rapport_run (rapport_id, genere_le, periode_du, periode_au, statut, resume, html)
              VALUES (?,?,?,?,?,?,NULL)',
        [(int) $rep['id'], date('Y-m-d H:i:s'), $periode['du'], $periode['au'],
         $vide ? 'vide' : 'genere', $resume]);
    $runId = (int) Db::pdo()->lastInsertId();
    $html = rapportHtml($rep, $sections, $periode, $seuils, $resume, $runId);
    Db::exec('UPDATE ceo_rapport_run SET html = ? WHERE id = ?', [$html, $runId]);
    journalAdd('CEO', 'Rapport', $rep['nom'], 'Généré — ' . $resume);
    return ['runId' => $runId, 'statut' => $vide ? 'vide' : 'genere', 'resume' => $resume];
}

/** Le logo officiel de la marque, incorporé (CID dans l'email). */
function rapLogoDataUri(): string
{
    static $uri = null;
    if ($uri !== null) { return $uri; }
    $chemin = __DIR__ . '/../public/assets/img/logo.png';
    $uri = is_file($chemin) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($chemin)) : '';
    return $uri;
}

/** L'adresse publique du cockpit — pour le lien « ouvrir » dans l'email. */
function rapBaseUrl(): string
{
    $conf = trim((string) setting('cockpitBase', ''));
    if ($conf !== '') { return rtrim($conf, '/'); }
    $hote = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($hote === '') { return ''; }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $chemin = (string) preg_replace('#/api/cockpit.*$#', '', (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? ''));
    return ($https ? 'https' : 'http') . '://' . $hote . $chemin;
}

/**
 * Le rapport en TEMPLATE D'EMAIL : tables imbriquées et styles inline — la
 * seule mise en page que Gmail et Outlook respectent. Bandeau de marque,
 * pastille de résumé, sections à barre de levier, bouton vers le cockpit.
 */
function rapportHtml(array $rep, array $sections, array $periode, array $seuils, string $resume, int $runId = 0): string
{
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $parMagasin = !empty($rep['par_magasin']);
    $reseau = setting('reseau', []);
    $marque = is_array($reseau) ? ($reseau['nom'] ?? 'L’Atelier By') : 'L’Atelier By';

    $rendSections = function (array $secs, ?string $magasin) use ($e, $F): string {
        $h = '';
        foreach ($secs as $s) {
            $lignes = $magasin === null ? $s['lignes'] : array_values(array_filter($s['lignes'], fn ($l) => $l[0] === $magasin));
            $infos = $magasin === null ? $s['infos'] : array_values(array_filter($s['infos'], fn ($l) => $l[0] === $magasin));
            $htmls = $magasin === null ? ($s['htmlPar'] ?? []) : array_values(array_filter($s['htmlPar'] ?? [], fn ($l) => $l[0] === $magasin));
            if ($lignes === [] && $infos === [] && $htmls === [] && $s['motif'] === null) { continue; }
            $lev = RAP_LEVIERS[$s['levier']];
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 6px"><tr>'
                . '<td width="4" style="background:' . $lev['couleur'] . ';border-radius:2px;font-size:0;line-height:0">&nbsp;</td>'
                . '<td style="padding-left:11px;' . $F . '">'
                . '<span style="font-size:9.5px;letter-spacing:1.4px;text-transform:uppercase;color:#8b8177">' . $e($lev['nom']) . '</span><br>'
                . '<span style="font-size:14.5px;font-weight:700;color:#221E1A">' . $e($s['nom']) . '</span></td></tr></table>';
            if ($s['motif'] !== null) {
                $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="' . $F . ';color:#8a5a13;background:#FBEFE0;border:1px solid #E8C9A0;border-radius:8px;padding:8px 12px;font-size:12px">Donnée indisponible : ' . $e($s['motif']) . '</td></tr></table>';
            }
            foreach ($lignes as $l) {
                $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                    . '<td width="16" valign="top" style="' . $F . ';padding:7px 0;font-size:12px;color:' . ($l[2] ? '#E0261A' : '#C17A2A') . ';font-weight:700">&#9679;</td>'
                    . '<td style="' . $F . ';padding:7px 0;font-size:13px;color:#221E1A;border-bottom:1px solid #F0EAE1;line-height:1.5">'
                    . ($magasin === null ? '<b>' . $e($l[0]) . '</b> — ' : '') . $e($l[1]) . '</td></tr></table>';
            }
            foreach ($infos as $l) {
                $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                    . '<td style="' . $F . ';padding:6px 0 6px 16px;font-size:12px;color:#6E645A;border-bottom:1px solid #F5F0E8;line-height:1.5">'
                    . ($magasin === null ? '<b>' . $e($l[0]) . '</b> — ' : '') . $e($l[1]) . '</td></tr></table>';
            }
            foreach ($htmls as $l) {
                // Construit par nos soins, jamais issu d'une saisie — pas d'échappement.
                $h .= ($magasin === null && $l[0] !== '' ? '<div style="' . $F . ';font-size:12.5px;font-weight:700;margin-top:10px;color:#221E1A">' . $e($l[0]) . '</div>' : '')
                    . $l[1];
            }
            if ($lignes !== [] && $s['action'] !== '') {
                $h .= '<div style="' . $F . ';font-size:11.5px;color:#8b8177;padding:7px 0 0;font-style:italic">&rarr; ' . $e($s['action']) . '</div>';
            }
        }
        return $h !== '' ? $h
            : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="' . $F . ';padding:14px;background:#EDF5EE;border-radius:8px;color:#2d7a3e;font-size:13px;font-weight:600">Rien à signaler — tous les seuils sont respectés.</td></tr></table>';
    };

    $corps = '';
    if ($parMagasin) {
        try { $shops = Db::rows('SELECT name FROM shops WHERE active = 1 ORDER BY name'); }
        catch (PDOException $ex) { $shops = []; }
        foreach ($shops as $s) {
            $corps .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px"><tr>'
                . '<td style="' . $F . ';background:#F7F3EC;border-left:4px solid #8D1D2C;border-radius:0 8px 8px 0;padding:10px 15px;font-size:15px;font-weight:700;color:#221E1A">' . $e($s['name']) . '</td></tr></table>'
                . $rendSections($sections, (string) $s['name']);
        }
    } else {
        $corps = $rendSections($sections, null);
    }

    $base = rapBaseUrl();
    $lien = $base !== '' && $runId > 0 ? $base . '/api/cockpit/rapports/run/' . $runId : '';
    $bouton = $lien === '' ? '' :
        '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:2px 0 14px"><tr>'
        . '<td style="background:#8D1D2C;border-radius:999px" align="center">'
        . '<a href="' . $e($lien) . '" style="display:inline-block;padding:11px 24px;color:#ffffff;' . $F . ';font-size:13px;font-weight:700;text-decoration:none">Ouvrir dans le cockpit &rarr;</a>'
        . '</td></tr></table>';

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $e($rep['nom']) . ' — ' . $e($periode['label']) . '</title>'
        . '<style>@page{size:A4;margin:12mm}'
        . '@media print{body{background:#ffffff !important;padding:0 !important}'
        . 'div[data-fiche]{page-break-inside:avoid}table[data-fiches] td{page-break-inside:avoid}'
        . '.ecran-seul{display:none !important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#EFE9DF">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EFE9DF"><tr><td align="center" style="padding:28px 12px">'
        . '<!--ecran--><div class="ecran-seul" style="' . $F . ';max-width:680px;margin:0 auto 10px;text-align:right">'
        . ($base !== '' && $runId > 0
            ? '<a href="' . $e($base . '/api/cockpit/rapports/run/' . $runId . '/pdf') . '" style="display:inline-block;border:none;border-radius:999px;padding:9px 18px;color:#ffffff;font-size:12px;font-weight:700;text-decoration:none;background:#8D1D2C;margin-right:8px">Télécharger le PDF</a>'
            : '')
        . '<a href="javascript:window.print()" style="display:inline-block;border:1px solid #CFC6B8;border-radius:999px;padding:8px 16px;color:#221E1A;font-size:12px;font-weight:600;text-decoration:none;background:#ffffff">Imprimer (A4)</a></div><!--/ecran-->'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="680" style="width:680px;max-width:96%">'
        // — bandeau de marque : le LOGO OFFICIEL sur fond clair (le noir du
        //   logo serait invisible sur bordeaux), liseré bordeaux au-dessous.
        . '<tr><td style="background:#ffffff;border-radius:14px 14px 0 0;border-bottom:3px solid #8D1D2C;padding:16px 30px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td>' . (rapLogoDataUri() !== ''
            ? '<img src="' . rapLogoDataUri() . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
            : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>') . '</td>'
        . '<td align="right" style="' . $F . ';color:#8b8177;font-size:10.5px;letter-spacing:1.2px;text-transform:uppercase">' . $e($rep['poste']) . '</td>'
        . '</tr></table></td></tr>'
        // — en-tête du rapport
        . '<tr><td style="background:#ffffff;padding:26px 30px 4px">'
        . '<div style="' . $F . ';font-size:21px;font-weight:700;color:#221E1A">' . $e($rep['nom']) . '</div>'
        . '<div style="' . $F . ';font-size:12px;color:#8b8177;margin-top:4px">' . $e(ucfirst($periode['label'])) . ' &middot; généré le ' . date('d/m/Y à H:i') . '</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px"><tr>'
        . '<td style="background:#F7ECEA;border-radius:999px;padding:7px 15px;' . $F . ';font-size:12px;font-weight:700;color:#8D1D2C">' . $e($resume) . '</td></tr></table>'
        . '</td></tr>'
        // — corps
        . '<tr><td style="background:#ffffff;padding:4px 30px 18px">' . $corps . '</td></tr>'
        // — pied
        . '<tr><td style="background:#F9F6F0;border-radius:0 0 14px 14px;border-top:1px solid #EDE7DE;padding:20px 30px">'
        . $bouton
        . '<div style="' . $F . ';font-size:10.5px;color:#8b8177;line-height:1.6">Seuils : food ' . $seuils['food'] . ' % &middot; labour ' . $seuils['labour'] . ' % &middot; overhead ' . $seuils['overhead']
        . ' % &middot; CA/ETP ' . number_format($seuils['caEtp'], 0, ',', ' ') . ' &euro; &middot; tâches &le; ' . $seuils['tacheNote'] . '/5 &middot; cible Google '
        . str_replace('.', ',', (string) $seuils['cibleGoogle']) . ' — réglables dans le cockpit (Catalogue des KPI). Sauf mode « complet », ce rapport ne liste que les seuils franchis.</div>'
        . '</td></tr>'
        . '</table>'
        . '<div style="' . $F . ';font-size:10px;color:#a89f93;padding:14px">Généré automatiquement par le cockpit ' . $e($marque) . '</div>'
        . '</td></tr></table></body></html>';
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

/**
 * Distribution « un email par magasin » : UNE définition, N envois ciblés.
 *
 *  - chaque magasin du périmètre reçoit SA version (ses seules lignes), aux
 *    adresses du carnet magasin → emails ; sans matière, pas d'envoi — la
 *    règle s'applique magasin par magasin ;
 *  - les destinataires « réseau » (le consultant) reçoivent la version
 *    complète, tous magasins en chapitres.
 *
 * Le cache de contexte (rapCtx) est partagé dans la requête : les lectures
 * API ne se font qu'une fois, les versions par magasin n'en refont aucune.
 */
function rapportDistribuer(array $rep): array
{
    $carnet = json_decode((string) ($rep['dest_par_magasin'] ?? ''), true) ?: [];
    $perim = json_decode((string) ($rep['magasins'] ?? ''), true) ?: [];
    if ($perim === []) {
        try { $perim = array_map(fn ($r2) => (string) $r2['name'], Db::rows('SELECT name FROM shops WHERE active = 1 ORDER BY name')); }
        catch (PDOException $e) { $perim = []; }
    }
    $bilan = ['reseau' => null, 'magasins' => []];

    // La version complète d'abord — elle sert aussi de run « principal ».
    $g = rapportGenerer($rep);
    $bilan['runId'] = $g['runId'];
    $bilan['resume'] = $g['resume'];
    $destsReseau = array_values(array_filter(json_decode((string) ($rep['destinataires'] ?? '[]'), true) ?: [],
        fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
    if ($destsReseau !== []) {
        $bilan['reseau'] = $g['statut'] === 'vide'
            ? ['statut' => 'vide', 'note' => 'rien à signaler — non envoyé']
            : rapportEnvoyer($rep, $g['runId']);
    }

    foreach ($perim as $mag) {
        $dests = array_values(array_filter((array) ($carnet[$mag] ?? []), fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
        if ($dests === []) {
            $bilan['magasins'][] = ['magasin' => $mag, 'statut' => 'sans-adresse',
                'note' => 'aucune adresse dans le carnet — version non générée'];
            continue;
        }
        $repMag = $rep;
        $repMag['nom'] = $rep['nom'] . ' — ' . $mag;
        $repMag['magasins'] = json_encode([$mag]);
        $repMag['destinataires'] = json_encode($dests);
        $repMag['par_magasin'] = 0;
        $gm = rapportGenerer($repMag);
        if ($gm['statut'] === 'vide') {
            $bilan['magasins'][] = ['magasin' => $mag, 'statut' => 'vide', 'runId' => $gm['runId'],
                'note' => 'rien à signaler — non envoyé'];
            continue;
        }
        $env = rapportEnvoyer($repMag, $gm['runId']);
        $bilan['magasins'][] = ['magasin' => $mag, 'statut' => $env['ok'] ? 'envoye' : 'echec',
            'runId' => $gm['runId'], 'envoyes' => $env['envoyes'] ?? [], 'note' => $env['note'] ?? null];
    }
    journalAdd('CEO', 'Rapport', (string) $rep['nom'], 'Distribution par magasin — '
        . count(array_filter($bilan['magasins'], fn ($m2) => $m2['statut'] === 'envoye')) . ' envoyé(s), '
        . count(array_filter($bilan['magasins'], fn ($m2) => $m2['statut'] === 'vide')) . ' sans matière, '
        . count(array_filter($bilan['magasins'], fn ($m2) => $m2['statut'] === 'sans-adresse')) . ' sans adresse');
    return $bilan;
}

/* --- Endpoints -------------------------------------------------------------- */

function ep_rapports(): array
{
    ensureRapports();
    $reps = Db::rows('SELECT * FROM ceo_rapport ORDER BY id');
    $runs = Db::rows("SELECT r.id, r.rapport_id, r.genere_le, r.statut, r.resume,
                             COALESCE(p.nom, 'Aperçu à la demande') nom
                        FROM ceo_rapport_run r LEFT JOIN ceo_rapport p ON p.id = r.rapport_id
                       ORDER BY r.id DESC LIMIT 12");
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
                'jours' => json_decode((string) ($r['jours'] ?? ''), true) ?: null,
                'envoiMode' => $r['envoi_mode'] ?? 'groupe',
                'destParMagasin' => json_decode((string) ($r['dest_par_magasin'] ?? ''), true) ?: [],
                'magasins' => json_decode((string) ($r['magasins'] ?? ''), true) ?: [],
                'periode' => $r['periode'] ?? null,
                'modes' => json_decode((string) ($r['modes'] ?? ''), true) ?: [],
                'dernier' => $d ? ['runId' => (int) $d['id'], 'le' => substr((string) $d['genere_le'], 0, 16),
                    'statut' => $d['statut'], 'resume' => $d['resume']] : null];
        }, $reps),
        'runs' => array_map(fn ($r) => ['runId' => (int) $r['id'], 'rapport' => $r['nom'],
            'le' => substr((string) $r['genere_le'], 0, 16), 'statut' => $r['statut'], 'resume' => $r['resume']], $runs),
        'blocs' => rapBlocDefs(),
        'cronUrl' => rapBaseUrl() !== ''
            ? rapBaseUrl() . '/api/cockpit/rapports/cron?jeton=' . (string) setting('rapportsJeton', '')
            : null,
        'leviers' => RAP_LEVIERS,
        // Les postes proposés au compositeur : ceux du réseau, puis les VRAIS
        // profils RH du panel (/positions) — saisie libre conservée à l'écran.
        // Les postes proposés : la liste /positions du panel telle quelle
        // (profils consultants ET employés) — demandé ainsi, rien d'autre.
        'postes' => rapCtx('positions', function () {
            $out = [];
            if (PanelApi::configured()) {
                foreach ((array) (PanelApi::get('/positions') ?? []) as $p) {
                    if (is_array($p) && !empty($p['name'])) { $out[] = trim((string) $p['name']); }
                }
            }
            return array_values(array_unique($out));
        }) ?? [],
        // L'annuaire des consultants du panel (/panel/consultants) : les
        // destinataires se choisissent en cliquant une personne. Sans email
        // dans le panel, la personne n'apparaît pas — on n'invente rien.
        'annuaire' => rapCtx('annuaire', function () {
            $out = []; $vus = [];
            if (PanelApi::configured()) {
                foreach ((array) (PanelApi::get('/panel/consultants') ?? []) as $c2) {
                    if (!is_array($c2) || empty($c2['is_active'])) { continue; }
                    $email = trim((string) ($c2['email'] ?? ''));
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($vus[$email])) { continue; }
                    $vus[$email] = true;
                    $out[] = ['nom' => trim(($c2['first_name'] ?? '') . ' ' . ($c2['last_name'] ?? '')),
                        'email' => $email, 'poste' => (string) ($c2['position_name'] ?? '')];
                }
            }
            usort($out, fn ($a, $b2) => strcmp($a['nom'], $b2['nom']));
            return $out;
        }) ?? [],
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
    if (isset($b['poste']) && trim((string) $b['poste']) !== '') {
        Db::exec('UPDATE ceo_rapport SET poste = ? WHERE id = ?', [trim((string) $b['poste']), $id]);
    }
    if (isset($b['nom']) && trim((string) $b['nom']) !== '') {
        Db::exec('UPDATE ceo_rapport SET nom = ? WHERE id = ?', [trim((string) $b['nom']), $id]);
    }
    if (isset($b['blocs']) && is_array($b['blocs'])) {
        $blocs = array_values(array_filter($b['blocs'], fn ($s2) => isset(rapBlocDefs()[$s2])));
        if ($blocs !== []) { Db::exec('UPDATE ceo_rapport SET blocs = ? WHERE id = ?', [json_encode($blocs), $id]); }
    }
    if (isset($b['modes']) && is_array($b['modes'])) {
        Db::exec('UPDATE ceo_rapport SET modes = ? WHERE id = ?', [json_encode($b['modes']), $id]);
    }
    if (isset($b['magasins']) && is_array($b['magasins'])) {
        Db::exec('UPDATE ceo_rapport SET magasins = ? WHERE id = ?',
            [json_encode(array_values(array_filter($b['magasins'], 'is_string'))), $id]);
    }
    if (isset($b['envoiMode']) && in_array($b['envoiMode'], ['groupe', 'par-magasin'], true)) {
        Db::exec('UPDATE ceo_rapport SET envoi_mode = ? WHERE id = ?', [$b['envoiMode'], $id]);
    }
    if (isset($b['destParMagasin']) && is_array($b['destParMagasin'])) {
        $carnet = [];
        foreach ($b['destParMagasin'] as $mag => $emails) {
            $ok2 = array_values(array_filter((array) $emails, fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
            if (is_string($mag) && $ok2 !== []) { $carnet[$mag] = $ok2; }
        }
        Db::exec('UPDATE ceo_rapport SET dest_par_magasin = ? WHERE id = ?', [json_encode($carnet, JSON_UNESCAPED_UNICODE), $id]);
    }
    if (isset($b['jours']) && is_array($b['jours'])) {
        $jrs = ['dows' => [], 'doms' => []];
        foreach ((array) ($b['jours']['dows'] ?? []) as $d2) { if (is_numeric($d2) && $d2 >= 1 && $d2 <= 7) { $jrs['dows'][] = (int) $d2; } }
        foreach ((array) ($b['jours']['doms'] ?? []) as $d2) { if (is_numeric($d2) && $d2 >= 1 && $d2 <= 31) { $jrs['doms'][] = (int) $d2; } }
        Db::exec('UPDATE ceo_rapport SET jours = ? WHERE id = ?', [json_encode($jrs), $id]);
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
    if (($rep['envoi_mode'] ?? 'groupe') === 'par-magasin') {
        return ['ok' => true, 'mode' => 'par-magasin'] + rapportDistribuer($rep);
    }
    $g = rapportGenerer($rep);
    if ($g['statut'] === 'vide') { return ['ok' => false, 'runId' => $g['runId'], 'error' => 'rapport sans matière — non envoyé']; }
    return ['runId' => $g['runId']] + rapportEnvoyer($rep, $g['runId']);
}

/** DELETE /rapports/{id} — la définition part, l'historique des runs reste. */
function wr_rapport_suppr(int $id): array
{
    ensureRapports();
    $rep = Db::row('SELECT nom FROM ceo_rapport WHERE id = ?', [$id]);
    if ($rep === null) { http_response_code(404); return ['error' => 'rapport inconnu']; }
    Db::exec('DELETE FROM ceo_rapport WHERE id = ?', [$id]);
    journalAdd('CEO', 'Rapport', (string) $rep['nom'], 'Rapport supprimé — ses générations passées restent lisibles');
    return ['ok' => true];
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
        $jrs = json_decode((string) ($rep['jours'] ?? ''), true);
        if (is_array($jrs)) {
            // Planification par jours cochés (compositeur) : jours de semaine
            // et/ou jours du mois. Aucun jour coché = à la demande, jamais dû.
            $du = (int) $rep['heure'] === $h
                && (in_array($dow, (array) ($jrs['dows'] ?? []), true)
                 || in_array($dom, (array) ($jrs['doms'] ?? []), true));
        } else {
            $du = ((string) $rep['frequence'] === 'quotidien' && (int) $rep['heure'] === $h)
                || ((string) $rep['frequence'] === 'hebdo' && (int) $rep['jour'] === $dow && (int) $rep['heure'] === $h)
                || ((string) $rep['frequence'] === 'mensuel' && (int) $rep['jour'] === $dom && (int) $rep['heure'] === $h);
        }
        if (!$du) { continue; }
        $deja = Db::row('SELECT id FROM ceo_rapport_run WHERE rapport_id = ? AND genere_le >= ? AND statut <> ?',
            [(int) $rep['id'], date('Y-m-d 00:00:00'), 'erreur']);
        if ($deja !== null) { continue; }
        if (($rep['envoi_mode'] ?? 'groupe') === 'par-magasin') {
            $bilan = rapportDistribuer($rep);
            $faits[] = ['rapport' => $rep['nom'], 'runId' => $bilan['runId'], 'statut' => 'distribue',
                'envoi' => count(array_filter($bilan['magasins'], fn ($m2) => $m2['statut'] === 'envoye')) . ' magasin(s) servi(s)'];
            continue;
        }
        $g = rapportGenerer($rep);
        $env = null;
        if ($g['statut'] !== 'vide') { $env = rapportEnvoyer($rep, $g['runId']); }
        $faits[] = ['rapport' => $rep['nom'], 'runId' => $g['runId'], 'statut' => $g['statut'],
            'envoi' => $env ? ($env['ok'] ? 'envoyé' : ($env['error'] ?? 'échec')) : 'non dû'];
    }
    return ['ok' => true, 'heure' => $h, 'faits' => $faits];
}

/**
 * GET /rapports/run/{id}/pdf — le rapport en vrai fichier PDF.
 *
 * Le rendu s'appuie sur un moteur présent sur le serveur (Chromium headless ou
 * wkhtmltopdf). S'il n'y en a aucun — ou si exec() est désactivé — la route
 * répond 501 en expliquant, et la page garde le bouton « Imprimer (A4) » qui
 * produit le même PDF depuis le navigateur.
 */
function ep_rapport_run_pdf(int $id): array
{
    ensureRapports();
    $run = Db::row('SELECT html FROM ceo_rapport_run WHERE id = ?', [$id]);
    if ($run === null || $run['html'] === null) { http_response_code(404); return ['error' => 'run inconnu']; }
    if (!function_exists('shell_exec')) {
        http_response_code(501);
        return ['error' => 'exec désactivé sur ce serveur — utilisez « Imprimer (A4) », le navigateur produit le même PDF'];
    }
    $tmpH = tempnam(sys_get_temp_dir(), 'rap') . '.html';
    $tmpP = tempnam(sys_get_temp_dir(), 'rap') . '.pdf';
    file_put_contents($tmpH, (string) $run['html']);
    // CHAQUE essai est borné par `timeout` : un moteur qui attend (xvfb sans
    // écran, chromium qui bloque) retiendrait sinon un worker Apache sans fin
    // — quelques requêtes suffisent alors à coucher toute l'API. Mesuré.
    $essais = [
        // Le build Ubuntu de wkhtmltopdf n'est pas headless : xvfb-run d'abord.
        'timeout 25 xvfb-run -a wkhtmltopdf --quiet --page-size A4 --enable-local-file-access %1$s %2$s 2>&1',
        'timeout 25 wkhtmltopdf --quiet --page-size A4 --enable-local-file-access %1$s %2$s 2>&1',
        'timeout 25 chromium --headless=new --disable-gpu --no-sandbox --print-to-pdf=%2$s %1$s 2>&1',
        'timeout 25 chromium-browser --headless --disable-gpu --no-sandbox --print-to-pdf=%2$s %1$s 2>&1',
        'timeout 25 google-chrome --headless=new --disable-gpu --no-sandbox --print-to-pdf=%2$s %1$s 2>&1',
    ];
    foreach ($essais as $cmd) {
        @shell_exec(sprintf($cmd, escapeshellarg($tmpH), escapeshellarg($tmpP)));
        if (is_file($tmpP) && filesize($tmpP) > 1000) {
            @unlink($tmpH);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="rapport-' . $id . '.pdf"');
            readfile($tmpP);
            @unlink($tmpP);
            exit;
        }
    }
    @unlink($tmpH); @unlink($tmpP);
    http_response_code(501);
    return ['error' => 'aucun moteur PDF sur le serveur (Chromium/wkhtmltopdf absents) — utilisez « Imprimer (A4) », le navigateur produit le même PDF'];
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

/* --------------------------------------------------------------------------
 * Mode « tableau complet » — les tableaux tels qu'à l'écran, en HTML d'email.
 * Rend null quand le bloc n'a pas de version complète : le générateur garde
 * alors les dépassements et le dit.
 * ------------------------------------------------------------------------ */

/** Un tableau d'email générique : colonnes, lignes de cellules [texte, style]. */
function rapTableHtml(array $cols, array $rows): string
{
    $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $th = 'text-align:right;font-size:9.5px;letter-spacing:0.05em;text-transform:uppercase;color:#8b8177;padding:6px 8px;border-bottom:1px solid #D8CEC0;font-family:sans-serif';
    $h = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:6px 0;font-family:sans-serif;font-size:12px"><tr>';
    foreach ($cols as $i => $c2) { $h .= '<th style="' . $th . ($i === 0 ? ';text-align:left' : '') . '">' . $e($c2) . '</th>'; }
    $h .= '</tr>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($r as $i => $cell) {
            [$txt, $st] = is_array($cell) ? $cell : [$cell, ''];
            $h .= '<td style="padding:6px 8px;border-bottom:1px solid #EDE7DE;text-align:' . ($i === 0 ? 'left' : 'right')
                . ';white-space:nowrap;' . $st . '">' . $e($txt) . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</table>';
}

/** La couleur du chiffre selon l'écart à une référence — paliers de l'écran. */
function rapCouleurEcart(?float $v, ?float $ref): string
{
    if ($v === null || $ref === null || $ref <= 0) { return 'color:#8b8177'; }
    $eC = $v / $ref - 1;
    $c = $eC >= 0.20 ? '#C9A227' : ($eC >= 0.10 ? '#2d7a3e' : ($eC >= 0.05 ? '#7CB342'
        : ($eC <= -0.20 ? '#E0261A' : ($eC <= -0.10 ? '#8D1D2C' : ($eC <= -0.05 ? '#C17A2A' : '')))));
    return $c !== '' ? 'color:' . $c . ';font-weight:700' : '';
}

function rapBlocComplet(string $slug, array $seuils, array $periode, array $filtre): ?array
{
    $fmtE = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ') . ' €';
    $fmtP = fn ($v) => $v === null ? '—' : str_replace('.', ',', (string) round((float) $v, 1)) . ' %';
    $garde = fn (string $nom) => $filtre === [] || in_array($nom, $filtre, true);
    $stSeuil = function (?float $v, float $alerte, ?float $critique, string $sens): string {
        if ($v === null) { return 'color:#8b8177'; }
        $mauvais = $sens === 'haut' ? $v >= $alerte : $v <= $alerte;
        $grave = $critique !== null && ($sens === 'haut' ? $v >= $critique : $v <= $critique);
        return $grave ? 'color:#E0261A;font-weight:700' : ($mauvais ? 'color:#8D1D2C;font-weight:700' : 'color:#2d7a3e');
    };

    switch ($slug) {
        case 'trafic-nvn1': {
            $r = rapReseau($periode['nvn1'] ?? 'semaine');
            if (!is_array($r) || ($r['etat'] ?? '') !== 'ok') { return null; }
            $rows = [];
            foreach ((array) $r['magasins'] as $m) {
                if (!$garde($m['magasin'])) { continue; }
                $rows[] = [$m['magasin'], $fmtE($m['n']), [$fmtE($m['n1']), 'color:#8b8177'],
                    [$m['ecart'] === null ? '—' : ($m['ecart'] > 0 ? '+' : '') . $fmtP($m['ecart']),
                     $m['ecart'] === null ? 'color:#8b8177' : $stSeuil((float) $m['ecart'], $seuils['ecartNvn1'], $seuils['ecartNvn1'] * 3, 'bas')]];
            }
            return [['', rapTableHtml(['Magasin', 'CA N', 'CA N-1', 'Écart'], $rows)]];
        }
        case 'trafic-clients':
        case 'recurrence-panier': {
            $k = rapKpis();
            if (!is_array($k) || !empty($k['indispo'])) { return null; }
            $mMax = (int) ($k['moisMax'] ?? 0);
            $MOIS = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
            $mesures = $slug === 'trafic-clients'
                ? [['clientsJour', 'Clients par jour', 1, '']]
                : [['panier', 'Ticket moyen', 2, ' €'], ['items', 'Articles par ticket', 2, '']];
            $out = [];
            foreach ($mesures as [$cle, $titre, $dec, $suf]) {
                $cols = ['Magasin'];
                for ($m = 1; $m <= $mMax; $m++) { $cols[] = $MOIS[$m]; }
                $rows = [];
                foreach ((array) $k['magasins'] as $mg) {
                    if (!$garde($mg['nom'])) { continue; }
                    $row = [$mg['nom']];
                    for ($m = 1; $m <= $mMax; $m++) {
                        $v = ($mg['mois'][$m] ?? [])[$cle] ?? null;
                        $ref = (($k['reseau'] ?? [])[$m] ?? [])[$cle] ?? null;
                        if ($cle === 'clientsJour' && $ref !== null) {
                            $actifs = max(1, count(array_filter((array) $k['magasins'],
                                fn ($x) => (($x['mois'][$m] ?? [])[$cle] ?? 0) > 0)));
                            $ref = $ref / $actifs;
                        }
                        $row[] = [$v === null ? '—' : number_format((float) $v, $dec, ',', ' ') . $suf, rapCouleurEcart($v, $ref)];
                    }
                    $rows[] = $row;
                }
                $resRow = ['Réseau'];
                for ($m = 1; $m <= $mMax; $m++) {
                    $v = (($k['reseau'] ?? [])[$m] ?? [])[$cle] ?? null;
                    $resRow[] = [$v === null ? '—' : number_format((float) $v, $dec, ',', ' ') . $suf, 'font-weight:700'];
                }
                $rows[] = $resRow;
                $out[] = ['', '<div style="font-size:12px;font-weight:700;font-family:sans-serif;margin-top:6px">' . htmlspecialchars($titre) . ' — mois par mois</div>'
                    . rapTableHtml($cols, $rows)];
            }
            return $out;
        }
        case 'food-cost': {
            try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
            catch (PDOException $e2) { return null; }
            $rows = [];
            foreach ($shops as $s) {
                if (!$garde((string) $s['name'])) { continue; }
                $hm = rapCtx('hm-mois-' . $s['id'], fn () => PanelApi::marginHeatmapEntre((int) $s['id'], date('Y-m-01'), date('Y-m-d')));
                $t = is_array($hm) ? ($hm['totals'] ?? []) : [];
                $ca = (float) ($t['ca'] ?? 0);
                $fc = $ca > 0 ? ($ca - (float) ($t['margin_value'] ?? 0)) / $ca * 100 : null;
                $rows[] = [$s['name'], [$fmtP($fc), $stSeuil($fc, $seuils['food'], kpiDefs()['food-cost-pct']['seuil_critique'] ?? null, 'haut')],
                    [$fmtE($ca), 'color:#8b8177']];
            }
            return [['', rapTableHtml(['Magasin', 'Food cost (mois en cours)', 'CA du mois'], $rows)]];
        }
        case 'labour-caetp': {
            $r = rapReseau('mois'); $etp = rapEtp();
            if (!is_array($r) || ($r['etat'] ?? '') !== 'ok') { return null; }
            $eParShop = [];
            foreach ((array) $etp as $e2) {
                if ((int) $e2['annee'] === (int) date('Y') && (int) $e2['mois'] === (int) date('n')) {
                    $eParShop[(string) $e2['storeId']] = $e2;
                }
            }
            $rows = [];
            foreach ((array) $r['magasins'] as $m) {
                if (!$garde($m['magasin'])) { continue; }
                $e2 = $eParShop[(string) $m['shopId']] ?? null;
                $ce = ($e2 && (float) $e2['etp'] > 0 && $m['n'] !== null) ? $m['n'] / (float) $e2['etp'] : null;
                $rows[] = [$m['magasin'],
                    [$fmtE($ce), $stSeuil($ce, $seuils['caEtp'], kpiDefs()['ca-etp']['seuil_critique'] ?? null, 'bas')],
                    [$e2 ? str_replace('.', ',', (string) $e2['etp']) . ' ETP' : 'planning inconnu', 'color:#8b8177'],
                    [$e2 ? str_replace('.', ',', (string) $e2['heures']) . ' h' : '—', 'color:#8b8177']];
            }
            return [['', rapTableHtml(['Magasin', 'CA / ETP (mois)', 'ETP planifiés', 'Heures'], $rows)]];
        }
        case 'overhead-jours': {
            $r = rapRentab();
            if (!is_array($r) || !empty($r['indispo'])) { return null; }
            $rows = [];
            foreach ((array) $r['magasins'] as $m) {
                if (!empty($m['indispo']) || !$garde((string) $m['nom'])) { continue; }
                $rouges = array_values(array_filter((array) $m['jours'], fn ($j) => $j['net'] !== null && $j['net'] < 0));
                $pire = null;
                foreach ($rouges as $j) { if ($pire === null || $j['netPct'] < $pire['netPct']) { $pire = $j; } }
                $tot = $m['total'] ?? [];
                $rows[] = [$m['nom'],
                    [count($rouges) . ' / 7', $stSeuil((float) count($rouges), $seuils['joursRouges'], 5, 'haut')],
                    [$pire ? $fmtP($pire['netPct']) . ' (' . date('D d/m', strtotime($pire['date'])) . ')' : '—', 'color:#8b8177'],
                    [isset($tot['netPct']) ? $fmtP($tot['netPct']) : '—', ($tot['netPct'] ?? 0) < 0 ? 'color:#8D1D2C;font-weight:700' : 'color:#2d7a3e']];
            }
            return [['', rapTableHtml(['Magasin', 'Jours négatifs', 'Pire jour', 'Résultat net semaine'], $rows)]];
        }
        case 'recurrence-avis': {
            $r = rapReput();
            if (!is_array($r) || !empty($r['indispo'])) { return null; }
            $cible = (float) ($r['cible'] ?? $seuils['cibleGoogle']);
            $vCible = str_replace('.', ',', (string) $cible);
            // « Pour atteindre la cible » ne doit jamais rester muet : un
            // magasin à la cible le dit, un magasin sans fiche aussi.
            $effort = static function (?int $requis, $note) use ($cible): array {
                if ($note === null) { return ['fiche non raccordée', 'color:#8b8177']; }
                if ($requis === null) { return ['hors d’atteinte par ajout d’avis', 'color:#8b8177']; }
                if ($requis === 0) { return ['cible atteinte', 'color:#2d7a3e;font-weight:700']; }
                return [$requis . ' avis 5★ à obtenir', 'color:#8D1D2C;font-weight:700'];
            };
            $rows = [];
            foreach ((array) $r['magasins'] as $m) {
                if (!$garde((string) $m['nom'])) { continue; }
                $rows[] = [$m['nom'],
                    [$m['note'] === null ? '—' : str_replace('.', ',', (string) $m['note']) . ' ★',
                     $stSeuil($m['note'] !== null ? (float) $m['note'] : null, $seuils['cibleGoogle'], kpiDefs()['note-google']['seuil_critique'] ?? null, 'bas')],
                    [(string) $m['avis'] . ' avis', 'color:#8b8177'],
                    $effort($m['avis5Requis'] === null ? null : (int) $m['avis5Requis'], $m['note'])];
            }
            $res = (array) ($r['reseau'] ?? []);
            if ($rows !== [] && ($res['moyenne'] ?? null) !== null) {
                $rows[] = [['Réseau', 'font-weight:700'],
                    [str_replace('.', ',', (string) $res['moyenne']) . ' ★', 'font-weight:700'],
                    [(int) ($res['avis'] ?? 0) . ' avis', 'color:#8b8177;font-weight:700'],
                    $effort($res['avis5Requis'] === null ? null : (int) $res['avis5Requis'], $res['moyenne'])];
            }
            return [['', rapTableHtml(['Magasin', 'Note Google', 'Volume', 'Pour atteindre ' . $vCible], $rows)
                . '<div style="font-size:10.5px;color:#8b8177;font-family:sans-serif;margin:2px 0 8px">'
                . 'Avis 5★ à obtenir = avis × (cible − note) ÷ (5 − cible), arrondi au supérieur — '
                . 'le nombre d’avis parfaits qui ramène la moyenne à ' . $vCible . '.</div>']];
        }
        case 'xp-taches': {
            $ts = rapTaches($periode['du'], $periode['au']);
            if ($ts === []) { return null; }
            $par = [];
            foreach ($ts as $t) {
                $mag = (string) $t['magasin'];
                if (!$garde($mag)) { continue; }
                $par[$mag] ??= ['n' => 0, 'notees' => 0, 'somme' => 0.0, 'sous' => 0];
                $par[$mag]['n']++;
                if ($t['note'] !== null) {
                    $par[$mag]['notees']++; $par[$mag]['somme'] += (float) $t['note'];
                    if ((int) $t['note'] <= $seuils['tacheNote']) { $par[$mag]['sous']++; }
                }
            }
            $rows = [];
            foreach ($par as $mag => $x) {
                $moy = $x['notees'] > 0 ? $x['somme'] / $x['notees'] : null;
                $rows[] = [$mag, [(string) $x['n'], 'color:#8b8177'], [(string) $x['notees'], 'color:#8b8177'],
                    [$moy === null ? '—' : str_replace('.', ',', (string) round($moy, 1)) . ' / 5',
                     $stSeuil($moy, (float) $seuils['tacheNote'], 2.0, 'bas')],
                    [(string) $x['sous'], $x['sous'] > 0 ? 'color:#8D1D2C;font-weight:700' : 'color:#2d7a3e']];
            }
            return [['', rapTableHtml(['Magasin', 'Tâches', 'Notées', 'Note moyenne', '≤ ' . $seuils['tacheNote'] . '/5'], $rows)]];
        }
    }
    return null;
}

/* --------------------------------------------------------------------------
 * Photos des non-conformités : la photo de boutique avec les REPÈRES dessinés
 * dessus (GD, au moment de la génération — les URL signées du panel expirent),
 * et la photo de référence du produit lié. Incorporées en data URI dans le
 * HTML ; l'envoi SMTP les convertit en pièces jointes intégrées (CID), la
 * seule forme que Gmail affiche.
 * ------------------------------------------------------------------------ */

/** Télécharge et redimensionne une image ; rend un GD ou null, sans bruit. */
function rapImageGd(string $url, int $maxW = 520)
{
    if ($url === '' || !function_exists('imagecreatefromstring')) { return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 18,
        CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    $brut = curl_exec($ch);
    curl_close($ch);
    if (!is_string($brut) || $brut === '') { return null; }
    $im = @imagecreatefromstring($brut);
    if ($im === false) { return null; }
    $w = imagesx($im); $h = imagesy($im);
    if ($w > $maxW) {
        $nh = (int) round($h * $maxW / $w);
        $petit = imagecreatetruecolor($maxW, $nh);
        imagecopyresampled($petit, $im, 0, 0, 0, 0, $maxW, $nh, $w, $h);
        imagedestroy($im);
        $im = $petit;
    }
    return $im;
}

/** Dessine les repères (cadres numérotés, couleur de gravité) sur l'image. */
function rapDessineReperes($im, array $reperes): void
{
    $W = imagesx($im); $H = imagesy($im);
    $n = 0;
    foreach ($reperes as $r) {
        $n++;
        $x = (int) round((float) ($r['x'] ?? 0) * $W);
        $y = (int) round((float) ($r['y'] ?? 0) * $H);
        $l = (int) round((float) ($r['l'] ?? 0) * $W);
        $h = (int) round((float) ($r['h'] ?? 0) * $H);
        $niv = (int) ($r['niveau'] ?? 3);
        [$cr, $cg, $cb] = $niv <= 2 ? [224, 38, 26] : ($niv === 3 ? [193, 122, 42] : [45, 122, 62]);
        $coul = imagecolorallocate($im, $cr, $cg, $cb);
        for ($e = 0; $e < 3; $e++) { imagerectangle($im, $x - $e, $y - $e, $x + $l + $e, $y + $h + $e, $coul); }
        // La pastille numérotée au coin du cadre — lisible sur toute photo.
        imagefilledellipse($im, max(11, $x), max(11, $y), 24, 24, $coul);
        $blanc = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 5, max(11, $x) - (strlen((string) $n) * 4), max(11, $y) - 7, (string) $n, $blanc);
    }
}

/** L'image en data URI JPEG — le HTML du run l'affiche, le mailer l'attache. */
function rapImageDataUri($im): string
{
    ob_start();
    imagejpeg($im, null, 74);
    $jpg = (string) ob_get_clean();
    imagedestroy($im);
    return 'data:image/jpeg;base64,' . base64_encode($jpg);
}

/**
 * La CARTE d'une tâche non conforme : photo annotée, référence, explications.
 * Contenu seul — l'appelant les range deux par rangée (lecture A4).
 */
function rapFicheTache(string $shopId, string $taskId, string $date, string $nomTache, string $magasin, ?int $note = null, string $commentaire = ''): string
{
    $det = rapCtx('tache-' . $shopId . '-' . $taskId . '-' . $date,
        fn () => rapAppel('ep_pwa_task_detail', ['shop' => $shopId, 'task' => $taskId, 'date' => $date]));
    if (!is_array($det)) { return ''; }
    $e = fn ($s2) => htmlspecialchars((string) $s2, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $reperes = (array) ((($det['reperes'] ?? [])['liste']) ?? []);

    $imgs = '';
    $photo = rapImageGd((string) ($det['photo'] ?? ''), 420);
    if ($photo !== null) {
        if ($reperes !== []) { rapDessineReperes($photo, $reperes); }
        $imgs .= '<img src="' . rapImageDataUri($photo) . '" width="100%" style="display:block;width:100%;border-radius:7px" alt="Photo en boutique">'
            . '<div style="' . $F . ';font-size:9.5px;color:#8b8177;margin:2px 0 6px">Photo en boutique' . ($reperes !== [] ? ' — ' . count($reperes) . ' repère(s)' : '') . '</div>';
    }
    $ref = rapImageGd((string) ($det['photoRef'] ?? ''), 420);
    if ($ref !== null) {
        $imgs .= '<img src="' . rapImageDataUri($ref) . '" width="100%" style="display:block;width:100%;border-radius:7px" alt="Référence attendue">'
            . '<div style="' . $F . ';font-size:9.5px;color:#8b8177;margin:2px 0 6px">Référence attendue' . (!empty($det['produit']) ? ' — ' . $e($det['produit']) : '') . '</div>';
    }
    if ($imgs === '' && $reperes === []) { return ''; }

    $exp = '';
    if ($note !== null) {
        $exp .= '<div style="' . $F . ';font-size:11px;color:#221E1A;padding:1px 0"><b style="color:' . ($note <= 2 ? '#E0261A' : '#C17A2A') . '">Note ' . $note . '/5</b> · le ' . $e(date('d/m', strtotime($date ?: 'today'))) . '</div>';
    }
    if (trim($commentaire) !== '') {
        $exp .= '<div style="' . $F . ';font-size:11px;color:#4a443c;padding:2px 0;line-height:1.45">' . $e(mb_substr($commentaire, 0, 260)) . '</div>';
    }
    $n = 0;
    foreach ($reperes as $r) {
        $n++;
        if (trim((string) ($r['txt'] ?? '')) === '') { continue; }
        $niv = (int) ($r['niveau'] ?? 3);
        $coulR = $niv <= 2 ? '#E0261A' : ($niv === 3 ? '#C17A2A' : '#2d7a3e');
        $exp .= '<div style="' . $F . ';font-size:11px;color:#221E1A;padding:1px 0"><span style="color:' . $coulR . ';font-weight:700">' . $n . '.</span> ' . $e($r['txt']) . '</div>';
    }
    return '<div style="' . $F . ';font-size:12px;font-weight:700;color:#221E1A;margin-bottom:5px">' . $e($nomTache) . '</div>' . $imgs . $exp;
}

/** Range les cartes deux par rangée — la grille qui tient sur un A4. */
function rapFichesGrille(array $cartes): string
{
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" data-fiches="1">';
    foreach (array_chunk($cartes, 2) as $paire) {
        $h .= '<tr>';
        $h .= '<td valign="top" width="50%" style="padding:5px 6px 5px 0"><div data-fiche="1" style="background:#FBF9F5;border-radius:10px;padding:10px 12px">' . $paire[0] . '</div></td>';
        $h .= '<td valign="top" width="50%" style="padding:5px 0 5px 6px">'
            . (isset($paire[1]) ? '<div data-fiche="1" style="background:#FBF9F5;border-radius:10px;padding:10px 12px">' . $paire[1] . '</div>' : '&nbsp;')
            . '</td>';
        $h .= '</tr>';
    }
    return $h . '</table>';
}

/* --- Compositeur : aperçu à la demande et enregistrement d'un modèle --------- */

/** Le pseudo-rapport d'une composition envoyée par l'écran. */
function rapCompositionRep(array $b): array
{
    $blocs = array_values(array_filter((array) ($b['blocs'] ?? []), fn ($s) => isset(rapBlocDefs()[$s])));
    return [
        'id' => 0, 'code' => 'apercu', 'nom' => trim((string) ($b['nom'] ?? '')) ?: 'Rapport à la demande',
        'poste' => trim((string) ($b['poste'] ?? '')) ?: 'À la demande',
        'frequence' => 'hebdo', 'par_magasin' => 0,
        'blocs' => json_encode($blocs),
        'modes' => json_encode(is_array($b['modes'] ?? null) ? $b['modes'] : []),
        'magasins' => json_encode(array_values(array_filter((array) ($b['magasins'] ?? []), 'is_string'))),
        // Sans période explicite, la fenêtre de données SUIT LA CADENCE du
        // rapport (quotidien → la veille, hebdo → semaine passée, mensuel →
        // mois passé) — c'est rapPeriode() qui tranche sur la fréquence.
        'periode' => in_array($b['periode'] ?? '', ['hier', 'semaine-passee', 'mois-en-cours', 'mois-passe', 'libre'], true) ? $b['periode'] : null,
        'periode_du' => (string) ($b['du'] ?? '') ?: null,
        'periode_au' => (string) ($b['au'] ?? '') ?: null,
        'destinataires' => json_encode(array_values(array_filter((array) ($b['destinataires'] ?? []), fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)))),
    ];
}

/** POST /rapports/apercu — générer une composition, sans rien enregistrer. */
function wr_rapport_apercu(): array
{
    ensureRapports();
    $b = body();
    $rep = rapCompositionRep($b);
    if (json_decode((string) $rep['blocs'], true) === []) {
        http_response_code(422);
        return ['error' => 'aucun KPI coché'];
    }
    $g = rapportGenerer($rep);
    if (!empty($b['envoyer'])) {
        if ($g['statut'] === 'vide') { return ['ok' => false, 'runId' => $g['runId'], 'error' => 'rapport sans matière — non envoyé']; }
        return ['runId' => $g['runId']] + rapportEnvoyer($rep, $g['runId']);
    }
    return ['ok' => true] + $g;
}

/** POST /rapports — enregistrer une composition comme rapport récurrent. */
function wr_rapport_creer(): array
{
    ensureRapports();
    $b = body();
    $rep = rapCompositionRep($b);
    if (json_decode((string) $rep['blocs'], true) === []) { http_response_code(422); return ['error' => 'aucun KPI coché']; }
    if ($rep['nom'] === 'Rapport à la demande') { http_response_code(422); return ['error' => 'donnez un nom au rapport']; }
    $jours = ['dows' => [], 'doms' => []];
    foreach ((array) (($b['jours'] ?? [])['dows'] ?? []) as $d2) { if (is_numeric($d2) && $d2 >= 1 && $d2 <= 7) { $jours['dows'][] = (int) $d2; } }
    foreach ((array) (($b['jours'] ?? [])['doms'] ?? []) as $d2) { if (is_numeric($d2) && $d2 >= 1 && $d2 <= 31) { $jours['doms'][] = (int) $d2; } }
    $heure = is_numeric($b['heure'] ?? null) ? max(0, min(23, (int) $b['heure'])) : 7;
    // La fréquence héritée sert à l'affichage et aux périodes par défaut — la
    // planification réelle est dans `jours` (aucun jour coché = à la demande).
    $freq = $jours['doms'] !== [] && $jours['dows'] === [] ? 'mensuel'
        : (count($jours['dows']) >= 7 ? 'quotidien' : 'hebdo');
    $code = 'perso-' . trim(mb_substr(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($rep['nom'])), 0, 30), '-');
    if (Db::row('SELECT id FROM ceo_rapport WHERE code = ?', [$code]) !== null) { $code .= '-' . random_int(10, 99); }
    $envoiMode = ($b['envoiMode'] ?? '') === 'par-magasin' ? 'par-magasin' : 'groupe';
    $carnet = [];
    foreach ((array) ($b['destParMagasin'] ?? []) as $mag => $emails) {
        $ok2 = array_values(array_filter((array) $emails, fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL)));
        if (is_string($mag) && $ok2 !== []) { $carnet[$mag] = $ok2; }
    }
    Db::exec('INSERT INTO ceo_rapport (code, nom, poste, frequence, heure, jour, blocs, destinataires, par_magasin, actif,
                                       jours, magasins, periode, periode_du, periode_au, modes, envoi_mode, dest_par_magasin)
              VALUES (?,?,?,?,?,?,?,?,0,1,?,?,?,?,?,?,?,?)',
        [$code, $rep['nom'], $rep['poste'], $freq, $heure,
         $jours['dows'][0] ?? ($jours['doms'][0] ?? 1),
         $rep['blocs'], $rep['destinataires'],
         json_encode($jours), $rep['magasins'], $rep['periode'], $rep['periode_du'], $rep['periode_au'], $rep['modes'],
         $envoiMode, json_encode($carnet, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Rapport', $rep['nom'], 'Rapport composé enregistré (' . $code . ')');
    return ['ok' => true, 'code' => $code,
        'planifie' => $jours['dows'] !== [] || $jours['doms'] !== [],
        'note' => $jours['dows'] === [] && $jours['doms'] === [] ? 'aucun jour coché — rapport à la demande, le cron ne l\'enverra pas' : null];
}
