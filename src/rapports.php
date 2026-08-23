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

/* Le poids d'images qu'un rapport peut porter, en caractères de HTML (les
   photos y sont en data URI, donc en base64 : ~1,33 caractère par octet).
   Six millions ≈ 4,5 Mo d'images — un email lourd mais qui passe partout, et
   MEDIUMTEXT en base tient seize fois cela. */
const RAP_POIDS_FICHES = 6000000;

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
              'ADD COLUMN dest_par_magasin TEXT NULL',
              "ADD COLUMN comparaison VARCHAR(4) NOT NULL DEFAULT 'A-1'",
              // L'ordre des blocs et les sauts de page voulus : {ordre:[slug],
              // sauts:[slug]}. Vide = l'ordre par levier, celui d'avant.
              'ADD COLUMN mise_en_page TEXT NULL'] as $alter) {
        try { Db::exec('ALTER TABLE ceo_rapport ' . $alter); } catch (PDOException $e) { /* déjà là */ }
    }
    // Le contexte d'un run : magasin et nom du rapport, pour le pied de page
    // du PDF. Il vit sur le RUN parce qu'un aperçu (composition non
    // enregistrée) n'a pas de ligne dans ceo_rapport à relire, et parce que
    // le périmètre d'un envoi par magasin n'est pas celui de la définition.
    try { Db::exec('ALTER TABLE ceo_rapport_run ADD COLUMN contexte TEXT NULL'); }
    catch (PDOException $e) { /* déjà là */ }

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
        // Sous ce taux d'exécution, un magasin apparaît dans le classement des
        // tâches. Réglable comme les autres, jamais en dur.
        'tauxTaches' => kpiSeuil('taux-taches', 80.0),
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
        'trafic-ca' => ['levier' => 'trafic', 'nom' => 'Chiffre d’affaires — période, cumul du mois, objectif'],
        'trafic-clients' => ['levier' => 'trafic', 'nom' => 'Clients par jour vs moyenne réseau'],
        'trafic-passage' => ['levier' => 'trafic', 'nom' => 'Passage clients par jour — magasin, réseau, comparaison'],
        'trafic-nvn1' => ['levier' => 'trafic', 'nom' => 'CA N vs N-1'],
        'recurrence-panier' => ['levier' => 'recurrence', 'nom' => 'Ticket moyen et articles par ticket'],
        'recurrence-avis' => ['levier' => 'recurrence', 'nom' => 'Réputation Google'],
        'xp-ticket' => ['levier' => 'xp', 'nom' => 'Ticket moyen — magasin, réseau, comparaison'],
        // `famille = taches` : ces deux blocs parlent des mêmes tâches et se
        // lisent l'un après l'autre. Un KPI chiffré coché entre les deux les
        // séparait au milieu du rapport ; ils passent toujours en dernier de
        // leur levier, quel que soit l'ordre des cases cochées.
        'xp-bilan' => ['levier' => 'xp', 'nom' => 'Bilan des tâches de la période', 'famille' => 'taches'],
        'xp-taches' => ['levier' => 'xp', 'nom' => 'Tâches sous le seuil', 'famille' => 'taches'],
        'xp-classement' => ['levier' => 'xp', 'nom' => 'Classement des tâches — réseau', 'famille' => 'taches'],
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
        case 'trafic-ca': {
            // Le chiffre d'affaires sur trois horizons : la période et son
            // A-1, le cumul du mois et son A-1, puis l'objectif du mois et ce
            // qu'il reste à faire. Trois questions qu'on pose toujours l'une
            // après l'autre — « combien cette semaine », « où en est le mois »,
            // « est-ce que j'y serai ».
            $b['action'] = 'Animation commerciale, panier, trafic — les trois leviers du chiffre.';
            $fen = rapFenetreComparaison($periode, (string) ($periode['comparaison'] ?? 'A-1'));
            $now = rapVentesFenetre((string) $periode['du'], (string) $periode['au']);
            if ($now['magasins'] === []) { $b['motif'] = 'aucune vente lue sur la période (API panel)'; break; }
            $avant = rapVentesFenetre($fen['du'], $fen['au']);
            $perim = (array) ($periode['magasins'] ?? []);
            $b['htmlPar'][] = ['', rapTableComparaison($now, $avant, $fen, 'ca', 'Chiffre d’affaires de la période',
                fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', ' ') . ' €', $perim)];

            // Le cumul : du premier du mois au dernier jour de la période — pas
            // au jour même, sinon un rapport relu la semaine suivante changerait
            // de chiffre.
            $mois1 = substr((string) $periode['au'], 0, 8) . '01';
            $cumul = rapVentesFenetre($mois1, (string) $periode['au']);
            $cumulA = rapVentesFenetre(
                (new DateTimeImmutable($mois1))->modify('-1 year')->format('Y-m-d'),
                (new DateTimeImmutable((string) $periode['au']))->modify('-1 year')->format('Y-m-d'));
            $objectifs = rapObjectifsMois((string) $periode['au']);
            $e2 = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
            $euro = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', ' ') . ' €';
            $rows = []; $ttCum = 0.0; $ttA = 0.0; $ttObj = 0.0; $objConnu = false;
            foreach ($cumul['magasins'] as $mag => $v) {
                if ($perim !== [] && !in_array($mag, $perim, true)) { continue; }
                $c = (float) ($v['ca'] ?? 0);
                $a = (float) ((($cumulA['magasins'][$mag] ?? [])['ca']) ?? 0);
                $objSrc = $objectifs[$mag] ?? null;
                $obj = $objSrc !== null ? (float) $objSrc['montant'] : null;
                $reste = $obj !== null ? $obj - $c : null;
                $ttCum += $c; $ttA += $a;
                if ($obj !== null) { $ttObj += $obj; $objConnu = true; }
                $ecart = $a > 0 ? ($c / $a - 1) * 100 : null;
                $rows[] = [$mag, $euro($c), [$a > 0 ? $euro($a) : '—', 'color:#8b8177'],
                    [$ecart === null ? '—' : ($ecart > 0 ? '+' : '') . str_replace('.', ',', (string) round($ecart, 1)) . ' %',
                     $ecart === null ? 'color:#8b8177' : ($ecart >= 0 ? 'color:#2d7a3e;font-weight:700' : 'color:#8D1D2C;font-weight:700')],
                    [$obj === null ? '—' : $euro($obj) . (($objSrc['source'] ?? '') === 'theorique' ? ' (théorique)' : ''), 'color:#8b8177'],
                    [$obj === null ? 'objectif non encodé' : ($reste > 0 ? $euro($reste) : 'atteint'),
                     $obj === null ? 'color:#8b8177' : ($reste > 0 ? 'color:#8D1D2C;font-weight:700' : 'color:#2d7a3e;font-weight:700')]];
            }
            if ($rows !== []) {
                $ecartR = $ttA > 0 ? ($ttCum / $ttA - 1) * 100 : null;
                $resteR = $objConnu ? $ttObj - $ttCum : null;
                $rows[] = [['Réseau', 'font-weight:700'], [$euro($ttCum), 'font-weight:700'],
                    [$ttA > 0 ? $euro($ttA) : '—', 'color:#8b8177;font-weight:700'],
                    [$ecartR === null ? '—' : ($ecartR > 0 ? '+' : '') . str_replace('.', ',', (string) round($ecartR, 1)) . ' %',
                     $ecartR === null ? 'color:#8b8177' : ($ecartR >= 0 ? 'color:#2d7a3e;font-weight:700' : 'color:#8D1D2C;font-weight:700')],
                    [$objConnu ? $euro($ttObj) : '—', 'color:#8b8177;font-weight:700'],
                    [$resteR === null ? '—' : ($resteR > 0 ? $euro($resteR) : 'atteint'),
                     $resteR === null ? 'color:#8b8177' : ($resteR > 0 ? 'color:#8D1D2C;font-weight:700' : 'color:#2d7a3e;font-weight:700')]];
                // Ce que les semaines RESTANTES ont rapporté l'an dernier, aux
                // mêmes dates : la jauge s'en sert pour dire s'il manquera
                // quelque chose à la fin du mois, sans attendre la fin du mois.
                $finMois = date('Y-m-t', strtotime((string) $periode['au']));
                $suite = date('Y-m-d', strtotime((string) $periode['au'] . ' +1 day'));
                $joursRestants = $suite <= $finMois
                    ? (int) (new DateTimeImmutable($suite))->diff(new DateTimeImmutable($finMois))->days + 1 : 0;
                $semaines = (int) ceil($joursRestants / 7);
                $proj = ['magasins' => []];
                if ($joursRestants > 0) {
                    $proj = rapVentesFenetre(
                        (new DateTimeImmutable($suite))->modify('-1 year')->format('Y-m-d'),
                        (new DateTimeImmutable($finMois))->modify('-1 year')->format('Y-m-d'));
                }
                $jauges = '';
                foreach ($cumul['magasins'] as $mag => $v) {
                    if ($perim !== [] && !in_array($mag, $perim, true)) { continue; }
                    $o = $objectifs[$mag] ?? null;
                    if ($o === null) { continue; }
                    $jauges .= (count($cumul['magasins']) > 1 && $perim === []
                        ? '<div style="font-family:sans-serif;font-size:11.5px;font-weight:700;color:#221E1A;margin-top:12px">' . $e2($mag) . '</div>' : '')
                        . rapJaugeObjectif((float) ($v['ca'] ?? 0), (float) ((($proj['magasins'][$mag] ?? [])['ca']) ?? 0),
                            (float) $o['montant'], $semaines);
                }
                $b['htmlPar'][] = ['', '<div style="font-size:12px;font-weight:700;font-family:sans-serif;margin-top:14px">'
                    . 'Cumul du mois — du ' . $e2(date('d/m', strtotime($mois1))) . ' au ' . $e2(date('d/m', strtotime((string) $periode['au']))) . '</div>'
                    . rapTableHtml(['Magasin', 'Cumul', 'A-1', 'Écart', 'Objectif du mois', 'Reste à faire'], $rows)
                    . '<div style="font-size:10.5px;color:#8b8177;font-family:sans-serif;margin:-2px 0 8px">'
                    . 'Objectif : le budget de CA encodé pour le mois (écran Encodage du budget) ; à défaut, le CA théorique de '
                    . 'l’étude de marché, signalé « (théorique) ». « Reste à faire » = objectif − cumul, '
                    . 'sur le mois entier — pas au prorata des jours écoulés.</div>'
                    . $jauges];
            }
            break;
        }
        case 'trafic-passage':
        case 'xp-ticket': {
            // Deux mesures, un même geste : la période, le réseau, et la même
            // période décalée (A-1 par défaut, M-1 ou S-1 au choix du
            // rapport). Bloc d'information : il s'imprime toujours, il ne
            // compte pas de « point à traiter ».
            $b['action'] = $slug === 'trafic-passage'
                ? 'Visibilité locale, vitrine, animations — ce qui fait entrer.'
                : 'Montée en gamme, vente additionnelle, conseil au comptoir.';
            $fen = rapFenetreComparaison($periode, (string) ($periode['comparaison'] ?? 'A-1'));
            $now = rapVentesFenetre((string) $periode['du'], (string) $periode['au']);
            if ($now['magasins'] === []) { $b['motif'] = 'aucune vente lue sur la période (API panel)'; break; }
            $avant = rapVentesFenetre($fen['du'], $fen['au']);
            $perim = (array) ($periode['magasins'] ?? []);
            $t = $slug === 'trafic-passage'
                ? rapTableComparaison($now, $avant, $fen, 'clientsJour', 'Passage clients par jour',
                    fn ($v) => $v === null ? '—' : str_replace('.', ',', (string) round((float) $v, 1)), $perim)
                : rapTableComparaison($now, $avant, $fen, 'panier', 'Ticket moyen',
                    fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ') . ' €', $perim);
            if ($t === '') { $b['motif'] = 'aucun magasin du périmètre n’a vendu sur la période'; break; }
            $b['htmlPar'][] = ['', $t];
            break;
        }
        case 'xp-bilan': {
            // Le bilan de la période AVANT la liste des écarts : combien de
            // tâches ont été demandées, combien rendues, comment les cotes se
            // distribuent — et ce qui a été fait de mieux. Un rapport qui ne
            // montre que les manquements donne une image fausse de la semaine.
            // (Le périmètre de magasins est appliqué par le générateur, sur la
            // première case de chaque ligne — ici le nom du magasin.)
            $b['action'] = 'Regarder ce qui a été rendu avant ce qui reste à reprendre.';
            $ts = rapTaches($periode['du'], $periode['au']);
            if ($ts === []) { $b['motif'] = 'aucune tâche lue sur la période (API panel)'; break; }
            $sig = (array) setting('signalement', []);
            $niv = (array) ($sig['niveaux'] ?? []);
            $perim = (array) ($periode['magasins'] ?? []);
            $par = [];
            foreach ($ts as $t) {
                $mag = (string) ($t['magasin'] ?? '');
                if ($perim !== [] && !in_array($mag, $perim, true)) { continue; }
                $par[$mag][] = $t;
            }
            foreach ($par as $mag => $liste) {
                $n = count($liste); $rendues = 0; $notees = 0; $somme = 0; $sansPhoto = 0;
                $dist = []; $exemplaires = [];
                foreach ($liste as $t) {
                    $st = (string) ($t['statut'] ?? '');
                    if ($st === 'sansPhoto') { $sansPhoto++; }
                    if ($st !== 'nonRendue') { $rendues++; }
                    $note = $t['note'] ?? null;
                    if ($note === null) { continue; }
                    $notees++; $somme += (int) $note;
                    $dist[(int) $note] = ($dist[(int) $note] ?? 0) + 1;
                    if ((int) $note >= 5) { $exemplaires[] = $t; }
                }
                // La PHOTO de chaque tâche exemplaire, comme pour les écarts :
                // on montre ce qui a été bien fait, pas seulement son intitulé.
                // Bornée à quatre par magasin — au-delà, l'email pèserait
                // plusieurs mégaoctets pour des félicitations.
                $fiches = [];
                // Douze vignettes : une page A4 pleine (3 × 4), pas davantage —
                // au-delà, les félicitations deviennent un catalogue.
                foreach (array_slice($exemplaires, 0, 12) as $t) {
                    if ((string) ($t['shopId'] ?? '') === '') { continue; }
                    $f = rapFicheTache((string) $t['shopId'], (string) ($t['taskId'] ?? ''), (string) ($t['date'] ?? ''),
                        (string) ($t['tache'] ?? ''), $mag, 5, (string) ($t['comment'] ?? ''), rapGrilleDe($periode)[0] >= 3);
                    if ($f !== '') { $fiches[] = rapFondNote(5) + ['html' => $f]; }
                }
                [$col, $rang] = rapGrilleDe($periode);
                // Le détail tâche par tâche : chaque passage noté de la période,
                // dans l'ordre du temps, et la moyenne de la tâche. C'est là
                // qu'on voit qu'une tâche tenue toute la semaine a lâché un
                // jour — ce qu'aucune moyenne globale ne montre.
                $parTache = [];
                foreach ($liste as $t) {
                    if (($t['note'] ?? null) === null) { continue; }
                    $parTache[(string) ($t['tache'] ?? 'Tâche')][(string) ($t['date'] ?? '')] = (int) $t['note'];
                }
                $b['htmlPar'][] = [$mag, rapBilanHtml($n, $rendues, $notees, $sansPhoto,
                    $notees > 0 ? round($somme / $notees, 1) : null, $dist, $exemplaires, $niv, $fiches, $col, $rang,
                    $parTache, rapJoursDe($periode))];
            }
            break;
        }
        case 'xp-taches': {
            $b['action'] = 'Photo annotée et commentaire dans le cockpit — à revoir avec l’équipe.';
            $ts = rapTaches($periode['du'], $periode['au']);
            if ($ts === []) { $b['motif'] = 'aucune tâche lue sur la période (API panel)'; break; }
            $perim = (array) ($periode['magasins'] ?? []);
            $fiches = 0; $poidsFiches = 0; $sautees = 0; $cartesParMag = [];
            $b['pointsCaches'] = [];
            foreach ($ts as $t) {
                if ($perim !== [] && !in_array((string) $t['magasin'], $perim, true)) { continue; }
                $note = $t['note'] ?? null;
                if ($note !== null && (int) $note <= $seuils['tacheNote']) {
                    $b['lignes'][] = [$t['magasin'], '« ' . ($t['tache'] ?? ('Tâche #' . ($t['taskId'] ?? '?'))) . ' » notée ' . $note . '/5 le '
                        . substr((string) ($t['date'] ?? ''), 5) . ($t['comment'] ? ' — ' . mb_substr((string) $t['comment'], 0, 160) : ''), (int) $note <= 2];
                    // La FICHE : photo annotée des repères + référence attendue.
                    // TOUTES les tâches sous le seuil en portent une — un écart
                    // sans photo se conteste, avec photo il se corrige. La
                    // seule borne est le POIDS : au-delà de RAP_POIDS_FICHES
                    // d'images, l'email ne passerait plus, et le rapport dit
                    // alors combien de fiches il a laissées de côté.
                    if (($t['shopId'] ?? '') !== '' && $poidsFiches < RAP_POIDS_FICHES) {
                        $fiche = rapFicheTache((string) $t['shopId'], (string) ($t['taskId'] ?? ''), (string) ($t['date'] ?? ''),
                            (string) ($t['tache'] ?? ''), (string) $t['magasin'], (int) $note, (string) ($t['comment'] ?? ''),
                            rapGrilleDe($periode)[0] >= 3);
                        if ($fiche !== '') {
                            $cartesParMag[(string) $t['magasin']][] = rapFondNote((int) $note) + ['html' => $fiche];
                            $fiches++; $poidsFiches += strlen($fiche);
                            // La fiche dit tout : magasin, tâche, date, note et
                            // repères. Reprendre la même chose en ligne de texte
                            // au-dessus faisait doublon — le point reste COMPTÉ
                            // dans la pastille de résumé, il n'est plus réécrit.
                            $b['pointsCaches'][] = array_pop($b['lignes']);
                        }
                    } elseif (($t['shopId'] ?? '') !== '') {
                        $sautees++;
                    }
                }
            }
            if ($sautees > 0) {
                $b['infos'][] = ['', $sautees . ' fiche(s) photo laissée(s) de côté — le rapport atteignait '
                    . round(RAP_POIDS_FICHES / 1000000) . ' Mo d’images ; elles se consultent dans le cockpit.'];
            }
            // La grille A4 qui convient à la période, magasin par magasin.
            [$col, $rang] = rapGrilleDe($periode);
            foreach ($cartesParMag ?? [] as $mag => $cartes) {
                $b['htmlPar'][] = [$mag, rapFichesGrille($cartes, $col, $rang)];
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
        case 'xp-classement': {
            // Ce que le panel CALCULE DÉJÀ : demandées, faites, sautées,
            // ratées, obligatoires manquées, journée close. Le cockpit le
            // reconstituait magasin par magasin ; ici on le lit.
            $b['action'] = 'Reprendre avec les magasins dont l’exécution décroche, et les obligatoires manquées d’abord.';
            $cl = rapCtx('classement-' . $periode['du'] . '-' . $periode['au'],
                fn () => classementFenetre((string) $periode['du'], (string) $periode['au']));
            if (!is_array($cl)) { $b['motif'] = 'classement des tâches indisponible (API panel)'; break; }
            $perim = (array) ($periode['magasins'] ?? []);
            $seuil = (float) $seuils['tauxTaches'];
            foreach ($cl['magasins'] as $m) {
                if ($perim !== [] && !in_array($m['nom'], $perim, true)) { continue; }
                if ($m['total'] <= 0) { continue; }
                $sous = $m['taux'] !== null && $m['taux'] < $seuil;
                $manque = (int) $m['obligatoiresManquees'] > 0;
                $txt = $fmtP($m['taux']) . ' d’exécution — ' . $m['faites'] . ' faites sur ' . $m['total']
                    . ((int) $m['sautees'] > 0 ? ', ' . $m['sautees'] . ' sautée(s)' : '')
                    . ((int) $m['ratees'] > 0 ? ', ' . $m['ratees'] . ' en échec' : '')
                    . ($manque ? ', ' . $m['obligatoiresManquees'] . ' obligatoire(s) manquée(s)' : '')
                    . ' (' . $m['joursClos'] . ' journée(s) close(s) sur ' . $m['jours'] . ')';
                $b['lignes'][] = [$m['nom'], $txt, $sous || $manque];
            }
            if ($b['lignes'] === []) { $b['motif'] = 'aucune tâche demandée sur la période'; break; }
            $r = $cl['reseau'];
            $b['infos'][] = 'Réseau : ' . $fmtP($r['taux']) . ' d’exécution — ' . $r['faites'] . ' faites sur '
                . $r['total'] . ($r['obligatoiresManquees'] > 0 ? ', ' . $r['obligatoiresManquees'] . ' obligatoire(s) manquée(s)' : '')
                . '. Seuil d’alerte : ' . $fmtP($seuil) . '.';
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

/**
 * La fenêtre de COMPARAISON d'une période : la même durée, décalée d'un an,
 * d'un mois ou d'une semaine.
 *
 * A-1 est le repère par défaut d'un commerce saisonnier — comparer une semaine
 * d'août à celle de juillet dirait surtout que c'est l'été. M-1 et S-1 servent
 * quand on suit une action récente. La durée ne change jamais : sinon l'écart
 * mesurerait le calendrier, pas le magasin.
 */
const RAP_COMPARAISONS = ['A-1' => 'même période l’an dernier', 'M-1' => 'même période le mois dernier',
    'S-1' => 'même période la semaine passée'];

/**
 * Les fenêtres OBSERVÉES proposées au compositeur.
 *
 * La clé vide veut dire « selon la cadence » : c'est le comportement d'avant —
 * quotidien → la veille, hebdo → la semaine passée, mensuel → le mois passé —
 * et il reste le défaut, pour que les rapports déjà réglés ne changent pas de
 * fenêtre parce qu'on a ajouté un bouton.
 */
const RAP_PERIODES = [
    '' => 'selon la cadence d’envoi',
    'hier' => 'la journée d’hier',
    'semaine-passee' => 'la semaine passée (lundi → dimanche)',
    'mois-en-cours' => 'le mois en cours, jusqu’à aujourd’hui',
    'mois-passe' => 'le mois passé, entier',
    'libre' => 'deux dates au choix',
];

/**
 * Chaque fenêtre proposée, résolue en dates — et sa fenêtre de comparaison.
 *
 * C'est le SERVEUR qui tranche : refaire ce calcul en JavaScript ferait deux
 * vérités pour une même question, et c'est toujours l'écran qui aurait tort.
 */
function rapFenetresProposees(): array
{
    $out = [];
    foreach (['hier', 'semaine-passee', 'mois-en-cours', 'mois-passe'] as $cle) {
        $out[$cle] = rapPeriode('hebdo', $cle);
    }
    foreach (['quotidien', 'hebdo', 'mensuel'] as $freq) {
        $out['cadence:' . $freq] = rapPeriode($freq, null);
    }
    foreach ($out as $cle => $p) {
        $cmp = [];
        foreach (array_keys(RAP_COMPARAISONS) as $code) {
            $f = rapFenetreComparaison($p, $code);
            $cmp[$code] = ['du' => $f['du'], 'au' => $f['au']];
        }
        $out[$cle] = ['du' => $p['du'], 'au' => $p['au'], 'label' => $p['label'],
            'jours' => (int) (new DateTimeImmutable($p['du']))->diff(new DateTimeImmutable($p['au']))->days + 1,
            'cmp' => $cmp];
    }
    return $out;
}

function rapFenetreComparaison(array $periode, string $code): array
{
    $decal = ['A-1' => '-1 year', 'M-1' => '-1 month', 'S-1' => '-7 days'][$code] ?? '-1 year';
    $du = (new DateTimeImmutable((string) $periode['du']))->modify($decal);
    $au = (new DateTimeImmutable((string) $periode['au']))->modify($decal);
    return ['du' => $du->format('Y-m-d'), 'au' => $au->format('Y-m-d'), 'code' => $code,
        'label' => RAP_COMPARAISONS[$code] ?? RAP_COMPARAISONS['A-1']];
}

/**
 * Les indicateurs de vente par magasin sur une fenêtre : tickets, CA, et ce
 * qu'on en tire — clients par jour et ticket moyen.
 *
 * Une seule lecture par fenêtre (cache de contexte), partagée par les blocs
 * trafic et expérience client : deux blocs, un aller-retour.
 */
function rapVentesFenetre(string $du, string $au): array
{
    return rapCtx('ventes-' . $du . '-' . $au, function () use ($du, $au) {
        $liste = analyseListe(PanelApi::shopsSalesKpisEntre($du, $au) ?? []);
        $jours = max(1, (int) (new DateTimeImmutable($du))->diff(new DateTimeImmutable($au))->days + 1);
        $noms = [];
        try {
            foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $sh) { $noms[(int) $sh['id']] = (string) $sh['name']; }
        } catch (PDOException $e) { /* noms indisponibles */ }
        $out = ['jours' => $jours, 'magasins' => [], 'reseau' => null];
        $ttTickets = 0; $ttCa = 0.0; $actifs = 0;
        foreach ($liste as $x) {
            $id = 0;
            foreach (['shop_id', 'id_shop', 'id'] as $c) {
                if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; }
            }
            $tickets = (int) (nombreOuNull($x, ['tickets', 'ticket_count', 'receipts']) ?? 0);
            $ca = (float) (nombreOuNull($x, ['ca', 'turnover', 'revenue']) ?? 0);
            // Une entrée technique du panel — inconnue du référentiel ET sans
            // vente — n'est pas un magasin : elle tirerait la moyenne réseau
            // vers le bas sans exister nulle part ailleurs.
            if (!isset($noms[$id]) && $tickets === 0 && $ca <= 0) { continue; }
            $out['magasins'][$noms[$id] ?? ('Magasin ' . $id)] = [
                // Zéro vente n'est pas une vente à zéro : un magasin qui
                // n'existait pas encore l'an dernier ne vaut pas « 0 € », il
                // ne vaut rien du tout — et l'écart ne se calcule pas.
                'tickets' => $tickets, 'ca' => $ca > 0 ? round($ca, 2) : null,
                // Aucun ticket sur la fenêtre : « 0 client par jour » se lirait
                // comme une mesure, alors que c'est une absence — un magasin
                // qui n'existait pas encore l'an dernier, ou fermé. Rien à
                // comparer, donc rien à afficher.
                'clientsJour' => $tickets > 0 ? round($tickets / $jours, 1) : null,
                'panier' => $tickets > 0 ? round($ca / $tickets, 2) : null];
            $ttTickets += $tickets; $ttCa += $ca;
            if ($tickets > 0) { $actifs++; }
        }
        if ($out['magasins'] !== []) {
            $out['reseau'] = ['tickets' => $ttTickets, 'ca' => round($ttCa, 2),
                // Clients/jour réseau = le total ramené au nombre de magasins
                // OUVERTS sur la fenêtre — même définition qu'à l'écran.
                'clientsJour' => $actifs > 0 ? round($ttTickets / $jours / $actifs, 1) : null,
                'panier' => $ttTickets > 0 ? round($ttCa / $ttTickets, 2) : null];
        }
        return $out;
    }) ?? ['jours' => 1, 'magasins' => [], 'reseau' => null];
}

/**
 * L'objectif de CA du mois, par NOM de magasin.
 *
 * Il vit dans ceo_shop_month_perf (encodage du budget) et se lit par
 * identifiant : on le rapproche des noms, seule clé que les blocs manipulent.
 * Un magasin sans budget encodé n'a pas d'objectif — pas d'objectif inventé.
 */
function rapObjectifsMois(string $date): array
{
    return rapCtx('objectifs-' . substr($date, 0, 7), function () use ($date) {
        $noms = [];
        try {
            foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $sh) { $noms[(int) $sh['id']] = (string) $sh['name']; }
        } catch (PDOException $e) { return []; }
        $out = [];
        try {
            // Le budget négocié fait objectif ; à défaut, le CA THÉORIQUE de
            // l'étude de marché. Un magasin qui ouvre a son étude bien avant
            // son premier budget : sans ce repli, son rapport disait
            // « objectif non encodé » alors que l'étude, elle, dit un chiffre.
            // La provenance voyage avec la valeur — le rapport doit pouvoir la
            // nommer, on ne prend pas l'une pour l'autre.
            foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE year = ? AND month = ?',
                [(int) substr($date, 0, 4), (int) substr($date, 5, 2)]) as $b) {
                $nom = $noms[(int) $b['shop_id']] ?? null;
                if ($nom === null) { continue; }
                $v = $b['revenue_budget'];
                if ($v !== null && (float) $v > 0) { $out[$nom] = ['montant' => (float) $v, 'source' => 'budget']; continue; }
                $t = $b['ca_theorique'];
                if ($t !== null && (float) $t > 0) { $out[$nom] = ['montant' => (float) $t, 'source' => 'theorique']; }
            }
        } catch (PDOException $e) { /* budget non encodé */ }
        return $out;
    }) ?? [];
}

/**
 * La jauge de l'objectif du mois : ce qui est fait, ce que les semaines
 * restantes devraient rapporter, ce qui manquerait quand même.
 *
 *  · VERT   — le cumul réalisé ;
 *  · JAUNE  — les semaines qui restent, mesurées sur les MÊMES semaines l'an
 *             dernier. Ce n'est pas une prévision maison : c'est ce que le
 *             magasin a fait l'an passé aux mêmes dates ;
 *  · ROUGE  — l'écart qui resterait à la fin du mois. Hachuré : ce n'est pas
 *             un chiffre mesuré, c'est un manque projeté.
 *
 * Le jaune ne dépasse jamais l'objectif : s'il suffit à combler, il est
 * tronqué et la phrase dit que l'objectif est tenu.
 */
function rapJaugeObjectif(float $cumul, float $projection, float $objectif, int $semaines): string
{
    if ($objectif <= 0) { return ''; }
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $eur = fn ($v) => number_format($v, 0, ',', ' ') . ' €';
    $pct = fn ($v) => (string) round($v / $objectif * 100) . ' %';
    $vert = min($cumul, $objectif);
    $jaune = max(0.0, min($projection, $objectif - $vert));
    $rouge = max(0.0, $objectif - $vert - $jaune);
    $depasse = $cumul > $objectif ? $cumul - $objectif : 0.0;
    $lg = fn ($c, $txt) => '<span style="white-space:nowrap"><i style="display:inline-block;width:9px;height:9px;border-radius:2px;background:'
        . $c . ';vertical-align:-1px"></i> ' . $txt . '</span>';
    $cell = fn ($c, $v, $hachure = false) => $v <= 0 ? '' :
        '<td width="' . round($v / $objectif * 100, 2) . '%" style="background:'
        . ($hachure ? 'repeating-linear-gradient(45deg,#C0182B,#C0182B 5px,#a91325 5px,#a91325 10px)' : $c)
        . ';height:26px;text-align:right;padding-right:5px;' . $F
        . ';font-size:10px;color:#ffffff;font-weight:700;white-space:nowrap">'
        . ($v / $objectif > 0.07 ? $pct($v) : '') . '</td>';

    return '<div style="' . $F . ';font-size:12px;font-weight:700;color:#221E1A;margin:14px 0 7px">Objectif du mois — '
        . $eur($objectif) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:6px;overflow:hidden;background:#EDE7DE"><tr>'
        . $cell('#2D7A3E', $vert) . $cell('#E1A32B', $jaune) . $cell('#C0182B', $rouge, true)
        . '</tr></table>'
        . '<div style="' . $F . ';margin-top:7px;font-size:11px;color:#4a443c">'
        . $lg('#2D7A3E', 'réalisé <b>' . $eur($cumul) . '</b>') . ' &nbsp; '
        . ($semaines > 0
            ? $lg('#E1A32B', $semaines . ' semaine' . ($semaines > 1 ? 's' : '') . ' à venir, au rythme de l’an dernier <b>'
                . $eur($projection) . '</b>') . ' &nbsp; '
            : '')
        . $lg('#C0182B', $rouge > 0 ? 'à trouver en plus <b>' . $eur($rouge) . '</b>' : '<b>objectif tenu</b>')
        . '</div>'
        . '<div style="' . $F . ';margin-top:8px;font-size:11.5px;color:#221E1A;background:#F7F3EC;border-radius:8px;padding:8px 11px">'
        . ($depasse > 0
            ? 'Objectif déjà dépassé de <b style="color:#2D7A3E">' . $eur($depasse) . '</b>.'
            : ($rouge > 0
                ? 'Au rythme de l’an dernier, il manquerait <b style="color:#C0182B">' . $eur($rouge) . '</b> à la fin du mois.'
                : 'Au rythme de l’an dernier, l’objectif est tenu.'))
        . '</div>';
}

/** Un tableau magasin / réseau / comparaison, pour une mesure donnée. */
function rapTableComparaison(array $now, array $avant, array $fen, string $cle, string $titre,
                             callable $fmt, array $perim = []): string
{
    $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $ecart = function (?float $a, ?float $b): array {
        if ($a === null || $b === null || $b == 0.0) { return ['—', 'color:#8b8177']; }
        $v = ($a / $b - 1) * 100;
        $txt = ($v > 0 ? '+' : '') . str_replace('.', ',', (string) round($v, 1)) . ' %';
        return [$txt, abs($v) < 1 ? 'color:#8b8177' : ($v > 0 ? 'color:#2d7a3e;font-weight:700' : 'color:#8D1D2C;font-weight:700')];
    };
    $rows = [];
    foreach ($now['magasins'] as $mag => $v) {
        if ($perim !== [] && !in_array($mag, $perim, true)) { continue; }
        $a = $v[$cle] ?? null;
        $b = ($avant['magasins'][$mag] ?? [])[$cle] ?? null;
        $rows[] = [$mag, $fmt($a), [$fmt($b), 'color:#8b8177'], $ecart($a === null ? null : (float) $a, $b === null ? null : (float) $b)];
    }
    if ($rows === []) { return ''; }
    $rn = $now['reseau'][$cle] ?? null;
    $ra = ($avant['reseau'] ?? [])[$cle] ?? null;
    $rows[] = [['Réseau', 'font-weight:700'], [$fmt($rn), 'font-weight:700'], [$fmt($ra), 'color:#8b8177;font-weight:700'],
        $ecart($rn === null ? null : (float) $rn, $ra === null ? null : (float) $ra)];
    // Le titre du bloc, juste au-dessus, dit déjà « Passage clients par jour » :
    // le réécrire ici donnait deux titres pour un tableau.
    return rapTableHtml(['Magasin', 'Période', $fen['code'], 'Écart'], $rows)
        . '<div style="font-size:10.5px;color:#8b8177;font-family:sans-serif;margin:-2px 0 8px">'
        . $e($fen['code'] . ' = ' . $fen['label']) . ' — du ' . $e(date('d/m/Y', strtotime($fen['du'])))
        . ' au ' . $e(date('d/m/Y', strtotime($fen['au']))) . '.</div>';
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
    $comparaison = isset(RAP_COMPARAISONS[(string) ($rep['comparaison'] ?? '')]) ? (string) $rep['comparaison'] : 'A-1';
    $defs = rapBlocDefs();
    $sections = [];
    foreach ($blocs as $slug) {
        if (!isset($defs[$slug])) { continue; }
        if (($modes[$slug] ?? '') === 'complet') { $periode['avisComplets'] = true; }
        // Le périmètre voyage jusqu'au bloc : sans lui, un bloc qui BORNE son
        // travail (les photos, limitées à huit) dépense son budget sur des
        // magasins que le filtre écartera juste après — et le magasin du
        // rapport se retrouve sans image. Mesuré sur le rapport hebdomadaire
        // d'un franchisé : dix écarts listés, aucune photo.
        $b = rapBloc($slug, $seuils, $periode + ['magasins' => $filtre, 'comparaison' => $comparaison]);
        $b['nom'] = $defs[$slug]['nom'];
        $b['levier'] = $defs[$slug]['levier'];
        $b['famille'] = $defs[$slug]['famille'] ?? '';
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
    // L'ORDRE VOULU d'abord, s'il y en a un : les blocs qu'il nomme sortent
    // dans cet ordre, les autres (cochés depuis, ou hérités) le suivent dans
    // l'ordre par levier.
    $mep = rapMiseEnPageDe($rep['mise_en_page'] ?? '');
    $ordre = array_keys(RAP_LEVIERS);
    // Par levier d'abord ; puis les blocs de TÂCHES en fin de levier, pour
    // qu'un KPI chiffré ne vienne pas s'intercaler entre le bilan des tâches
    // et la liste des écarts. À égalité, l'ordre des cases cochées décide
    // (le tri de PHP 8 est stable).
    usort($sections, function ($a, $b2) use ($ordre, $mep) {
        if ($mep['ordre'] !== []) {
            $ia = array_search($a['slug'] ?? '', $mep['ordre'], true);
            $ib = array_search($b2['slug'] ?? '', $mep['ordre'], true);
            if ($ia !== false || $ib !== false) {
                // Un bloc absent de l'ordre voulu passe après ceux qui y sont.
                return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
            }
        }
        $l = array_search($a['levier'], $ordre, true) <=> array_search($b2['levier'], $ordre, true);
        return $l !== 0 ? $l : ((($a['famille'] ?? '') === 'taches' ? 1 : 0) <=> (($b2['famille'] ?? '') === 'taches' ? 1 : 0));
    });
    // Le bilan des tâches et les photos ne se séparent pas. La PAIRE se pose
    // là où l'ordre demandé plaçait le premier des deux — mettre les photos en
    // tête, c'est demander les tâches en tête —, et se lit toujours dans le
    // même sens : le bilan, puis les photos qu'il commente.
    $iB = null; $iT = null;
    foreach ($sections as $i2 => $s2) {
        if (($s2['slug'] ?? '') === 'xp-bilan') { $iB = $i2; }
        if (($s2['slug'] ?? '') === 'xp-taches') { $iT = $i2; }
    }
    if ($iB !== null && $iT !== null && $iT !== $iB + 1) {
        $ancre = min($iB, $iT);
        $paire = [$sections[$iB], $sections[$iT]];
        foreach ([max($iB, $iT), min($iB, $iT)] as $i3) { array_splice($sections, $i3, 1); }
        array_splice($sections, $ancre, 0, $paire);
    }
    // Qui ouvre une page : ce que le compositeur a demandé, plus les sauts
    // que le rapport impose de lui-même. Jamais le tout premier bloc — une
    // page blanche avant le rapport n'apprend rien.
    $sauts = array_unique(array_merge($mep['sauts'], rapSautsForces()));
    foreach ($sections as $i2 => $s2) {
        $sections[$i2]['saut'] = $i2 > 0 && in_array($s2['slug'] ?? '', $sauts, true);
    }

    $nPoints = array_sum(array_map(fn ($s) => count($s['lignes']) + count($s['pointsCaches'] ?? []), $sections));
    $nMotifs = count(array_filter($sections, fn ($s) => $s['motif'] !== null));
    $resume = $nPoints . ' point(s) à traiter'
        . ($nMotifs ? ' · ' . $nMotifs . ' bloc(s) sans donnée' : '')
        . ' — ' . $periode['label'];
    $vide = $nPoints === 0 && !array_filter($sections, fn ($s) => $s['infos'] !== [] || $s['htmlPar'] !== []);

    // Le run d'abord, le HTML ensuite : le pied du rapport porte un lien
    // absolu vers sa propre page, qui exige l'identifiant.
    Db::exec('INSERT INTO ceo_rapport_run (rapport_id, genere_le, periode_du, periode_au, statut, resume, html, contexte)
              VALUES (?,?,?,?,?,?,NULL,?)',
        [(int) $rep['id'], date('Y-m-d H:i:s'), $periode['du'], $periode['au'],
         $vide ? 'vide' : 'genere', $resume,
         json_encode(['magasin' => rapMagasinLabel($rep), 'rapport' => (string) ($rep['nom'] ?? '')],
             JSON_UNESCAPED_UNICODE)]);
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

/**
 * Le magasin dont parle un rapport — ou le réseau.
 *
 * Sert au pied de page du PDF et à l'email : une feuille qui traîne sur un
 * comptoir doit dire de QUEL magasin elle parle, sans qu'on ait à la lire.
 */
function rapMagasinLabel(array $rep): string
{
    $mags = json_decode((string) ($rep['magasins'] ?? '[]'), true) ?: [];
    if (count($mags) === 1) { return (string) $mags[0]; }
    if (count($mags) > 1) { return 'Réseau · ' . count($mags) . ' magasins'; }
    return 'Réseau';
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

    // Les leviers RÉELLEMENT imprimés — un rapport par magasin n'en porte pas
    // toujours autant que le rapport réseau. Servent le rappel sous le titre.
    $leviersVus = [];
    $rendSections = function (array $secs, ?string $magasin) use ($e, $F, &$leviersVus): string {
        $h = '';
        foreach ($secs as $s) {
            $lignes = $magasin === null ? $s['lignes'] : array_values(array_filter($s['lignes'], fn ($l) => $l[0] === $magasin));
            $infos = $magasin === null ? $s['infos'] : array_values(array_filter($s['infos'], fn ($l) => $l[0] === $magasin));
            $htmls = $magasin === null ? ($s['htmlPar'] ?? []) : array_values(array_filter($s['htmlPar'] ?? [], fn ($l) => $l[0] === $magasin));
            if ($lignes === [] && $infos === [] && $htmls === [] && $s['motif'] === null) { continue; }
            $lev = RAP_LEVIERS[$s['levier']];
            $leviersVus[$s['levier']] = $lev['nom'];
            // Le titre du bloc, seul. Le levier est annoncé UNE fois, au
            // bandeau : le répéter en pastille et en barre au-dessus de chaque
            // section faisait trois fois la même information sur une page.
            // Le slug voyage avec le titre : le PDF s'en sert pour décider
            // ce qui commence une page (le bilan des tâches, par exemple).
            $h .= '<div data-titre-bloc="1" data-bloc="' . $e($s['slug'] ?? '') . '"'
                . (!empty($s['saut']) ? ' data-saut="1"' : '') . ' style="' . $F
                . ';font-size:14.5px;font-weight:700;color:#221E1A;margin:24px 0 7px">'
                . $e($s['nom']) . '</div>';
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
            // Le nom du magasin en tête d'une grille ne sert qu'à DISTINGUER :
            // s'il n'y en a qu'un, il est déjà dans l'en-tête du rapport et
            // dans le pied de chaque page — l'écrire une troisième fois au-
            // dessus des photos n'apprend rien.
            $plusieursMags = count($htmls) > 1;
            foreach ($htmls as $l) {
                // Construit par nos soins, jamais issu d'une saisie — pas d'échappement.
                $h .= ($magasin === null && $plusieursMags && $l[0] !== '' ? '<div data-titre-magasin="1" style="' . $F . ';font-size:12.5px;font-weight:700;margin-top:10px;color:#221E1A">' . $e($l[0]) . '</div>' : '')
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
        '<table data-cta="1" role="presentation" cellpadding="0" cellspacing="0" style="margin:2px 0 14px"><tr>'
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
        // Les prises `data-fond`, `data-marge`, `data-carte` et `data-cta` ne
        // servent qu'au PDF : rapPdfHtml() s'en sert pour poser une mise en
        // page de PAPIER (pleine largeur, fond blanc, sans bouton) sans avoir
        // à deviner des styles en ligne.
        . '<table data-fond="1" role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EFE9DF"><tr><td data-marge="1" align="center" style="padding:28px 12px">'
        . '<!--ecran--><div class="ecran-seul" style="' . $F . ';max-width:680px;margin:0 auto 10px;text-align:right">'
        . ($base !== '' && $runId > 0
            ? '<a href="' . $e($base . '/api/cockpit/rapports/run/' . $runId . '/pdf') . '" style="display:inline-block;border:none;border-radius:999px;padding:9px 18px;color:#ffffff;font-size:12px;font-weight:700;text-decoration:none;background:#8D1D2C;margin-right:8px">Télécharger le PDF</a>'
            : '')
        . '<a href="javascript:window.print()" style="display:inline-block;border:1px solid #CFC6B8;border-radius:999px;padding:8px 16px;color:#221E1A;font-size:12px;font-weight:600;text-decoration:none;background:#ffffff">Imprimer (A4)</a></div><!--/ecran-->'
        . '<table data-carte="1" role="presentation" cellpadding="0" cellspacing="0" width="680" style="width:680px;max-width:96%">'
        // — bandeau de marque : le LOGO OFFICIEL sur fond clair (le noir du
        //   logo serait invisible sur bordeaux), liseré bordeaux au-dessous.
        . '<tr><td style="background:#ffffff;border-radius:14px 14px 0 0;border-bottom:3px solid #8D1D2C;padding:16px 30px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td>' . (rapLogoDataUri() !== ''
            ? '<img src="' . rapLogoDataUri() . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
            : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>') . '</td>'
        // À droite du logo : le LEVIER dont parle le rapport, pas le poste du
        // destinataire — celui-ci le connaît, le levier est ce qu'il vient
        // chercher. Sans levier imprimé (rapport sans matière), le poste
        // reprend sa place plutôt qu'un blanc.
        . '<td align="right" style="' . $F . ';color:#8b8177;font-size:10.5px;letter-spacing:1.2px;text-transform:uppercase">'
        . ($leviersVus === []
            ? $e((string) $rep['poste'])
            // Le levier en pastille à SA couleur, la même que la barre du bloc
            // plus bas : deux fois la même couleur, donc un seul repère à
            // apprendre. Vaut pour tous les rapports.
            : implode('', array_map(fn ($code, $nom) => '<span style="display:inline-block;background:'
                . (RAP_LEVIERS[$code]['couleur'] ?? '#8b8177') . ';color:#ffffff;border-radius:999px;'
                . 'padding:4px 11px;margin-left:6px;font-size:9.5px;font-weight:700;letter-spacing:1.2px;'
                . 'text-transform:uppercase;white-space:nowrap">' . $e($nom) . '</span>',
                array_keys($leviersVus), $leviersVus)))
        . '</td>'
        . '</tr></table></td></tr>'
        // — en-tête du rapport
        . '<tr><td style="background:#ffffff;padding:26px 30px 4px">'
        . '<div style="' . $F . ';font-size:21px;font-weight:700;color:#221E1A">' . $e($rep['nom']) . '</div>'
        . '<div style="' . $F . ';font-size:12px;color:#8b8177;margin-top:4px">' . $e(ucfirst($periode['label'])) . ' &middot; généré le ' . date('d/m/Y à H:i') . '</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:12px"><tr>'
        . '<td style="background:#F7ECEA;border-radius:999px;padding:7px 15px;' . $F . ';font-size:12px;font-weight:700;color:#8D1D2C">' . $e($resume) . '</td>'
        . '</tr></table>'
        . '</td></tr>'
        // — corps
        . '<tr><td style="background:#ffffff;padding:4px 30px 18px">' . $corps . '</td></tr>'
        // — pied
        . '<tr data-pied="1"><td style="background:#F9F6F0;border-radius:0 0 14px 14px;border-top:1px solid #EDE7DE;padding:20px 30px">'
        . $bouton
        . '<div style="' . $F . ';font-size:10.5px;color:#8b8177;line-height:1.6">Seuils : food ' . $seuils['food'] . ' % &middot; labour ' . $seuils['labour'] . ' % &middot; overhead ' . $seuils['overhead']
        . ' % &middot; CA/ETP ' . number_format($seuils['caEtp'], 0, ',', ' ') . ' &euro; &middot; tâches &le; ' . $seuils['tacheNote'] . '/5 &middot; cible Google '
        . str_replace('.', ',', (string) $seuils['cibleGoogle']) . ' — réglables dans le cockpit (Catalogue des KPI). Sauf mode « complet », ce rapport ne liste que les seuils franchis.</div>'
        . '</td></tr>'
        . '</table>'
        . '<div style="' . $F . ';font-size:10px;color:#a89f93;padding:14px">Généré automatiquement par le cockpit ' . $e($marque) . '</div>'
        . '</td></tr></table></body></html>';
}

/** La période d'un run, en clair — « du 15/08 au 21/08 », « le 21/08 ». */
function rapPeriodeLabelRun(array $run): string
{
    $du = substr((string) ($run['periode_du'] ?? ''), 0, 10);
    $au = substr((string) ($run['periode_au'] ?? ''), 0, 10);
    if ($du === '' && $au === '') { return 'période du rapport'; }
    if ($du === $au || $au === '') { return 'le ' . date('d/m/Y', strtotime($du ?: $au)); }
    if ($du === '') { return 'jusqu’au ' . date('d/m/Y', strtotime($au)); }
    return 'du ' . date('d/m/Y', strtotime($du)) . ' au ' . date('d/m/Y', strtotime($au));
}

/**
 * L'EMAIL quand le rapport part en pièce jointe : une page de garde, pas le
 * rapport une deuxième fois.
 *
 * Le rapport complet vivait dans le corps du message ET dans le PDF : deux
 * mises en page à tenir, un email de plusieurs mégaoctets, et un lecteur qui
 * ne sait pas laquelle des deux fait foi. Le message dit désormais de quoi il
 * s'agit et ce qu'il porte ; la matière est dans la pièce jointe. Sans PDF
 * (aucun moteur de rendu), le corps redevient le rapport entier — jamais un
 * email vide.
 */
function rapMailGarde(array $rep, array $run, string $labelPeriode, string $nomPdf, string $base): string
{
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif";
    $marque = (string) setting('marque', "L'Atelier by");
    $runId = (int) ($run['id'] ?? 0);
    $resume = (string) ($run['resume'] ?? '');
    $lien = $base !== '' && $runId > 0 ? $base . '/api/cockpit/rapports/run/' . $runId . '/pdf' : '';
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $e($rep['nom']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EFE9DF">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EFE9DF">'
        . '<tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="width:600px;max-width:96%">'
        // — bandeau de marque
        . '<tr><td style="background:#ffffff;border-radius:14px 14px 0 0;border-bottom:3px solid #8D1D2C;padding:16px 28px">'
        . (rapLogoDataUri() !== ''
            ? '<img src="' . rapLogoDataUri() . '" height="28" style="display:block;height:28px" alt="' . $e($marque) . '">'
            : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>')
        . '</td></tr>'
        // — de quoi parle ce message
        . '<tr><td style="background:#ffffff;padding:24px 28px 6px">'
        . '<div style="' . $F . ';font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#8b8177">'
        . $e(rapMagasinLabel($rep)) . '</div>'
        . '<div style="' . $F . ';font-size:20px;font-weight:700;color:#221E1A;margin-top:5px">' . $e($rep['nom']) . '</div>'
        . '<div style="' . $F . ';font-size:12px;color:#8b8177;margin-top:4px">' . $e(ucfirst($labelPeriode))
        . ' &middot; généré le ' . date('d/m/Y à H:i') . '</div>'
        . ($resume !== ''
            ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:14px"><tr>'
              . '<td style="background:#F7ECEA;border-radius:999px;padding:7px 15px;' . $F . ';font-size:12px;font-weight:700;color:#8D1D2C">'
              . $e($resume) . '</td></tr></table>'
            : '')
        . '</td></tr>'
        // — la pièce jointe, nommée : on sait quoi ouvrir. La CARTE ELLE-MÊME
        //   est le lien : un bouton en dessous répétait l'action et allongeait
        //   le message pour rien. Le lien enveloppe le contenu de la cellule
        //   (et non la table) — c'est la forme que les messageries respectent.
        . '<tr><td style="background:#ffffff;padding:18px 28px 6px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #EDE7DE;border-radius:12px;background:#F9F6F0">'
        . '<tr><td style="padding:14px 16px" valign="middle">'
        . ($lien !== '' ? '<a href="' . $e($lien) . '" style="display:block;text-decoration:none;color:inherit">' : '')
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="background:#8D1D2C;border-radius:8px;padding:9px 11px;' . $F . ';font-size:10px;font-weight:700;color:#ffffff;letter-spacing:0.6px">PDF</td>'
        . '<td style="padding-left:12px;' . $F . '">'
        . '<div style="font-size:13px;font-weight:700;color:#221E1A">' . $e($nomPdf) . '</div>'
        . '<div style="font-size:11.5px;color:#8b8177;margin-top:2px">Le rapport complet est en pièce jointe de ce message'
        . ($lien !== '' ? ' — cliquez pour l’ouvrir.' : '.') . '</div>'
        . '</td></tr></table>'
        . ($lien !== '' ? '</a>' : '')
        . '</td></tr></table></td></tr>'
        . '<tr><td style="background:#F9F6F0;border-radius:0 0 14px 14px;border-top:1px solid #EDE7DE;padding:16px 28px;'
        . $F . ';font-size:10.5px;color:#8b8177;line-height:1.6">'
        . 'Périmètre : ' . $e(rapMagasinLabel($rep)) . '. '
        . 'Les seuils et le périmètre se règlent dans le cockpit.'
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

    // Le rapport EN PIÈCE JOINTE, rendu UNE fois pour tous les destinataires —
    // un rendu par adresse coûterait 25 s chacun pour le même fichier. Sans
    // moteur de rendu, l'email part quand même : le rapport est dans le corps
    // du message, et la réponse dit pourquoi le PDF manque plutôt que de
    // laisser chercher une pièce jointe qui n'existe pas.
    $pieces = []; $notePdf = null; $corpsMail = (string) $run['html'];
    if ($viaSmtp) {
        $pdf = rapPdfRendu((string) $run['html'], [
            'magasin' => rapMagasinLabel($rep),
            'rapport' => (string) $rep['nom'],
            'genere' => date('d/m/Y à H:i', strtotime((string) ($run['genere_le'] ?? 'now'))),
            'envoye' => date('d/m/Y'),
        ]);
        if ($pdf !== null) {
            $nomPdf = rapPdfNom($rep, $run);
            $pieces[] = ['nom' => $nomPdf, 'type' => 'application/pdf', 'contenu' => $pdf];
            // Le rapport EST la pièce jointe : le message n'en est que la page
            // de garde. Le corps ne le répète plus.
            $corpsMail = rapMailGarde($rep, $run + ['id' => $runId], rapPeriodeLabelRun($run), $nomPdf, rapBaseUrl());
        } else {
            $notePdf = 'PDF non joint — aucun moteur de rendu n’a répondu sur le serveur ; le rapport reste lisible dans le corps du message.';
        }
    } else {
        $notePdf = 'PDF non joint — envoi par mail() de PHP ; configurez la machine SMTP dans Paramètres pour l’avoir en pièce jointe.';
    }

    $ok = []; $derniereErreur = null;
    foreach ($dests as $d) {
        if ($viaSmtp) {
            $ok[$d] = Smtp::envoyer($d, $sujet, $corpsMail, $pieces);
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
    journalAdd('CEO', 'Rapport', $rep['nom'], $tous
        ? 'Envoyé (' . ($viaSmtp ? 'SMTP' : 'mail()') . ', ' . ($pieces !== [] ? 'PDF joint' : 'sans PDF') . ') à ' . implode(', ', $dests)
        : 'Envoi échoué — ' . ($derniereErreur ?? implode(', ', array_keys(array_filter($ok, fn ($v) => !$v)))));
    return ['ok' => $tous, 'envoyes' => array_keys(array_filter($ok)),
        'echecs' => array_keys(array_filter($ok, fn ($v) => !$v)),
        'via' => $viaSmtp ? 'smtp' : 'mail',
        'pdf' => $pieces !== [] ? $pieces[0]['nom'] : null,
        'notePdf' => $notePdf,
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
            $mep = rapMiseEnPageDe($r['mise_en_page'] ?? '');
            return ['id' => (int) $r['id'], 'code' => $r['code'], 'nom' => $r['nom'], 'poste' => $r['poste'],
                'ordre' => $mep['ordre'], 'sauts' => $mep['sauts'],
                'frequence' => $r['frequence'], 'heure' => (int) $r['heure'], 'jour' => (int) $r['jour'],
                'blocs' => json_decode((string) $r['blocs'], true) ?: [],
                'destinataires' => json_decode((string) ($r['destinataires'] ?? '[]'), true) ?: [],
                'parMagasin' => (bool) $r['par_magasin'], 'actif' => (bool) $r['actif'],
                'jours' => json_decode((string) ($r['jours'] ?? ''), true) ?: null,
                'envoiMode' => $r['envoi_mode'] ?? 'groupe',
                'destParMagasin' => json_decode((string) ($r['dest_par_magasin'] ?? ''), true) ?: [],
                'magasins' => json_decode((string) ($r['magasins'] ?? ''), true) ?: [],
                'periode' => $r['periode'] ?? null,
                'periodeDu' => $r['periode_du'] ?? null, 'periodeAu' => $r['periode_au'] ?? null,
                'modes' => json_decode((string) ($r['modes'] ?? ''), true) ?: [],
                'comparaison' => (string) ($r['comparaison'] ?? 'A-1'),
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
        'comparaisons' => RAP_COMPARAISONS,
        'periodes' => RAP_PERIODES,
        'fenetres' => rapFenetresProposees(),
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
    if (isset($b['comparaison']) && isset(RAP_COMPARAISONS[(string) $b['comparaison']])) {
        Db::exec('UPDATE ceo_rapport SET comparaison = ? WHERE id = ?', [(string) $b['comparaison'], $id]);
    }
    // La fenêtre OBSERVÉE. Chaîne vide = « selon la cadence » : on écrit NULL,
    // qui est exactement ce que rapPeriode() attend pour retomber sur la
    // fréquence — et non la chaîne vide, qu'elle prendrait pour un choix.
    if (array_key_exists('periode', $b)) {
        $pe = (string) $b['periode'];
        $pe = in_array($pe, ['hier', 'semaine-passee', 'mois-en-cours', 'mois-passe', 'libre'], true) ? $pe : null;
        $dd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['du'] ?? '')) ? (string) $b['du'] : null;
        $aa = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($b['au'] ?? '')) ? (string) $b['au'] : null;
        Db::exec('UPDATE ceo_rapport SET periode = ?, periode_du = ?, periode_au = ? WHERE id = ?',
            [$pe, $dd, $aa, $id]);
    }
    // L'ordre des blocs et les sauts de page : envoyés ensemble, enregistrés
    // ensemble — un ordre sans ses sauts décalerait les pages.
    if (isset($b['ordre']) || isset($b['sauts'])) {
        Db::exec('UPDATE ceo_rapport SET mise_en_page = ? WHERE id = ?',
            [json_encode(rapMiseEnPageDe(['ordre' => $b['ordre'] ?? [], 'sauts' => $b['sauts'] ?? []])), $id]);
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
    // Le plan de cadence des contrôles suit le même battement : une fois par
    // jour au premier passage après 5 h (voir cadenceCron), sans cron dédié.
    $cadence = function_exists('cadenceCron') ? cadenceCron() : 'module absent';
    return ['ok' => true, 'heure' => $h, 'faits' => $faits, 'cadence' => $cadence];
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
    $run = Db::row('SELECT * FROM ceo_rapport_run WHERE id = ?', [$id]);
    if ($run === null || $run['html'] === null) { http_response_code(404); return ['error' => 'run inconnu']; }
    if (!function_exists('shell_exec')) {
        http_response_code(501);
        return ['error' => 'exec désactivé sur ce serveur — utilisez « Imprimer (A4) », le navigateur produit le même PDF'];
    }
    // Le même pied de page que le PDF envoyé par email : magasin, rapport et
    // dates — un téléchargement depuis le cockpit n'est pas un autre document.
    // Le contexte du RUN prime sur la définition : un aperçu n'a pas de
    // définition en base, et un envoi par magasin porte un périmètre plus
    // étroit que celui du rapport.
    $ctx = json_decode((string) ($run['contexte'] ?? ''), true) ?: [];
    $rep = Db::row('SELECT nom, magasins FROM ceo_rapport WHERE id = ?', [(int) ($run['rapport_id'] ?? 0)]) ?? [];
    $pdf = rapPdfRendu((string) $run['html'], [
        'magasin' => (string) ($ctx['magasin'] ?? '') !== '' ? (string) $ctx['magasin'] : rapMagasinLabel($rep),
        'rapport' => (string) ($ctx['rapport'] ?? '') !== '' ? (string) $ctx['rapport'] : (string) ($rep['nom'] ?? ''),
        'genere' => date('d/m/Y à H:i', strtotime((string) ($run['genere_le'] ?? 'now'))),
        'envoye' => (($run['statut'] ?? '') === 'envoye') ? date('d/m/Y', strtotime((string) ($run['genere_le'] ?? 'now'))) : '',
    ]);
    if ($pdf === null) {
        http_response_code(501);
        return ['error' => 'aucun moteur PDF sur le serveur (Chromium/wkhtmltopdf absents) — utilisez « Imprimer (A4) », le navigateur produit le même PDF'];
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="rapport-' . $id . '.pdf"');
    echo $pdf;
    exit;
}

/**
 * Le PDF d'un HTML de rapport — les octets, ou null si aucun moteur ne rend.
 *
 * Un seul endroit pour la liste des moteurs : la route de téléchargement et le
 * mailer (qui joint le PDF à l'email) doivent produire le MÊME fichier.
 *
 * CHAQUE essai est borné par `timeout` : un moteur qui attend (xvfb sans
 * écran, chromium qui bloque) retiendrait sinon un worker Apache sans fin —
 * quelques requêtes suffisent alors à coucher toute l'API. Mesuré.
 */
/**
 * Le même rapport, mis en page pour du PAPIER.
 *
 * Rendu tel quel, le document sort comme ce qu'il est à l'écran : une carte de
 * 680 px flottant au milieu d'une page A4, sur fond beige, avec les boutons
 * d'écran — « on dirait le mail ». Ici il devient une feuille : pleine largeur,
 * fond blanc, coins carrés, sans bouton d'action (le PDF ne se clique pas),
 * les fiches photo et les lignes de tableau insécables.
 *
 * Rien n'est réécrit dans le contenu : seules des prises posées à la
 * fabrication (data-fond, data-marge, data-carte, data-cta) sont neutralisées,
 * ce qui évite de deviner des styles en ligne au risque de casser la page.
 */
function rapPdfHtml(string $html): string
{
    $html = (string) preg_replace('#<!--ecran-->.*?<!--/ecran-->#s', '', $html);
    // Repli pour les runs enregistrés avant la prise `data-pied` : le rappel
    // des seuils s'y reconnaît à son texte.
    $html = (string) preg_replace('#<div[^>]*>Seuils\s*:\s*food.*?</div>#s', '', $html);
    $css = '<style>'
        // Des marges de PAPIER, pas d'écran : 15 mm sur les côtés — la feuille
        // respire, et un rapport agrafé ou perforé ne mange pas son texte.
        // Le bas garde 20 mm : le pied de page (logo, magasin, dates,
        // pagination) s'y loge, posé par le moteur sur chaque feuille.
        . '@page{size:A4;margin:14mm 15mm 20mm}'
        . 'html,body{background:#ffffff !important;margin:0 !important;padding:0 !important;'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact}'
        . 'table[data-fond]{background:#ffffff !important}'
        . 'td[data-marge]{padding:0 !important}'
        . 'table[data-carte]{width:100% !important;max-width:100% !important}'
        // La carte occupait toute la largeur ET gardait ses 30 px de marge
        // intérieure d'email : additionnés aux marges de la page, le texte se
        // retrouvait au milieu d'une double marge. La page respire dehors, la
        // carte se resserre dedans.
        . 'table[data-carte]>tbody>tr>td{padding-left:10px !important;padding-right:10px !important}'
        // Entre deux sections, une respiration franche : sur écran on fait
        // défiler, sur papier l'œil a besoin de la coupure.
        . 'div[data-titre-bloc]{margin-top:30px !important}'
        // Les sauts de page décidés à la génération : le compositeur les a
        // demandés, ou le rapport les impose (bilan des tâches, photos). Le
        // premier bloc n'en porte jamais — une page blanche avant le rapport
        // n'apprend rien.
        . 'div[data-titre-bloc][data-saut]{page-break-before:always;margin-top:0 !important}'
        // Les runs enregistrés AVANT cette prise n'ont pas de `data-saut` :
        // pour eux seulement, les deux sauts imposés se visent au slug, comme
        // avant. Un rapport d'hier s'imprime ainsi comme hier.
        . (!str_contains($html, 'data-saut')
            ? 'div[data-bloc="xp-bilan"],div[data-bloc="xp-taches"]{page-break-before:always;margin-top:0 !important}'
            : '')
        // Un titre ne se sépare jamais de ce qu'il annonce.
        . 'div[data-titre-bloc],div[data-titre-magasin]{page-break-after:avoid}'
        // Un peu d'air entre le bandeau et le titre, et entre les blocs.
        . 'table[data-carte]>tbody>tr:first-child>td{padding-top:6px !important;padding-bottom:12px !important}'
        . 'table[data-cta]{display:none !important}'
        // Le pied — bouton « Ouvrir dans le cockpit » et rappel des seuils —
        // s'adresse au lecteur d'un EMAIL. Sur une feuille remise en boutique,
        // il n'ajoute rien : le rapport dit déjà ce qui est hors seuil.
        . 'tr[data-pied]{display:none !important}'
        . '.ecran-seul{display:none !important}'
        // Les runs DÉJÀ enregistrés n'ont pas les prises : on vise alors leurs
        // styles en ligne, pour qu'un rapport d'hier s'imprime aussi bien
        // qu'un rapport de demain.
        . 'table[style*="background:#EFE9DF"]{background:#ffffff !important}'
        . 'td[style*="padding:28px 12px"]{padding:0 !important}'
        . 'table[style*="width:680px"]{width:100% !important;max-width:100% !important}'
        // Les coins arrondis de la carte n'ont pas de sens sur une feuille.
        . 'td[style*="border-radius:14px 14px 0 0"]{border-radius:0 !important}'
        . 'td[style*="border-radius:0 0 14px 14px"]{border-radius:0 !important}'
        // Ce qui ne doit pas se couper entre deux pages.
        . 'div[data-fiche],table[data-fiches] td,tr{page-break-inside:avoid}'
        // Six fiches par page A4 : deux par rangée, trois rangées, toutes de
        // la MÊME hauteur. Sans hauteur fixe, une fiche à deux photos écrase
        // sa voisine et la grille se déforme d'une page à l'autre.
        . 'table[data-page-fiches]{page-break-after:always;page-break-inside:avoid}'
        // L'encadré des 5/5 a SA page : ce qui a été bien fait ne se lit pas
        // en bas d'une page d'écarts. Douze vignettes remplissent exactement
        // une A4, et la page suivante reprend les non-conformités.
        . 'table[data-encadre]{page-break-before:always}'
        . 'table[data-page-fiches]:last-child{page-break-after:auto}'
        . 'table[data-grille="2x3"] tr[data-rangee-fiches]>td{height:84mm}'
        . 'table[data-grille="2x3"] div[data-fiche]{height:80mm;overflow:hidden;box-sizing:border-box}'
        . 'table[data-grille="2x3"] div[data-fiche] img{max-height:44mm}'
        // Douze vignettes sur une page : chaque cadre fait le quart de la
        // hauteur utile, l'écriture se resserre d'un point.
        // Douze vignettes : quatre rangées sur les 271 mm utiles d'une A4, et
        // dans chaque cadre la PHOTO prend tout ce que le texte ne prend pas.
        . rapHauteursVignettes()
        . 'table[data-grille="3x5"] div[data-fiche]{overflow:hidden;box-sizing:border-box;padding:4px 6px !important}'
        . 'table[data-grille="3x5"] div[data-fiche] img{max-width:100%;width:auto !important;height:auto}'
        . 'table[data-grille="3x5"] div[data-fiche] div{font-size:8.5px !important;line-height:1.2 !important}'
        . 'table[data-grille="3x4"] tr[data-rangee-fiches]>td{height:67mm}'
        . 'table[data-grille="3x4"] div[data-fiche]{height:65mm;overflow:hidden;box-sizing:border-box;padding:5px 6px !important}'
        // « Aussi grande que possible » ne veut pas dire déformée : largeur et
        // hauteur bornées, proportions libres — l'image remplit ce qu'elle
        // peut du cadre sans jamais s'étirer.
        . 'table[data-grille="3x4"] div[data-fiche] img{max-height:46mm;max-width:100%;width:auto !important;height:auto}'
        . 'table[data-grille="3x4"] div[data-fiche] div{font-size:9px !important;line-height:1.25 !important}'
        // L'image garde ses proportions et tient dans son cadre : la fiche
        // reste de taille constante, la photo n'est ni étirée ni rognée.
        . 'div[data-fiche] img{width:auto !important;max-width:100%;margin:0 auto}'
        . 'img{max-width:100% !important;height:auto}'
        . '</style>';
    // Injectée EN DERNIER dans <head> : la dernière feuille gagne à égalité de
    // spécificité, et l'`!important` passe devant les styles en ligne.
    $pos = stripos($html, '</head>');
    return $pos === false ? $css . $html : substr($html, 0, $pos) . $css . substr($html, $pos);
}

/**
 * Le pied de page du PDF : logo, magasin, rapport, dates, pagination.
 *
 * C'est une PAGE de pied, donnée à wkhtmltopdf par `--footer-html` : lui seul
 * sait la répéter sur chaque feuille. Mesuré, faute de quoi rien ne marche :
 * un élément en position fixe n'est peint que sur la dernière page, un
 * <thead> que sur la première, et le build des dépôts Ubuntu (Qt non corrigé)
 * ignore purement et simplement les `--footer-*`. Le déploiement installe
 * donc la version corrigée ; sans elle, le PDF sort sans pied plutôt que
 * faux.
 *
 * wkhtmltopdf passe les numéros de page en paramètres d'URL — d'où le petit
 * script qui les recopie. Aucune ressource externe : le logo est incorporé,
 * sinon le rendu partirait chercher un fichier et bloquerait.
 */
function rapPdfPiedHtml(array $meta): string
{
    $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:Helvetica,Arial,sans-serif";
    $logo = rapLogoDataUri();
    $dates = 'Généré le ' . $e($meta['genere'] ?? date('d/m/Y à H:i'))
        . (($meta['envoye'] ?? '') !== '' ? ' · envoyé le ' . $e($meta['envoye']) : '');
    // Un rapport composé porte souvent le magasin DANS son nom (« … —
    // Gosselies ») : le pied l'écrivait alors deux fois. Le magasin reste à
    // gauche, le nom du rapport perd ce qui fait doublon.
    $magasin = trim((string) ($meta['magasin'] ?? ''));
    $rapport = trim((string) ($meta['rapport'] ?? ''));
    if ($magasin !== '' && $rapport !== '' && mb_stripos($rapport, $magasin) !== false) {
        $rapport = trim(str_ireplace($magasin, '', $rapport), " \t—–-·|");
    }
    return '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;padding:0}'
        . 'table{width:100%;border-collapse:collapse;border-top:0.5pt solid #DED6C9}'
        . 'td{' . $F . ';font-size:6.5pt;color:#8b8177;vertical-align:middle;padding-top:2mm}'
        . '</style><script>'
        . 'function pied(){var v={},q=document.location.search.substring(1).split("&");'
        . 'for(var i=0;i<q.length;i++){var kv=q[i].split("=");v[kv[0]]=decodeURIComponent(kv[1]||"");}'
        . 'var el=document.getElementById("pg");if(el){el.textContent=(v.page||"")+"/"+(v.topage||"");}}'
        . '</script></head><body onload="pied()"><table><tr>'
        // Le logo tient dans SA colonne : sans largeur, il passait sous le
        // texte du magasin.
        . '<td style="width:26mm">' . ($logo !== '' ? '<img src="' . $logo . '" style="height:4.5mm;display:block">' : '') . '</td>'
        . '<td style="padding-left:4mm"><span style="color:#221E1A;font-weight:bold">' . $e($magasin) . '</span>'
        . ($rapport !== '' ? ' · ' . $e($rapport) : '') . '</td>'
        // Les dates et la pagination ne se coupent jamais en deux lignes.
        . '<td align="right" style="white-space:nowrap">' . $dates . ' · <span id="pg"></span></td>'
        . '</tr></table></body></html>';
}

function rapPdfRendu(string $html, array $meta = []): ?string
{
    if (!function_exists('shell_exec')) { return null; }
    $tmpH = tempnam(sys_get_temp_dir(), 'rap') . '.html';
    $tmpP = tempnam(sys_get_temp_dir(), 'rap') . '.pdf';
    $tmpF = null;
    $profil = sys_get_temp_dir() . '/rapchrome-' . getmypid() . '-' . substr(sha1($tmpH), 0, 8);
    file_put_contents($tmpH, rapPdfHtml($html));

    // `--print-media-type` : le document porte ses règles @media print — la
    // version corrigée de wkhtmltopdf les applique (celle des dépôts ignore
    // le drapeau, en le disant).
    $wk = '--quiet --page-size A4 --print-media-type --enable-local-file-access'
        . ' --margin-top 14mm --margin-bottom 20mm --margin-left 15mm --margin-right 15mm'
        . ' --footer-spacing 4';
    if ($meta !== []) {
        $tmpF = tempnam(sys_get_temp_dir(), 'rap') . '.html';
        file_put_contents($tmpF, rapPdfPiedHtml($meta));
        $wk .= ' --footer-html ' . escapeshellarg($tmpF);
    }
    // Chromium en dernier recours : il rend bien, mais ne sait poser aucun
    // pied de page en ligne de commande — le document sortirait sans identité.
    $ch = '--headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
        . ' --no-first-run --no-default-browser-check --no-pdf-header-footer'
        . ' --user-data-dir=' . escapeshellarg($profil);
    $essais = [
        // La version CORRIGÉE d'abord, à son chemin d'installation : elle
        // seule répète le pied de page, et elle est headless (pas de xvfb).
        // Le PATH d'un serveur web ne contient pas toujours /usr/local/bin,
        // d'où le chemin en clair.
        'timeout 25 /usr/local/bin/wkhtmltopdf ' . $wk . ' %1$s %2$s 2>&1',
        'timeout 25 wkhtmltopdf ' . $wk . ' %1$s %2$s 2>&1',
        // Le build des dépôts n'est pas headless : xvfb-run pour lui.
        'timeout 25 xvfb-run -a wkhtmltopdf ' . $wk . ' %1$s %2$s 2>&1',
        'timeout 25 chromium ' . $ch . ' --print-to-pdf=%2$s %1$s 2>&1',
        'timeout 25 chromium-browser ' . $ch . ' --print-to-pdf=%2$s %1$s 2>&1',
        'timeout 25 google-chrome ' . $ch . ' --print-to-pdf=%2$s %1$s 2>&1',
    ];
    foreach ($essais as $cmd) {
        @shell_exec(sprintf($cmd, escapeshellarg($tmpH), escapeshellarg($tmpP)));
        if (is_file($tmpP) && filesize($tmpP) > 1000) {
            $octets = (string) file_get_contents($tmpP);
            rapNettoyer($tmpH, $tmpP, $profil, $tmpF);
            return $octets;
        }
    }
    rapNettoyer($tmpH, $tmpP, $profil, $tmpF);
    return null;
}

/** Les fichiers de travail d'un rendu — dont le profil jetable de Chromium. */
function rapNettoyer(string $tmpH, string $tmpP, string $profil, ?string $tmpF = null): void
{
    @unlink($tmpH); @unlink($tmpP);
    if ($tmpF !== null) { @unlink($tmpF); }
    if (!is_dir($profil)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($profil, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($profil);
}

/**
 * Le bilan d'une période pour UN magasin : les comptes, la dispersion des
 * cotes, et les tâches exemplaires.
 *
 * Tout en tables et styles en ligne : c'est la seule mise en page qu'un client
 * mail respecte, et le PDF part du même HTML. Les barres sont des cellules de
 * tableau — ni SVG ni CSS externe, que Gmail retirerait.
 */
function rapBilanHtml(int $demandees, int $rendues, int $notees, int $sansPhoto,
                      ?float $moyenne, array $dist, array $exemplaires, array $niveaux,
                      array $fichesExemplaires = [], int $colonnes = 2, int $rangees = 3,
                      array $parTache = [], array $joursPeriode = []): string
{
    $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $pct = fn (int $n) => $demandees > 0 ? round($n / $demandees * 100) : 0;
    $nonRendues = max(0, $demandees - $rendues);

    // --- les comptes, en quatre cases lisibles d'un coup d'œil
    $cases = [
        ['Demandées', (string) $demandees, ''],
        ['Rendues', (string) $rendues, $pct($rendues) . ' %'],
        ['Non rendues', (string) $nonRendues, $nonRendues > 0 ? $pct($nonRendues) . ' %' : '—'],
        ['Note moyenne', $moyenne === null ? '—' : str_replace('.', ',', (string) $moyenne) . '/5',
            $notees . ' notée' . ($notees > 1 ? 's' : '')],
    ];
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 10px"><tr>';
    foreach ($cases as [$lib, $val, $sous]) {
        $h .= '<td width="25%" valign="top" style="padding:0 5px 0 0">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F3EC;border-radius:9px;height:62px">'
            // Hauteur imposée ET troisième ligne toujours présente : sans
            // elles, la case « Demandées » — qui n'a rien à mettre dessous —
            // était plus courte que ses voisines, et la rangée bancale.
            . '<tr><td valign="top" height="62" style="height:62px;padding:9px 11px;' . $F . '">'
            . '<div style="font-size:9.5px;letter-spacing:1px;text-transform:uppercase;color:#8b8177">' . $e($lib) . '</div>'
            . '<div style="font-size:19px;font-weight:700;color:#221E1A;line-height:1.2">' . $e($val) . '</div>'
            . '<div style="font-size:10.5px;color:#8b8177">' . ($sous !== '' ? $e($sous) : '&nbsp;') . '</div>'
            . '</td></tr></table></td>';
    }
    $h .= '</tr></table>';
    if ($sansPhoto > 0) {
        $h .= '<div style="' . $F . ';font-size:11px;color:#8b8177;margin:-4px 0 10px">Dont ' . $sansPhoto
            . ' tâche(s) rendue(s) sans photo.</div>';
    }

    // --- la dispersion des cotes : une barre par niveau, à sa couleur
    if ($notees > 0) {
        $h .= '<div style="' . $F . ';font-size:11px;font-weight:700;color:#221E1A;margin:14px 0 6px">Dispersion des cotes</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        foreach ($niveaux as $nv) {
            $n = (int) ($nv['n'] ?? 0);
            $nb = (int) ($dist[$n] ?? 0);
            $part = $notees > 0 ? $nb / $notees : 0;
            // Une part non nulle garde au moins un filet visible : à 2 % sur
            // 300 px, une barre d'un pixel se confond avec l'absence de barre.
            $largeur = $nb === 0 ? 0 : max(2, round($part * 100));
            $coul = (string) ($nv['couleur'] ?? '#8b8177');
            $h .= '<tr>'
                . '<td width="26" style="' . $F . ';font-size:11.5px;font-weight:700;color:' . $coul . ';padding:2px 0">' . $n . '/5</td>'
                . '<td width="150" style="' . $F . ';font-size:10.5px;color:#6E645A;padding:2px 8px 2px 0">' . $e($nv['nom'] ?? '') . '</td>'
                . '<td style="padding:2px 0">'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F0EAE1;border-radius:999px"><tr>'
                . ($largeur > 0
                    ? '<td width="' . $largeur . '%" style="background:' . $coul . ';border-radius:999px;font-size:0;line-height:0;height:9px">&nbsp;</td>'
                    : '')
                . '<td style="font-size:0;line-height:0;height:9px">&nbsp;</td></tr></table></td>'
                . '<td width="64" align="right" style="' . $F . ';font-size:11px;color:#221E1A;padding:2px 0 2px 8px">'
                . $nb . ($nb > 0 ? ' <span style="color:#8b8177">· ' . round($part * 100) . ' %</span>' : '')
                . '</td></tr>';
        }
        $h .= '</table>';
    }

    // --- le détail par tâche : les notes de chaque passage, et la moyenne
    //
    // Deux ou trois colonnes selon le nombre de tâches : une seule colonne
    // ferait trois pages de lignes courtes, quatre rendraient les pastilles
    // illisibles.
    if ($parTache !== []) {
        $lignes = [];
        foreach ($parTache as $nom => $notesParJour) {
            ksort($notesParJour);
            // Une case par JOUR de la période — grise les jours sans tâche.
            // Sans elles, deux tâches notées 4 et 4 se ressemblent, qu'elles
            // aient été faites deux fois ou sept fois dans la semaine.
            $cases = [];
            foreach (($joursPeriode !== [] ? $joursPeriode : array_keys($notesParJour)) as $j) {
                $cases[] = ['date' => $j, 'note' => $notesParJour[$j] ?? null];
            }
            $notes = array_values($notesParJour);
            $lignes[] = ['nom' => $nom, 'cases' => $cases,
                'moy' => $notes === [] ? null : array_sum($notes) / count($notes)];
        }
        // La plus basse d'abord : on lit ce qu'il y a à reprendre avant ce qui
        // tient déjà.
        usort($lignes, fn ($a, $b2) => ($a['moy'] ?? 9) <=> ($b2['moy'] ?? 9));
        $cols = count($lignes) > 10 ? 3 : 2;
        $h .= '<div style="' . $F . ';font-size:11px;font-weight:700;color:#221E1A;margin:18px 0 6px">Détail par tâche</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        foreach (array_chunk($lignes, $cols) as $rangee) {
            $h .= '<tr>';
            for ($i = 0; $i < $cols; $i++) {
                $l = $rangee[$i] ?? null;
                $h .= '<td valign="top" width="' . round(100 / $cols, 2) . '%" style="padding:0 '
                    . ($i === $cols - 1 ? '0' : '8px') . ' 6px 0">';
                if ($l !== null) {
                    $pastilles = '';
                    foreach ($l['cases'] as $ca) {
                        $vide = $ca['note'] === null;
                        $pastilles .= '<span title="' . $e(substr($ca['date'], 8, 2) . '/' . substr($ca['date'], 5, 2)) . '"'
                            . ' style="display:inline-block;min-width:13px;text-align:center;background:'
                            . ($vide ? '#E4DCD0' : rapCouleurNote($ca['note']))
                            . ';color:' . ($vide ? '#B4A99A' : '#ffffff')
                            . ';border-radius:4px;padding:1px 4px;margin-right:2px;font-size:9px;font-weight:700">'
                            . ($vide ? '·' : $ca['note']) . '</span>';
                    }
                    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F7F3EC;border-radius:8px">'
                        . '<tr><td style="padding:6px 9px;' . $F . '">'
                        . '<div style="font-size:10px;font-weight:700;color:#221E1A;line-height:1.25">' . $e($l['nom']) . '</div>'
                        . '<div style="margin-top:3px;white-space:nowrap">' . $pastilles
                        . '<span style="font-size:10px;color:#4a443c;font-weight:700;margin-left:4px">'
                        . ($l['moy'] === null ? '—' : str_replace('.', ',', (string) round($l['moy'], 1)) . ' / 5') . '</span></div>'
                        . '</td></tr></table>';
                } else {
                    $h .= '&nbsp;';
                }
                $h .= '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
    }

    // --- ce qui a été fait de mieux
    //
    // Les vignettes disent déjà le nom, la date et la note : les relister
    // au-dessus doublait l'encadré pour rien. Ne restent en texte que les
    // tâches SANS photo, et une ligne ferme le bloc — la seule séparation
    // avec les non-conformités qui suivent, sans saut de page.
    if ($exemplaires !== []) {
        $nommees = [];
        foreach ($exemplaires as $t) {
            $nommees[] = $e($t['tache'] ?? 'Tâche') . ' (le '
                . $e(substr((string) ($t['date'] ?? ''), 8, 2) . '/' . substr((string) ($t['date'] ?? ''), 5, 2)) . ')';
        }
        $reste = array_slice($nommees, count($fichesExemplaires));
        // Présentées comme les écarts : un titre, puis la grille. Le cadre
        // doré autour n'apportait rien — la teinte des cartes dit déjà de
        // quoi il s'agit. La table ne sert plus qu'à porter le saut de page.
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0 6px" data-encadre="5sur5">'
            . '<tr><td style="' . $F . '">'
            . '<div style="font-size:14.5px;font-weight:700;color:#221E1A;margin:0 0 6px">Cette semaine, vous vous êtes distingué avec celles-ci'
            . '<span style="font-weight:400;font-size:12px;color:#8b8177"> — ' . count($exemplaires) . ' tâche(s) à 5/5</span></div>'
            . ($fichesExemplaires !== []
                ? rapFichesGrille($fichesExemplaires, $colonnes, $rangees, false)
                : '')
            . ($reste !== []
                ? '<div style="font-size:11px;color:#4a443c;padding-top:' . ($fichesExemplaires !== [] ? '4' : '6') . 'px;line-height:1.45">'
                    . ($fichesExemplaires !== [] ? 'Aussi : ' : '') . implode(' &middot; ', $reste) . '</div>'
                : '')
            . '</td></tr></table>'
            . '<div style="border-top:1px solid #E4DCD0;margin:2px 0 10px"></div>';
    }
    return $h;
}

/** Un nom de fichier lisible : « rapport-non-conformites-2026-08-21.pdf ». */
function rapPdfNom(array $rep, array $run): string
{
    $nom = (string) ($rep['nom'] ?? 'rapport');
    $nom = @iconv('UTF-8', 'ASCII//TRANSLIT', $nom) ?: $nom;
    $nom = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $nom), '-');
    $jour = substr((string) ($run['genere_le'] ?? date('Y-m-d')), 0, 10);
    return strtolower(($nom !== '' ? $nom : 'rapport') . '-' . $jour) . '.pdf';
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
function rapFicheTache(string $shopId, string $taskId, string $date, string $nomTache, string $magasin, ?int $note = null, string $commentaire = '', bool $compact = false): string
{
    $det = rapCtx('tache-' . $shopId . '-' . $taskId . '-' . $date,
        fn () => rapAppel('ep_pwa_task_detail', ['shop' => $shopId, 'task' => $taskId, 'date' => $date]));
    if (!is_array($det)) { return ''; }
    $e = fn ($s2) => htmlspecialchars((string) $s2, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $reperes = (array) ((($det['reperes'] ?? [])['liste']) ?? []);

    $imgs = '';
    $legende = fn (string $t) => '<div style="' . $F . ';font-size:9.5px;color:#8b8177;margin:2px 0 6px">' . $t . '</div>';
    $photo = rapImageGd((string) ($det['photo'] ?? ''), 420);
    $imgPhoto = null;
    if ($photo !== null) {
        if ($reperes !== []) { rapDessineReperes($photo, $reperes); }
        $imgPhoto = '<img src="' . rapImageDataUri($photo) . '" width="100%" style="display:block;width:100%;border-radius:7px" alt="Photo en boutique">';
    }
    // La photo de RÉFÉRENCE ne suit qu'en grande carte : en vignette, deux
    // images dans un cadre de six centimètres n'en montrent aucune.
    $ref = $compact ? null : rapImageGd((string) ($det['photoRef'] ?? ''), 420);
    $imgRef = $ref !== null
        ? '<img src="' . rapImageDataUri($ref) . '" width="100%" style="display:block;width:100%;border-radius:7px" alt="Référence attendue">'
        : null;
    $legPhoto = 'Photo en boutique' . ($reperes !== [] ? ' — ' . count($reperes) . ' repère(s)' : '');
    $legRef   = 'Référence attendue' . (!empty($det['produit']) ? ' — ' . $e($det['produit']) : '');
    if ($imgPhoto !== null && $imgRef !== null) {
        // CÔTE À CÔTE — un contrôle se juge par comparaison et la mise en
        // page le dit : boutique à gauche, référence à droite, mêmes coins,
        // légendes alignées sous chaque image, hauts alignés. Un TABLEAU,
        // pas de flex : ce HTML finit en e-mail et en PDF, où seules les
        // tables tiennent partout.
        $imgs = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr>'
            . '<td width="50%" valign="top" style="padding:0 4px 0 0">' . $imgPhoto . $legende($legPhoto) . '</td>'
            . '<td width="50%" valign="top" style="padding:0 0 0 4px">' . $imgRef . $legende($legRef) . '</td>'
            . '</tr></table>';
    } else {
        // Une seule image : pleine largeur, comme avant. En vignette, la
        // légende de la photo mange la place de la photo : douze par page,
        // chaque millimètre compte.
        if ($imgPhoto !== null) { $imgs .= $imgPhoto . ($compact ? '' : $legende($legPhoto)); }
        if ($imgRef !== null) { $imgs .= $imgRef . $legende($legRef); }
    }
    if ($imgs === '' && $reperes === []) { return ''; }

    $exp = '';
    if ($note !== null) {
        // La couleur de la note suit les niveaux de validation : doré pour
        // l'exemplaire, vert pour le conforme — une fiche 5/5 ne doit pas
        // porter la couleur d'un écart.
        $coulN = rapCouleurNote($note);
        $exp .= '<div style="' . $F . ';font-size:11px;color:#221E1A;padding:1px 0">'
            . '<span style="color:' . $coulN . ';font-size:9px;line-height:1">&#9679;</span> '
            . '<b style="color:' . $coulN . '">Note ' . $note . '/5</b> · le ' . $e(date('d/m', strtotime($date ?: 'today'))) . '</div>';
    }
    // Le commentaire du panel EST la concaténation des repères : « 1. [mineur]
    // Il serait pas mieux au mur ? » puis, juste dessous, le repère numéroté
    // qui dit la même chose. On ne garde que les repères — ceux qui portent le
    // numéro en couleur et renvoient au cadre dessiné sur la photo. Le
    // commentaire ne s'affiche donc que s'il est SEUL à parler.
    $reperesParlants = array_filter($reperes, fn ($r) => trim((string) ($r['txt'] ?? '')) !== '');
    if (trim($commentaire) !== '' && $reperesParlants === []) {
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
    // En vignette : la PHOTO d'abord, aussi grande que le cadre le permet, le
    // titre et la note dessous. En grande carte, le titre reste en tête.
    $titre = '<div style="' . $F . ';font-size:' . ($compact ? '10px' : '12px')
        . ';font-weight:700;color:#221E1A;margin:' . ($compact ? '5px 0 1px' : '0 0 5px') . '">' . $e($nomTache) . '</div>';
    return $compact ? $imgs . $titre . $exp : $titre . $imgs . $exp;
}

/** Range les cartes deux par rangée — la grille qui tient sur un A4. */
/** Le fond et le filet d'une fiche, selon la note — les couleurs du
 *  référentiel de validation, éclaircies pour rester lisibles sous le texte. */
function rapCouleurNote(?int $note): string
{
    return [5 => '#C9A227', 4 => '#2D7A3E', 3 => '#D97706', 2 => '#C0182B', 1 => '#8D1D2C'][$note] ?? '#8b8177';
}

/**
 * Le fond d'une fiche selon sa note — teinté pour les écarts, BLANC dans
 * l'encadré des 5/5 : le doré y est déjà celui de l'encadré, deux fonds l'un
 * sur l'autre ne disent rien de plus. Le niveau se lit au point de couleur
 * devant la note, pas à une arête sur tout le côté.
 */
function rapFondNote(?int $note, bool $surFondTeinte = false): array
{
    if ($surFondTeinte) { return ['fond' => '#FFFFFF']; }
    return ['fond' => [5 => '#FBF6E7', 4 => '#EFF6F0', 3 => '#FDF2E5',
        2 => '#FBEBED', 1 => '#F7E4E7'][$note] ?? '#FBF9F5'];
}

function rapFichesGrille(array $cartes, int $colonnes = 2, int $rangees = 3, bool $paginer = true): string
{
    // Une PAGE A4 = colonnes × rangées fiches, toutes de la même taille. Un
    // rapport du JOUR porte deux ou trois écarts : de grandes cartes (2 × 3).
    // Un rapport de la SEMAINE en porte vingt : des vignettes (3 × 4), sinon
    // il fait huit pages. Les fiches partent donc par paquets d'une page, un
    // paquet par table — le PDF pose le saut de page entre elles.
    $colonnes = max(1, min(4, $colonnes));
    $rangees = max(1, min(6, $rangees));
    $largeur = round(100 / $colonnes, 4);
    $h = '';
    // `$paginer` à faux : une seule table, aucun saut de page — pour une grille
    // POSÉE DANS un encadré (les tâches exemplaires), où un saut couperait
    // l'encadré en deux.
    foreach (array_chunk($cartes, $paginer ? $colonnes * $rangees : max(1, count($cartes))) as $page) {
        // Le nombre de rangées RÉELLES de cette page : une page qui n'en porte
        // que trois étire ses cartes au lieu de laisser un tiers de blanc.
        $rangsPage = (int) ceil(count($page) / $colonnes);
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" data-fiches="1"'
            . ($paginer ? ' data-page-fiches="1"' : '') . ' data-grille="' . $colonnes . 'x' . $rangees . '"'
            . ' data-rangees="' . $rangsPage . '">';
        $rangs = array_chunk($page, $colonnes);
        foreach ($rangs as $rangee) {
            $h .= '<tr data-rangee-fiches="1">';
            for ($i = 0; $i < $colonnes; $i++) {
                $pad = 'padding:5px ' . ($i === $colonnes - 1 ? '0' : '6px') . ' 5px ' . ($i === 0 ? '0' : '6px');
                // Une carte est soit du HTML nu, soit du HTML AVEC sa couleur :
                // le fond dit le niveau (doré pour l'exemplaire, du orange au
                // bordeaux pour les écarts) sans qu'on ait à lire la note.
                $carte = $rangee[$i] ?? null;
                $html = is_array($carte) ? (string) ($carte['html'] ?? '') : (string) $carte;
                $fond = is_array($carte) ? (string) ($carte['fond'] ?? '#FBF9F5') : '#FBF9F5';
                $h .= '<td valign="top" width="' . $largeur . '%" style="' . $pad . '">'
                    . ($carte !== null
                        ? '<div data-fiche="1" style="background:' . $fond . ';border-radius:10px;padding:10px 12px">' . $html . '</div>'
                        : '&nbsp;')
                    . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
    }
    return $h;
}

/**
 * La grille qui convient à la période : de grandes cartes pour une journée,
 * des vignettes dès qu'on couvre plusieurs jours (semaine, mois).
 */
/**
 * Les jours d'une période, du premier au dernier — au plus quatorze.
 *
 * Le détail par tâche pose une case PAR JOUR : sur une semaine, les sept
 * cases se lisent d'un coup et les jours sans tâche restent gris, ce qui dit
 * autant que les jours notés. Au-delà de deux semaines, une case par jour
 * deviendrait une frise illisible : seuls les jours notés s'affichent alors.
 */
function rapJoursDe(array $periode): array
{
    $du = new DateTimeImmutable((string) $periode['du']);
    $au = new DateTimeImmutable((string) $periode['au']);
    $n = $du->diff($au)->days + 1;
    if ($n > 14) { return []; }
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = $du->modify('+' . $i . ' days')->format('Y-m-d'); }
    return $out;
}

/**
 * La hauteur des vignettes, selon ce que la page porte VRAIMENT.
 *
 * Une A4 laisse ~240 mm au-dessous du titre du bloc. À cinq rangées, chacune
 * en prend 48 : la page est pleine. Mais une page de onze photos n'a que
 * quatre rangées — figées à 54 mm, elles s'arrêtaient aux deux tiers de la
 * feuille. Chaque page étire donc ses cartes jusqu'à remplir les 240 mm,
 * plafonnées à 78 mm : au-delà, la photo ne grandit plus (elle est bornée par
 * la largeur de la colonne) et on n'étirerait que du vide.
 */
function rapHauteursVignettes(): string
{
    $css = '';
    // Le compte : 297 mm moins les marges (14 en haut, 20 en bas — le pied de
    // page se loge dans celle du bas) = 263 mm, moins le titre du bloc et le
    // nom du magasin (~15 mm) = ~248 mm pour la grille. Chaque rangée porte en
    // plus ~2,6 mm de gouttière (5 px de padding en haut et en bas), d'où des
    // hauteurs de cellule un peu inférieures au quotient : sans cela, la
    // dernière rangée basculait sur une page presque vide.
    foreach ([5 => [47, 31], 4 => [59, 42], 3 => [78, 56], 2 => [78, 56], 1 => [78, 56]] as $rangs => [$h, $photo]) {
        $t = 'table[data-grille="3x5"][data-rangees="' . $rangs . '"] ';
        $css .= $t . 'tr[data-rangee-fiches]>td{height:' . $h . 'mm}'
            . $t . 'div[data-fiche]{height:' . ($h - 2) . 'mm}'
            . $t . 'div[data-fiche] img{max-height:' . $photo . 'mm}';
    }
    // Une grille sans compte de rangées (rapport ancien, test) : la valeur qui
    // remplit une page pleine.
    return $css . 'table[data-grille="3x5"] tr[data-rangee-fiches]>td{height:47mm}'
        . 'table[data-grille="3x5"] div[data-fiche]{height:45mm}'
        . 'table[data-grille="3x5"] div[data-fiche] img{max-height:31mm}';
}

function rapGrilleDe(array $periode): array
{
    $jours = 1 + (int) round((strtotime((string) $periode['au']) - strtotime((string) $periode['du'])) / 86400);
    return $jours >= 2 ? [3, 5] : [2, 3];
}

/* --- Compositeur : aperçu à la demande et enregistrement d'un modèle --------- */

/** Le pseudo-rapport d'une composition envoyée par l'écran. */
/**
 * La mise en page voulue : l'ordre des blocs, et ceux qui ouvrent une page.
 *
 * Deux règles ne se négocient pas, quel que soit l'ordre demandé : les photos
 * des tâches ouvrent toujours une page (quinze vignettes ne se coincent pas en
 * bas d'un tableau), et le bilan des tâches reste collé devant elles — un KPI
 * chiffré glissé entre les deux couperait la lecture en son milieu.
 */
function rapMiseEnPageDe($brut): array
{
    $m = is_array($brut) ? $brut : (json_decode((string) $brut, true) ?: []);
    $defs = rapBlocDefs();
    $filtre = fn ($v) => array_values(array_filter(array_map('strval', (array) $v), fn ($sl) => isset($defs[$sl])));
    return ['ordre' => $filtre($m['ordre'] ?? []), 'sauts' => $filtre($m['sauts'] ?? [])];
}

/** Les blocs qui ouvrent une page quoi qu'il arrive. */
function rapSautsForces(): array
{
    return ['xp-bilan', 'xp-taches'];
}

function rapCompositionRep(array $b): array
{
    $blocs = array_values(array_filter((array) ($b['blocs'] ?? []), fn ($s) => isset(rapBlocDefs()[$s])));
    // Une composition RATTACHÉE à un rapport enregistré (on l'édite, ou on
    // l'a chargée comme modèle) : ses générations se rangent sous SON nom
    // dans l'historique, au lieu d'aller grossir « Aperçu à la demande ».
    // C'est le seul moyen de retrouver ce qui a été essayé sur un rapport.
    $lie = (int) ($b['rapportId'] ?? 0);
    $ref = $lie > 0 ? Db::row('SELECT id, nom, poste FROM ceo_rapport WHERE id = ?', [$lie]) : null;
    return [
        'id' => $ref !== null ? (int) $ref['id'] : 0,
        'code' => $ref !== null ? 'apercu-' . (int) $ref['id'] : 'apercu',
        'nom' => trim((string) ($b['nom'] ?? '')) ?: (string) ($ref['nom'] ?? 'Rapport à la demande'),
        'poste' => trim((string) ($b['poste'] ?? '')) ?: (string) ($ref['poste'] ?? 'À la demande'),
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
        // Le repère de comparaison : A-1 par défaut, le seul qui neutralise la
        // saison. Une valeur inconnue retombe dessus plutôt que d'être crue.
        'comparaison' => isset(RAP_COMPARAISONS[(string) ($b['comparaison'] ?? '')]) ? (string) $b['comparaison'] : 'A-1',
        'mise_en_page' => json_encode(rapMiseEnPageDe(['ordre' => $b['ordre'] ?? [], 'sauts' => $b['sauts'] ?? []])),
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
                                       jours, magasins, periode, periode_du, periode_au, modes, envoi_mode, dest_par_magasin, comparaison,
                                       mise_en_page)
              VALUES (?,?,?,?,?,?,?,?,0,1,?,?,?,?,?,?,?,?,?,?)',
        [$code, $rep['nom'], $rep['poste'], $freq, $heure,
         $jours['dows'][0] ?? ($jours['doms'][0] ?? 1),
         $rep['blocs'], $rep['destinataires'],
         json_encode($jours), $rep['magasins'], $rep['periode'], $rep['periode_du'], $rep['periode_au'], $rep['modes'],
         $envoiMode, json_encode($carnet, JSON_UNESCAPED_UNICODE), $rep['comparaison'], $rep['mise_en_page']]);
    journalAdd('CEO', 'Rapport', $rep['nom'], 'Rapport composé enregistré (' . $code . ')');
    return ['ok' => true, 'code' => $code,
        'planifie' => $jours['dows'] !== [] || $jours['doms'] !== [],
        'note' => $jours['dows'] === [] && $jours['doms'] === [] ? 'aucun jour coché — rapport à la demande, le cron ne l\'enverra pas' : null];
}
