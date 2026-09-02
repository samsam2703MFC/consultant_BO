<?php
declare(strict_types=1);

/**
 * Cockpit CEO — endpoints de lecture (GET), un par écran + référentiels.
 * Formes JSON : voir contrat-api.md. Aucune donnée métier dans le HTML :
 * tout ce que le front affiche sort d'ici.
 */

/** Correspondance slug levier ↔ of_tag.id (le nom/type/desc sont applicatifs, la couleur vient d'of_tag). */
const LEVIER_DEFS = [
    ['slug' => 'trafic',        'nom' => 'Trafic',            'type' => 'Vente', 'tag' => 4, 'desc' => 'Faire venir plus de monde : visibilité locale, vitrine, animations, signalétique.'],
    ['slug' => 'recurrence',    'nom' => 'Récurrence',        'type' => 'Vente', 'tag' => 3, 'desc' => 'Faire revenir les clients : fidélisation PWA, qualité constante, loyalty, suivi B2B.'],
    ['slug' => 'xp',            'nom' => 'Expérience Client', 'type' => 'Vente', 'tag' => 2, 'desc' => 'Qualité du moment en boutique : accueil < 3 s, conseil, ambiance, rapidité.'],
    ['slug' => 'food-cost',     'nom' => 'Food Cost',         'type' => 'Coût',  'tag' => 5, 'desc' => 'Coût matière : recettes, contrôle réception ProdAtelier, FIFO, casse & invendus.'],
    ['slug' => 'labour-cost',   'nom' => 'Labour Cost',       'type' => 'Coût',  'tag' => 6, 'desc' => 'Coût main d\'œuvre : plannings au flux, productivité, ratio CA/ETP, polyvalence.'],
    ['slug' => 'overhead-cost', 'nom' => 'Overhead Cost',     'type' => 'Coût',  'tag' => 7, 'desc' => 'Charges fixes : loyer, énergies, abonnements, assurances, maintenance.'],
];

function levierSlugByTag(): array
{
    $out = [];
    foreach (LEVIER_DEFS as $l) { $out[$l['tag']] = $l['slug']; }
    return $out;
}

function setting(string $key, mixed $default = null): mixed
{
    $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]);
    return $r === null ? $default : json_decode($r['value'], true);
}

/**
 * L'identifiant CONSULTANT du compte connecté.
 *
 * Le cockpit connaît son utilisateur par son nom (« Sam Verheyden ») ; les
 * tâches, elles, sont portées par un identifiant (« u6 »). Rapprocher les deux
 * par le nom serait fragile — « Sam V. » côté panel, « Sam Verheyden » côté
 * réglage. La route `/consultant/tasks` rend la POSITION du compte qui
 * interroge : son `membership_id` EST cet identifiant, sans configuration.
 *
 * Mémorisé pour la journée : c'est une identité, elle ne change pas d'une
 * heure à l'autre, et l'écran d'accueil ne doit pas attendre le panel.
 */
function consultantIdCompte(): ?string
{
    $memo = setting('consultantCompte', null);
    $connu = is_array($memo) ? ($memo['id'] ?? null) : null;
    if ($connu !== null && (string) ($memo['le'] ?? '') === date('Y-m-d')) { return (string) $connu; }
    if (!PanelApi::configured()) { return $connu !== null ? (string) $connu : null; }
    $r = PanelApi::get('/consultant/tasks');
    $mid = is_array($r) ? (($r['position']['membership_id'] ?? null)) : null;
    if ($mid === null || !is_numeric($mid)) { return $connu !== null ? (string) $connu : null; }
    $id = 'u' . (int) $mid;
    try {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['consultantCompte', json_encode(['id' => $id, 'le' => date('Y-m-d')])]);
    } catch (PDOException $e) { /* mémorisation best-effort */ }
    return $id;
}

/**
 * L'identifiant que le PANEL attend pour écrire une note : l'`auth_user_id` du
 * compte, pas son `membership_id`. Mesuré : les deux existent et diffèrent
 * (6 et 104), et la route n'accepte que le second.
 */
function consultantAuthId(): ?int
{
    $memo = setting('consultantAuthId', null);
    if (is_numeric($memo)) { return (int) $memo; }
    $uid = consultantIdCompte();                       // « u6 »
    if ($uid === null || !preg_match('/^u(\d+)$/', $uid, $m)) { return null; }
    try {
        $r = Db::row('SELECT auth_user_id FROM user_membership WHERE id = ?', [(int) $m[1]]);
    } catch (PDOException $e) { return null; }
    if ($r === null || !is_numeric($r['auth_user_id'])) { return null; }
    $id = (int) $r['auth_user_id'];
    try {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['consultantAuthId', json_encode($id)]);
    } catch (PDOException $e) { /* mémorisation best-effort */ }
    return $id;
}

function ep_meta(): array
{
    // Horodatage du JS RÉELLEMENT déployé. « Je ne le vois pas en ligne » est
    // une question qu'on ne doit pas avoir à trancher en tâtonnant : si l'écran
    // affiche une version plus ancienne que la livraison, c'est le cache du
    // navigateur, pas le serveur. On lit le fichier servi, pas une constante
    // qu'on aurait pu oublier de mettre à jour.
    $js = __DIR__ . '/../public/assets/js/app.js';
    $build = is_file($js) ? date('d/m H:i', (int) filemtime($js)) : null;
    $seuils = [];
    foreach (Db::rows("SELECT code, seuil_bas, seuil_haut FROM kpi WHERE code IS NOT NULL") as $k) {
        $seuils[$k['code']] = $k['seuil_haut'] !== null ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
    }
    return [
        'build'            => $build,
        'reseau'           => setting('reseau', ['nom' => '', 'sousTitre' => '']),
        // L'utilisateur, et — quand le compte API en est un — l'identifiant
        // consultant qui lui correspond : c'est lui qui dit « mes tâches ».
        'utilisateur'      => setting('utilisateur', ['initiales' => '', 'nom' => '', 'role' => ''])
                              + ['consultantId' => consultantIdCompte()],
        // « Aujourd'hui » pilote la logique de dates (défaut du planning d'un
        // nouveau projet, jalons/tâches en retard). Sans réglage explicite, on
        // prend la date réelle du serveur — jamais null (sinon les comparaisons
        // de dates côté écran deviennent fausses) et jamais figée.
        'aujourdhui'       => setting('aujourdhui', date('Y-m-d')),
        'dateLabel'        => setting('dateLabel', ''),
        'periodeLabel'     => setting('periodeLabel', ''),
        'exercice'         => (int) setting('exercice', (int) date('Y')),
        'moisLabels'       => setting('moisLabels', []),
        'seuils'           => [
            'food'        => $seuils['food'] ?? 32,
            'labour'      => $seuils['labour'] ?? 33,
            'overhead'    => $seuils['overhead'] ?? 13.5,
            'royalties'   => $seuils['royalties'] ?? 3,
            'financieres' => $seuils['financieres'] ?? 2.2,
            'caEtp'       => $seuils['ca_etp'] ?? 13000,
        ],
        // Période réellement servie par le scoring produit (le backend replie
        // sur le dernier mois de caisse encodé) — la modale « perte par
        // magasin » doit interroger la MÊME fenêtre, sinon les deux chiffres
        // se contredisent sans que rien ne le signale.
        'periodeProduits'  => setting('periodeProduits', date('Y-m')),
        'contribOuverture' => setting('contribOuverture', 0),
        'notes'            => setting('notes', new stdClass()),
        'familles'         => setting('familles', []),
        // Pondération et seuils du scoring produit — réglage, jamais une
        // constante d'écran : le score qui décide de retirer une référence
        // doit pouvoir se discuter et s'ajuster sans déploiement.
        'scoring'          => setting('scoring', ['poids' => ['volume' => 40, 'marge' => 30, 'perte' => 20, 'comptoir' => 10],
                                                  'seuils' => ['moteur' => 68, 'conforter' => 46],
                                                  'marge' => ['bas' => 20, 'basNote' => 20, 'haut' => 80, 'hautNote' => 100]]),
        'reportTypes'      => setting('reportTypes', []),
        // Validation des tâches : une seule source pour les cinq niveaux, le
        // seuil et le référentiel famille → type. Rien n'est recopié dans le
        // JavaScript, sinon les deux se mettent à diverger en silence.
        'signalement'      => setting('signalement', [
            'seuil'    => 4,
            'niveaux'  => [],
            'familles' => [],
        ]),
    ];
}

function ep_leviers(): array
{
    $colors = [];
    foreach (Db::rows('SELECT id, color FROM of_tag') as $t) { $colors[(int) $t['id']] = $t['color']; }
    return array_map(fn ($l) => [
        'slug' => $l['slug'], 'nom' => $l['nom'], 'type' => $l['type'],
        'color' => $colors[$l['tag']] ?? '#666666', 'desc' => $l['desc'],
    ], LEVIER_DEFS);
}

function ep_kpis(): array
{
    return array_map(fn ($r) => ['nom' => $r['name']], Db::rows('SELECT name FROM kpi WHERE code IS NULL ORDER BY id'));
}

function ep_email_templates(): array
{
    return array_map(fn ($r) => ['id' => $r['id'], 'nom' => $r['name'], 'sujet' => $r['subject'], 'corps' => $r['body']],
        Db::rows('SELECT * FROM ceo_email_template ORDER BY id'));
}

function ep_project_templates(): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_project_template') as $r) {
        $out[$r['axe']] = ['jalons' => json_decode($r['jalons_json'], true), 'couts' => json_decode($r['couts_json'], true)];
    }
    return $out;
}

function ep_stores(): array
{
    // Vraies boutiques du panel (table partagée `shops` d'atelierby_db) — même
    // source que le panel consultant. Repli sur `ceo_shop` si la table partagée
    // est absente (installation autonome / démo).
    try {
        $rows = Db::rows('SELECT id, slug, name, legal_name, city, zone, region, active, kind,
                                 is_franchise, operator, contrat, since_year, sort_order
                          FROM shops ORDER BY sort_order, id');
        return array_map(fn ($r) => [
            'id'     => (string) $r['id'],
            'code'   => $r['slug'] !== null ? strtoupper((string) $r['slug']) : (string) $r['id'],
            'nom'    => $r['name'],
            'fr'     => $r['operator'] ?: ($r['legal_name'] ?: ''),
            'zone'   => $r['zone'] ?: ($r['region'] ?: $r['city']),
            'status' => ((int) $r['active'] === 1) ? 'Ouvert' : 'Fermé',
            'opened' => $r['since_year'] ? sprintf('%04d-01', (int) $r['since_year']) : null,
            // La valorisation n'est calculée ni ici ni en amont : elle est
            // déclarée en lacune plutôt que rendue par un null muet.
            'valT'   => null,
            // Le panier moyen n'est PAS un manque : /stores/perf le rend. Le
            // laisser nul ici est correct ; le déclarer manquant serait faux.
            'panier' => null,
            'pwaId'  => (int) $r['id'], // la boutique du panel EST le pwa_shop_id
        ], $rows);
    } catch (PDOException $e) {
        return array_map(fn ($r) => [
            'id' => $r['id'], 'code' => $r['code'], 'nom' => $r['name'], 'fr' => $r['franchisee'],
            'zone' => $r['zone'], 'status' => $r['status'],
            'opened' => $r['opened_on'] ? substr($r['opened_on'], 0, 7) : null,
            'valT' => $r['valuation_target'] !== null ? (float) $r['valuation_target'] : null,
            'panier' => $r['basket_ref'] !== null ? (float) $r['basket_ref'] : null,
            'pwaId' => isset($r['pwa_shop_id']) && $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null,
        ], Db::rows('SELECT * FROM ceo_shop ORDER BY id'));
    }
}

/**
 * GET /pwa/reports — rapports du panel consultant (pwa_consultant).
 *
 * Deux volets :
 *  - `base` + `magasins[].pwaId` : de quoi construire côté client les liens de
 *    GÉNÉRATION (`/reports/view?type=week|month&scope=all|{id}`,
 *    `/reports/checklist/week|month?scope={id}`) — le rapport est rendu par le
 *    panel à l'ouverture, c'est sa génération ;
 *  - `partages` : les liens de partage FIGÉS récupérés de `mac_report_share`
 *    (un rapport mensuel, une boutique, un mois — page publique `/r/{token}`),
 *    avec état, ouvertures et expiration.
 */
function ep_pwa_reports(): array
{
    $cfgBase = Db::config()['pwaBase'] ?? null;                       // config/env prime
    $base = rtrim((string) ($cfgBase ?: setting('pwaBase', '')), '/');
    // Boutiques : vraies boutiques du panel (`shops`) si disponibles, sinon
    // ceo_shop. pwaId = id de la boutique du panel (= id_shop de mac_report_share).
    try {
        $magasins = array_map(fn ($r) => ['id' => (string) $r['id'], 'nom' => $r['name'], 'pwaId' => (int) $r['id']],
            Db::rows("SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id"));
    } catch (PDOException $e) {
        $magasins = array_map(fn ($r) => ['id' => $r['id'], 'nom' => $r['name'], 'pwaId' => $r['pwa_shop_id'] !== null ? (int) $r['pwa_shop_id'] : null],
            Db::rows("SELECT id, name, pwa_shop_id FROM ceo_shop WHERE status = 'Ouvert' ORDER BY id"));
    }
    $shopByPwa = [];
    foreach ($magasins as $m) { if ($m['pwaId'] !== null) { $shopByPwa[$m['pwaId']] = $m['nom']; } }

    $partages = [];
    try {
        $rows = Db::rows('SELECT token, id_shop, ym, label, consultant_name, created_at, expires_at, revoked_at, opens, last_opened_at
                            FROM mac_report_share ORDER BY created_at DESC LIMIT 100');
    } catch (PDOException $e) {
        $rows = []; // table absente (panel sur une autre base) : volet vide, pas d'erreur
    }
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $r) {
        $etat = $r['revoked_at'] !== null ? 'Révoqué' : ($r['expires_at'] < $now ? 'Expiré' : 'Actif');
        $partages[] = [
            'label' => $r['label'], 'ym' => $r['ym'],
            'magasin' => $shopByPwa[(int) $r['id_shop']] ?? ('Boutique #' . $r['id_shop']),
            'consultant' => $r['consultant_name'],
            'url' => $base !== '' ? $base . '/r/' . $r['token'] : '/r/' . $r['token'],
            'cree' => substr($r['created_at'], 0, 10), 'expire' => substr($r['expires_at'], 0, 10),
            'etat' => $etat, 'opens' => (int) $r['opens'],
            'derniereOuverture' => $r['last_opened_at'] ? substr($r['last_opened_at'], 0, 10) : null,
        ];
    }
    return ['base' => $base, 'magasins' => $magasins, 'partages' => $partages];
}

/**
 * Noms des tâches prédéfinies du panel (table partagée `todo_task`).
 *
 * Le schéma de `todo_task` varie ; on détecte la colonne du libellé comme le
 * fait le panel (TodoTaskRepository) plutôt que de supposer `name`. Table ou
 * colonne absente → map vide (les tâches s'afficheront par leur identifiant).
 *
 * @return array<int, string> id_task => libellé
 */
function todoTaskNames(): array
{
    try {
        $cols = array_map(fn ($r) => (string) $r['COLUMN_NAME'],
            Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_task'"));
        if ($cols === []) { return []; }
        $lower = array_map('strtolower', $cols);
        $nameCol = null;
        foreach (['name', 'title', 'label', 'task_name', 'task'] as $cand) {
            $i = array_search($cand, $lower, true);
            if ($i !== false) { $nameCol = $cols[$i]; break; }
        }
        if ($nameCol === null) {
            foreach ($cols as $c) { if (stripos($c, 'name') !== false) { $nameCol = $c; break; } }
        }
        if ($nameCol === null) { return []; }
        $idCol = in_array('id', $lower, true) ? 'id' : $cols[0];
        $out = [];
        foreach (Db::rows("SELECT `$idCol` AS id, `$nameCol` AS name FROM todo_task LIMIT 1000") as $r) {
            $out[(int) $r['id']] = trim((string) $r['name']);
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Réglages du contrôle par exception (seuils, fenêtre, cadence).
 *
 * @return array{masquer:float, rouvrir:float, minAvis:int, fenetre:int, recontroleJours:int, noteGrave:int}
 */
function maitriseReglages(): array
{
    $d = setting('maitrise', []);
    $d = is_array($d) ? $d : [];
    return [
        'masquer' => isset($d['masquer']) ? (float) $d['masquer'] : 4.2,
        'rouvrir' => isset($d['rouvrir']) ? (float) $d['rouvrir'] : 3.75,
        'minAvis' => isset($d['minAvis']) ? (int) $d['minAvis'] : 5,
        'fenetre' => isset($d['fenetre']) ? max(1, (int) $d['fenetre']) : 5,
        'recontroleJours' => isset($d['recontroleJours']) ? max(1, (int) $d['recontroleJours']) : 42,
        'noteGrave' => isset($d['noteGrave']) ? (int) $d['noteGrave'] : 2,
    ];
}

/**
 * L'état de maîtrise de chaque couple (boutique, tâche) — masqué ou à contrôler.
 *
 * La moyenne se calcule sur les N DERNIERS avis notés (fenêtre glissante), pas
 * sur tout l'historique : une dérive récente serait sinon noyée dans un bon
 * passé, et c'est justement la dérive qu'on cherche. Les avis non notés ne
 * comptent ni en bien ni en mal — ils n'ont pas eu lieu.
 *
 * L'ordre des règles est l'ordre de leur autorité : ce qui protège passe avant
 * ce qui optimise.
 *   1. `jamais_masquer` — un contrôle d'hygiène ne se gagne pas au mérite.
 *   2. une note GRAVE dans la fenêtre — rouvre le jour même, sans attendre que
 *      la moyenne bouge : une moyenne noie un accident isolé, or c'est
 *      justement l'accident qu'il faut revoir.
 *   3. la réouverture MANUELLE — nouveau gérant, travaux, réclamation : elle
 *      tient le temps d'une cadence complète, sinon le calcul l'effacerait au
 *      rendu suivant.
 *   4. le re-contrôle périodique échu.
 *   5. trop peu d'avis pour juger.
 *   6. seulement alors, l'hystérésis des deux seuils.
 *
 * Rend, par clé « shop|task », l'état et de quoi l'expliquer à l'écran. Les
 * bascules sont persistées : l'hystérésis a besoin de se souvenir.
 *
 * @param  list<array{0:int,1:int}> $paires
 * @return array<string, array<string,mixed>>
 */
function maitriseEtats(array $paires): array
{
    if ($paires === []) { return []; }
    $R = maitriseReglages();
    $aujourdhui = date('Y-m-d');

    $shops = array_values(array_unique(array_map(fn ($p) => (int) $p[0], $paires)));
    $voulu = [];
    foreach ($paires as $p) { $voulu[(int) $p[0] . '|' . (int) $p[1]] = true; }

    // Les avis notés des six derniers mois pour ces boutiques, du plus récent
    // au plus ancien : la fenêtre se découpe ensuite en PHP (MySQL 5.7 n'a pas
    // de fonction de fenêtrage, et un LIMIT par couple ferait N requêtes).
    $notes = [];
    try {
        $in = implode(',', array_fill(0, count($shops), '?'));
        $depuis = date('Y-m-d', strtotime('-180 days'));
        // Borne PROPORTIONNÉE au besoin : il faut `fenetre` notes par couple,
        // et le tri du plus récent au plus ancien les sert en premier. Quatre
        // fois la quantité utile laisse de la marge pour les couples inégalement
        // contrôlés, sans lire six mois d'historique à chaque ouverture d'écran
        // (l'écran est déjà lent, et c'est cette lenteur qui vide les tableaux).
        // Un couple peu contrôlé récemment ressort donc avec moins d'avis que
        // son historique complet : il reste VISIBLE, c'est-à-dire du côté du
        // contrôle — jamais du côté du masquage.
        $borne = max(500, count($voulu) * $R['fenetre'] * 4);
        $rows = Db::rows("SELECT id_shop, id_task, rating, review_date
                            FROM mac_task_review
                           WHERE rating IS NOT NULL AND id_shop IN ($in) AND review_date >= ?
                        ORDER BY review_date DESC, id DESC
                           LIMIT $borne", array_merge($shops, [$depuis]));
        foreach ($rows as $r) {
            $cle = (int) $r['id_shop'] . '|' . (int) $r['id_task'];
            if (!isset($voulu[$cle])) { continue; }
            $notes[$cle][] = ['note' => (int) $r['rating'], 'date' => (string) $r['review_date']];
        }
    } catch (PDOException $e) { /* table absente : tout reste à contrôler */ }

    $etats = [];
    try {
        foreach (Db::rows('SELECT * FROM ceo_task_maitrise') as $r) {
            $etats[(int) $r['id_shop'] . '|' . (int) $r['id_task']] = $r;
        }
    } catch (PDOException $e) { /* table absente : premier passage */ }

    $out = [];
    foreach ($paires as $p) {
        $sid = (int) $p[0]; $tid = (int) $p[1];
        $cle = $sid . '|' . $tid;
        $fen = array_slice($notes[$cle] ?? [], 0, $R['fenetre']);
        $n = count($fen);
        $moy = $n > 0 ? round(array_sum(array_column($fen, 'note')) / $n, 2) : null;
        $grave = false;
        foreach ($fen as $f) { if ($f['note'] <= $R['noteGrave']) { $grave = true; break; } }

        $e = $etats[$cle] ?? null;
        $avant = $e !== null ? (string) $e['etat'] : 'visible';
        $permanent = $e !== null && (int) $e['jamais_masquer'] === 1;
        $recontrole = $e !== null && $e['recontrole_le'] !== null ? (string) $e['recontrole_le'] : null;
        // Une réouverture manuelle tient une cadence complète : sans cela le
        // calcul la balaierait au rendu suivant, et le bouton ne servirait à rien.
        $forceJusqu = $e !== null && $e['force_le'] !== null
            ? date('Y-m-d', strtotime((string) $e['force_le'] . ' +' . $R['recontroleJours'] . ' days')) : null;

        if ($permanent) {
            $etat = 'visible'; $motif = 'Contrôle permanent — jamais masqué';
        } elseif ($grave) {
            $etat = 'visible'; $motif = 'Note grave dans les ' . $n . ' derniers contrôles';
        } elseif ($forceJusqu !== null && $forceJusqu >= $aujourdhui) {
            $etat = 'visible'; $motif = 'Rouvert manuellement' . ($e['force_par'] ? ' par ' . $e['force_par'] : '');
        } elseif ($recontrole !== null && $recontrole <= $aujourdhui) {
            $etat = 'visible'; $motif = 'Re-contrôle périodique';
        } elseif ($n < $R['minAvis']) {
            $etat = 'visible'; $motif = $n . ' contrôle(s) sur ' . $R['minAvis'] . ' — pas encore de quoi juger';
        } elseif ($avant === 'masquee') {
            // Masquée : elle ne rouvre QUE sous le seuil bas.
            $etat = $moy < $R['rouvrir'] ? 'visible' : 'masquee';
            $motif = $etat === 'visible'
                ? 'Moyenne retombée à ' . number_format((float) $moy, 2, ',', '') . ' (< ' . $R['rouvrir'] . ')'
                : 'Maîtrisée — ' . number_format((float) $moy, 2, ',', '') . ' sur ' . $n . ' contrôles';
        } else {
            // Visible : elle ne se masque QU'AU-DESSUS du seuil haut.
            $etat = $moy >= $R['masquer'] ? 'masquee' : 'visible';
            $motif = $etat === 'masquee'
                ? 'Maîtrisée — ' . number_format((float) $moy, 2, ',', '') . ' sur ' . $n . ' contrôles'
                : 'Moyenne ' . number_format((float) $moy, 2, ',', '') . ' (masquage à ' . $R['masquer'] . ')';
        }

        // Persistance : seulement quand l'état CHANGE, ou au premier passage.
        // Un rendu d'écran ne doit pas écrire une ligne par tâche à chaque
        // ouverture — l'état est une décision, pas une trace de lecture.
        $nouveauRecontrole = $recontrole;
        if ($etat === 'masquee' && $avant !== 'masquee') {
            $nouveauRecontrole = date('Y-m-d', strtotime('+' . $R['recontroleJours'] . ' days'));
        } elseif ($etat === 'visible' && $avant === 'masquee') {
            $nouveauRecontrole = null;
        }
        if ($e === null || $avant !== $etat) {
            try {
                Db::exec('INSERT INTO ceo_task_maitrise (id_shop, id_task, etat, depuis, moyenne, nb_avis, recontrole_le, motif)
                          VALUES (?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE etat = VALUES(etat), depuis = VALUES(depuis),
                            moyenne = VALUES(moyenne), nb_avis = VALUES(nb_avis),
                            recontrole_le = VALUES(recontrole_le), motif = VALUES(motif)',
                    [$sid, $tid, $etat, date('Y-m-d H:i:s'), $moy, $n, $nouveauRecontrole, mb_substr($motif, 0, 200)]);
            } catch (PDOException $eW) { /* écriture impossible : l'écran reste juste */ }
        }

        $out[$cle] = ['etat' => $etat, 'moyenne' => $moy, 'nb' => $n, 'motif' => $motif,
            'recontrole' => $etat === 'masquee' ? $nouveauRecontrole : null,
            'permanent' => $permanent, 'grave' => $grave];
    }
    return $out;
}

/**
 * GET /pwa/tasks?date=YYYY-MM-DD — contrôle des tâches consultants du panel.
 *
 * Arbre Boutique › Tâche › avis consultant, lu dans la table partagée
 * `mac_task_review` (même source que l'écran « réseau » du panel). Chaque avis
 * porte la note, l'acceptation, le commentaire, qui a évalué, ET la validation
 * de l'Owner (owner_validated_at/owner_name) — le CEO valide ou retire depuis
 * ici. Les libellés de tâche viennent de `todo_task`, les magasins de `shops`.
 *
 * Sans date : la dernière journée réellement évaluée (l'écran n'est pas vide).
 * Le contenu VIVANT des checklists (tâches planifiées du jour, photos) vient de
 * l'API amont du panel, hors de portée ici : on montre ce qui est persité.
 */
function ep_pwa_tasks(): array
{
    $date = $_GET['date'] ?? null;
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { $date = null; }

    $empty = ['date' => $date ?? date('Y-m-d'), 'dates' => [], 'shops' => [],
        'consultants' => [], 'totals' => ['taches' => 0, 'valides' => 0, 'refuses' => 0, 'aValider' => 0,
            'aControler' => 0, 'nonRendues' => 0, 'sansPhoto' => 0, 'noteMoy' => null],
        'indispo' => true];

    try {
        // Journées réellement évaluées (sélecteur de date) + date active par défaut.
        // Les dates proposées partaient des NOTES : aujourd'hui n'y figurait
        // jamais, et le sélecteur ouvrait sur la dernière journée déjà notée.
        // Or c'est précisément aujourd'hui qu'il y a à contrôler. La journée
        // courante et la veille sont donc toujours offertes.
        $dates = array_map(fn ($r) => (string) $r['d'],
            Db::rows("SELECT DISTINCT review_date d FROM mac_task_review ORDER BY review_date DESC LIMIT 90"));
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $d) {
            if (!in_array($d, $dates, true)) { $dates[] = $d; }
        }
        rsort($dates);
        if ($date === null) { $date = date('Y-m-d'); }

        $taskNames = todoTaskNames();
        $shopNames = [];
        try {
            foreach (Db::rows("SELECT id, name FROM shops") as $s) { $shopNames[(int) $s['id']] = $s['name']; }
        } catch (PDOException $e) { /* shops absente : nom = #id */ }

        $rows = Db::rows("SELECT * FROM mac_task_review WHERE review_date = ? ORDER BY id_shop, id_task", [$date]);

        // Noms RÉELS des tâches : l'API amont du panel est la seule à les porter
        // (la base ne garde que l'identifiant). On interroge une fois par
        // boutique concernée, et on complète le référentiel local `todo_task`.
        // Sans identifiants API configurés, on garde les noms disponibles.
        // L'ÉCRAN PART DES TÂCHES, PLUS DES NOTES. Auparavant l'API n'était
        // interrogée que pour les boutiques ayant déjà une note : sans note,
        // aucun appel, donc rien à contrôler — jamais. Mesuré : au 17/08 la
        // route rendait 34 tâches par boutique quand l'écran en affichait zéro.
        // On interroge donc toutes les boutiques actives, et `status` (PENDING
        // ou DONE) dit ce qui est rendu et attend un contrôle.
        $apiNames = [];
        $apiTaches = [];        // sid|tid => tâche telle que rendue par le panel
        $apiOn = PanelApi::configured();
        if ($apiOn) {
            try {
                $actifs = array_map(fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1'));
            } catch (PDOException $eA) { $actifs = []; }
            $sids = array_values(array_unique(array_merge($actifs,
                array_map(fn ($r) => (int) $r['id_shop'], $rows))));
            $req = [];
            foreach ($sids as $sid) { $req[$sid] = '/consultant/shops/' . $sid . '/tasks?date=' . urlencode($date); }
            foreach (PanelApi::getParallele($req) as $sid => $rep) {
                foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $t) {
                    $tid = (int) ($t['task_id'] ?? $t['id'] ?? 0);
                    if ($tid <= 0) { continue; }
                    $nom = trim((string) ($t['task_name'] ?? $t['name'] ?? ''));
                    if ($nom !== '') { $apiNames[$sid . '|' . $tid] = $nom; }
                    $apiTaches[$sid . '|' . $tid] = $t + ['_shop' => (int) $sid, '_task' => $tid];
                }
            }

            // LA PHOTO FAIT LA TÂCHE À NOTER. « status = DONE » ne suffit pas :
            // c'est la photo de réalisation que le consultant regarde pour
            // noter, et elle n'est pas dans la liste des tâches — elle vit dans
            // l'avancement de la checklist, sous `attachment_id`. Sans ce
            // second niveau d'appel, l'écran proposerait à la notation des
            // tâches sans rien à voir.
            $req2 = [];
            foreach ($sids as $sid) { $req2[$sid] = '/consultant/shops/' . $sid . '/checklists?date=' . urlencode($date); }
            $req3 = [];
            foreach (PanelApi::getParallele($req2) as $sid => $rep) {
                foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $cl) {
                    $cid = (int) ($cl['id'] ?? $cl['checklist_id'] ?? 0);
                    if ($cid > 0) {
                        $req3[$sid . '#' . $cid] = '/consultant/shops/' . $sid . '/checklists/' . $cid
                            . '/progress?date=' . urlencode($date);
                    }
                }
            }
            foreach (PanelApi::getParallele($req3) as $k3 => $rep) {
                $sid = (int) strstr((string) $k3, '#', true);
                $cid = (int) substr((string) $k3, strpos((string) $k3, '#') + 1);
                foreach (PanelApi::liste(is_array($rep) ? $rep : []) as $pr) {
                    $tid = (int) ($pr['task_id'] ?? $pr['id'] ?? 0);
                    if ($tid <= 0) { continue; }
                    $cle3 = $sid . '|' . $tid;
                    $att = (int) ($pr['attachment_id'] ?? 0);
                    $apiTaches[$cle3] = ($apiTaches[$cle3] ?? ['_shop' => $sid, '_task' => $tid])
                        + ['_att' => $att, '_cl' => $cid,
                           '_completion' => (int) ($pr['completion_id'] ?? 0),
                           '_statutProg' => (string) ($pr['status'] ?? '')];
                    if ($att > 0) { $apiTaches[$cle3]['_att'] = $att; }
                    if (!isset($apiNames[$cle3])) {
                        $n3 = trim((string) ($pr['task_name'] ?? $pr['name'] ?? ''));
                        if ($n3 !== '') { $apiNames[$cle3] = $n3; }
                    }
                }
            }
        }

        $byShop = [];
        $cons = [];
        $tot = ['taches' => 0, 'valides' => 0, 'refuses' => 0, 'aValider' => 0];
        $noteSum = 0; $noteN = 0;
        foreach ($rows as $r) {
            $sid = (int) $r['id_shop'];
            $tid = (int) $r['id_task'];
            $note = isset($r['rating']) && $r['rating'] !== null ? (int) $r['rating'] : null;
            $acc  = isset($r['is_accepted']) && $r['is_accepted'] !== null ? (bool) (int) $r['is_accepted'] : null;
            // Une tâche NOTÉE est validée : la note EST la validation. Le
            // contrôle de la direction consiste à noter (ou à renoter), pas à
            // cocher une case en plus — sans quoi l'écran annonce « 0 validée »
            // devant dix tâches déjà évaluées.
            $valide = $note !== null;
            $ctrlDir = !empty($r['owner_validated_at']);
            if (!isset($byShop[$sid])) {
                $byShop[$sid] = ['shopId' => (string) $sid, 'shop' => $shopNames[$sid] ?? ('Boutique #' . $sid), 'taches' => []];
            }
            $byShop[$sid]['taches'][] = [
                'taskId'      => (string) $tid,
                'tache'       => $apiNames[$sid . '|' . $tid] ?? $taskNames[$tid] ?? ('Tâche #' . $tid),
                'note'        => $note,
                'accepte'     => $acc,
                'comment'     => $r['comment'] !== null && $r['comment'] !== '' ? (string) $r['comment'] : null,
                'consultant'  => $r['consultant_name'] !== null ? (string) $r['consultant_name'] : null,
                'consultantId' => (int) ($r['id_consultant'] ?? 0),
                'date'        => (string) $r['review_date'],
                'valide'      => $valide,
                // Qui a validé = qui a noté (le consultant), sauf si la
                // direction a explicitement contresigné : elle prime alors.
                'valideePar'  => $valide ? ($r['consultant_name'] ?? null) : null,
                'revuePar'    => $ctrlDir ? ($r['owner_name'] ?? null) : null,
                'valideeLe'   => $ctrlDir ? substr((string) $r['owner_validated_at'], 0, 16)
                                          : ($valide && $r['updated_at'] !== null ? substr((string) $r['updated_at'], 0, 16) : null),
                'ctrlDir'     => $ctrlDir,
                'majLe'       => $r['updated_at'] !== null ? substr((string) $r['updated_at'], 0, 16) : null,
                // Le geste en boutique, distinct du contrôle : quand la photo a
                // été prise et par qui. Le panel les porte sur la tâche ; une
                // tâche notée les perdait, et l'écran ne montrait plus que
                // l'heure de la NOTATION.
                'faitLe'      => !empty($apiTaches[$sid . '|' . $tid]['completed_at'])
                    ? substr((string) $apiTaches[$sid . '|' . $tid]['completed_at'], 0, 16) : null,
                'faitePar'    => trim((string) ($apiTaches[$sid . '|' . $tid]['completed_by'] ?? '')) ?: null,
                'statut'      => $valide ? 'notee' : 'aControler',
            ];
            $tot['taches']++;
            if ($valide) { $tot['valides']++; } else { $tot['aValider']++; }
            if ($acc === false) { $tot['refuses']++; }
            if ($note !== null) { $noteSum += $note; $noteN++; }

            // Agrégat par consultant (pilotage des consultants).
            $cid = (int) ($r['id_consultant'] ?? 0);
            if (!isset($cons[$cid])) {
                $cons[$cid] = ['id' => $cid, 'nom' => $r['consultant_name'] !== null ? (string) $r['consultant_name'] : ('#' . $cid),
                    'avis' => 0, 'refuses' => 0, 'valides' => 0, 'noteSum' => 0, 'noteN' => 0];
            }
            $cons[$cid]['avis']++;
            if ($acc === false) { $cons[$cid]['refuses']++; }
            if ($valide) { $cons[$cid]['valides']++; }
            if ($note !== null) { $cons[$cid]['noteSum'] += $note; $cons[$cid]['noteN']++; }
        }

        // --- LES TÂCHES RENDUES ET NON ENCORE NOTÉES : le reste à contrôler.
        // C'était le trou : elles n'existent pas dans mac_task_review, donc
        // l'écran les ignorait. Une tâche DONE sans note est exactement ce que
        // le consultant doit regarder ; une PENDING n'est pas encore rendue et
        // n'est pas contrôlable — on distingue les deux plutôt que de les
        // mélanger sous un même « à valider ».
        $deja = [];
        foreach ($rows as $r) { $deja[(int) $r['id_shop'] . '|' . (int) $r['id_task']] = true; }
        foreach ($apiTaches as $cle => $t) {
            if (isset($deja[$cle])) { continue; }
            $sid = (int) $t['_shop']; $tid = (int) $t['_task'];
            $st = strtoupper(trim((string) ($t['status'] ?? ($t['_statutProg'] ?? ''))));
            $rendue = $st === 'DONE' || $st === 'COMPLETED' || !empty($t['completed_at']);
            // À NOTER = une photo existe. Rendue sans photo, il n'y a rien à
            // regarder : la tâche est faite mais non notable, et l'écran le dit
            // au lieu de la ranger avec celles qui attendent un avis.
            $photo = (int) ($t['_att'] ?? 0) > 0;
            $faite = $photo;
            if (!isset($byShop[$sid])) {
                $byShop[$sid] = ['shopId' => (string) $sid, 'shop' => $shopNames[$sid] ?? ('Boutique #' . $sid), 'taches' => []];
            }
            $byShop[$sid]['taches'][] = [
                'taskId' => (string) $tid,
                'tache'  => $apiNames[$cle] ?? ('Tâche #' . $tid),
                'note' => null, 'accepte' => null,
                'comment' => ($t['note'] ?? null) !== '' ? ($t['note'] ?? null) : null,
                'consultant' => null, 'consultantId' => 0, 'date' => $date,
                'valide' => false, 'valideePar' => null, 'revuePar' => null, 'valideeLe' => null,
                'ctrlDir' => false,
                'majLe' => !empty($t['completed_at']) ? substr((string) $t['completed_at'], 0, 16) : null,
                'faitLe' => !empty($t['completed_at']) ? substr((string) $t['completed_at'], 0, 16) : null,
                // Deux états bien séparés, portés jusqu'à l'écran.
                'statut' => $photo ? 'aControler' : ($rendue ? 'sansPhoto' : 'nonRendue'),
                'photo' => $photo,
                'faitePar' => ($t['completed_by'] ?? null) ?: null,
                'photoRequise' => !empty($t['requires_photo']),
                'obligatoire' => !empty($t['is_mandatory']),
                'checklist' => trim((string) ($t['checklist_name'] ?? '')) ?: null,
            ];
            $tot['taches']++;
            if ($photo) { $tot['aControler'] = ($tot['aControler'] ?? 0) + 1; $tot['aValider']++; }
            elseif ($rendue) { $tot['sansPhoto'] = ($tot['sansPhoto'] ?? 0) + 1; }
            else { $tot['nonRendues'] = ($tot['nonRendues'] ?? 0) + 1; }
        }
        $tot['aControler'] = $tot['aControler'] ?? 0;
        $tot['nonRendues'] = $tot['nonRendues'] ?? 0;
        $tot['sansPhoto']  = $tot['sansPhoto'] ?? 0;

        // --- CONTRÔLE PAR EXCEPTION : ce qui est maîtrisé sort de la liste.
        // La décision est attachée à chaque tâche plutôt que de la retirer ici :
        // l'écran replie les masquées au lieu de les faire disparaître, et le
        // motif reste lisible — un contrôle qui s'efface sans dire pourquoi
        // ressemble à un oubli.
        $paires = [];
        foreach ($byShop as $sid2 => $b2) {
            foreach ($b2['taches'] as $t2) { $paires[] = [(int) $sid2, (int) $t2['taskId']]; }
        }
        $maitrise = maitriseEtats($paires);
        $tot['masquees'] = 0;
        foreach ($byShop as $sid2 => $b2) {
            foreach ($byShop[$sid2]['taches'] as $i2 => $t2) {
                $m2 = $maitrise[(int) $sid2 . '|' . (int) $t2['taskId']] ?? null;
                if ($m2 === null) { continue; }
                // Une tâche DÉJÀ notée aujourd'hui reste montrée : elle est
                // faite, la masquer effacerait le travail du jour.
                $masquee = $m2['etat'] === 'masquee' && $t2['note'] === null;
                $byShop[$sid2]['taches'][$i2]['maitrise'] = [
                    'masquee' => $masquee, 'moyenne' => $m2['moyenne'], 'nb' => $m2['nb'],
                    'motif' => $m2['motif'], 'recontrole' => $m2['recontrole'],
                    'permanent' => $m2['permanent'],
                ];
                if ($masquee) {
                    $tot['masquees']++;
                    // Une tâche masquée ne compte plus dans le reste à faire :
                    // sinon l'écran réclamerait un contrôle qu'il n'affiche pas.
                    if (($t2['statut'] ?? '') === 'aControler') {
                        $tot['aControler'] = max(0, $tot['aControler'] - 1);
                        $tot['aValider'] = max(0, $tot['aValider'] - 1);
                    }
                }
            }
        }

        $consultants = array_map(fn ($c) => [
            'id' => $c['id'], 'nom' => $c['nom'], 'avis' => $c['avis'], 'refuses' => $c['refuses'], 'valides' => $c['valides'],
            'noteMoy' => $c['noteN'] > 0 ? round($c['noteSum'] / $c['noteN'], 1) : null,
        ], array_values($cons));
        usort($consultants, fn ($a, $b) => $b['avis'] <=> $a['avis']);

        $tot['noteMoy'] = $noteN > 0 ? round($noteSum / $noteN, 1) : null;

        // Répartition par NIVEAU DE CONFORMITÉ — le barème des cinq niveaux
        // (Exemplaire / Conforme / NC mineur / majeur / critique) est le
        // réglage `signalement`, partagé avec l'écran de validation : un
        // « majeur » doit vouloir dire la même chose partout.
        $sig = setting('signalement', []);
        $niveaux = (is_array($sig) && isset($sig['niveaux']) && is_array($sig['niveaux'])) ? $sig['niveaux'] : [];
        $parNote = [];
        foreach ($rows as $r) {
            if ($r['rating'] !== null) { $n = (int) $r['rating']; $parNote[$n] = ($parNote[$n] ?? 0) + 1; }
        }
        $repartition = [];
        foreach ($niveaux as $lv) {
            $n = (int) ($lv['n'] ?? 0);
            $c = $parNote[$n] ?? 0;
            $repartition[] = [
                'n' => $n, 'nom' => (string) ($lv['nom'] ?? ($n . '/5')),
                'couleur' => (string) ($lv['couleur'] ?? '#666666'),
                'aide' => (string) ($lv['aide'] ?? ''),
                'conforme' => $n >= (int) ($sig['seuil'] ?? 4),
                'nb' => $c, 'pct' => $noteN > 0 ? round(100 * $c / $noteN) : 0,
            ];
        }
        $tot['notees'] = $noteN;
        $tot['nonNotees'] = $tot['taches'] - $noteN;

        return ['date' => $date, 'dates' => $dates, 'shops' => array_values($byShop),
            'repartition' => $repartition, 'seuil' => (int) ($sig['seuil'] ?? 4),
            'consultants' => $consultants, 'totals' => $tot, 'indispo' => false,
            // L'écran doit pouvoir DIRE pourquoi il manque des noms/photos.
            'api' => ['configure' => $apiOn, 'erreur' => $apiOn ? PanelApi::$lastError : null]];
    } catch (PDOException $e) {
        // mac_task_review absente (panel sur une autre base / jamais alimentée).
        return $empty;
    }
}

/**
 * GET /pwa/tasks/detail?shop=&task=&date= — le détail d'une tâche : photo de
 * réalisation + avis en cours.
 *
 * La photo n'est pas en base : l'API donne un `attachment_id` sur le flux
 * d'avancement de la checklist, puis une URL signée pour l'image. On parcourt
 * les checklists du jour pour retrouver la ligne de CETTE tâche.
 */
/**
 * GET /pwa/waste/debug?shop=&from=&to= — forme brute de la réponse « pertes ».
 *
 * Sonde de diagnostic : la structure exacte de /shops/{id}/products/waste n'est
 * pas documentée ici. Plutôt que de deviner les noms de champs et d'écrire un
 * calcul qui échouerait en silence, on regarde. Lecture seule, quelques lignes.
 */
function ep_pwa_waste_debug(): array
{
    $shopId = (int) ($_GET['shop'] ?? 0);
    if ($shopId <= 0) { http_response_code(400); return ['error' => 'shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $from = $_GET['from'] ?? null; $to = $_GET['to'] ?? null;
    $q = [];
    if ($from) { $q['from'] = $from; $q['date_from'] = $from; }
    if ($to)   { $q['to'] = $to;     $q['date_to'] = $to; }
    $path = '/shops/' . $shopId . '/products/waste' . ($q ? '?' . http_build_query($q) : '');
    $brut = PanelApi::brut($path);
    $liste = PanelApi::shopWaste($shopId, $from, $to);
    $apercu = static function ($v) {
        if (!is_array($v)) { return ['type' => gettype($v), 'valeur' => $v]; }
        if (array_is_list($v)) {
            return ['type' => 'liste', 'n' => count($v),
                'clesPremier' => ($v && is_array($v[0])) ? array_slice(array_keys($v[0]), 0, 25) : null,
                'premier' => $v[0] ?? null];
        }
        return ['type' => 'objet', 'cles' => array_slice(array_keys($v), 0, 25), 'extrait' => array_slice($v, 0, 6, true)];
    };
    return [
        'chemin'    => $path,
        'erreur'    => PanelApi::$lastError,
        'clesBrut'  => is_array($brut) ? array_slice(array_keys($brut), 0, 15) : null,
        'products'         => is_array($brut) && isset($brut['products']) ? $apercu($brut['products']) : null,
        'grouped_products' => is_array($brut) && isset($brut['grouped_products']) ? $apercu($brut['grouped_products']) : null,
        'period_summary'   => is_array($brut) && isset($brut['period_summary']) ? $apercu($brut['period_summary']) : null,
        'nbLignesApresDepaquetage' => count($liste),
    ];
}

/**
 * GET /products/waste?produit=&periode=YYYY-MM — perte d'une référence,
 * magasin par magasin, pour la modale du scoring.
 *
 * Le taux réseau d'une référence peut cacher un seul magasin qui jette : la
 * décision (retirer ? reformer une équipe ?) n'est pas la même. On rend donc
 * le détail par boutique, trié du plus mauvais au meilleur.
 */
/**
 * GET /products/periodes?produit=… — le CA réseau et la marge brute d'UNE
 * référence, sur trois fenêtres : le mois affiché par le scoring, le
 * trimestre qui s'y termine, et l'année civile précédente.
 *
 * Servi à la FICHE de la référence, à l'ouverture : la ligne du tableau ne
 * porte plus ces chiffres — trois sommes SQL par référence n'ont rien à
 * faire dans un tableau de deux cents lignes. La marge brute = CA − volume ×
 * coût matière, le coût venant des mêmes sources que le score (recettes,
 * panel, saisie du cockpit) ; sans coût, la marge reste absente — pas un
 * zéro qui se lirait comme « aucune marge ».
 */
function ep_product_periodes(): array
{
    $pid = (int) ($_GET['produit'] ?? 0);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'produit requis']; }
    // La même fenêtre que le tableau : la fiche doit répondre sur le mois
    // qu'on vient de regarder, pas sur un mois courant sans caisse.
    $ref = setting('periodeProduits');
    $per = (is_string($ref) && preg_match('/^\d{4}-\d{2}$/', $ref)) ? $ref : date('Y-m');
    $moisDeb   = $per . '-01 00:00:00';
    $moisFin   = date('Y-m-01 00:00:00', strtotime($moisDeb . ' +1 month'));
    $triDeb    = date('Y-m-01 00:00:00', strtotime($moisDeb . ' -2 months'));
    $anPrec    = ((int) substr($per, 0, 4)) - 1;

    // Le coût matière : les mêmes sources que le score, dans le même ordre —
    // recettes (et panel, via catalogueCouts), puis la saisie du cockpit.
    $cout = catalogueCouts()[$pid]['mat'] ?? null;
    try {
        $ov = Db::row('SELECT mat FROM ceo_prod_product WHERE pwa_id = ? AND mat IS NOT NULL AND actif = 1', [$pid]);
        if ($ov !== null) { $cout = (float) $ov['mat']; }
    } catch (PDOException $e) { /* référentiel absent : recettes seules */ }

    $fenetre = static function (string $du, string $au) use ($pid, $cout): array {
        $r = Db::row('SELECT /*+ MAX_EXECUTION_TIME(6000) */
                             COALESCE(SUM(tp.quantity), 0) vol,
                             COALESCE(SUM(tp.total_gross_value_after_discount), 0) ca
                        FROM transaction t
                        JOIN transaction_product tp ON tp.id_transaction = t.id
                       WHERE tp.id_product = ? AND t.insert_timestamp >= ? AND t.insert_timestamp < ?',
            [$pid, $du, $au]);
        $vol = (float) ($r['vol'] ?? 0); $ca = (float) ($r['ca'] ?? 0);
        return ['volume' => (int) round($vol), 'ca' => round($ca, 2),
            'marge' => $cout !== null ? round($ca - $vol * $cout, 2) : null];
    };

    try {
        return ['produit' => $pid, 'periode' => $per, 'cout' => $cout,
            'fenetres' => [
                ['cle' => 'mois', 'label' => 'Mois ' . $per] + $fenetre($moisDeb, $moisFin),
                ['cle' => 'trimestre', 'label' => 'Trimestre ' . substr($triDeb, 0, 7) . ' → ' . $per] + $fenetre($triDeb, $moisFin),
                ['cle' => 'annee', 'label' => 'Année ' . $anPrec] + $fenetre($anPrec . '-01-01 00:00:00', ($anPrec + 1) . '-01-01 00:00:00'),
            ]];
    } catch (PDOException $e) {
        http_response_code(503);
        return ['error' => 'tables de caisse indisponibles'];
    }
}

function ep_product_waste(): array
{
    $pid = (int) ($_GET['produit'] ?? 0);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'produit requis']; }
    // Défaut : le mois de caisse réellement encodé, PAS le mois courant. La
    // caisse s'arrête à la mi-juillet ; interroger août rend des rebuts réels
    // face à zéro vente, donc 100 % de perte partout — un chiffre faux qui a
    // l'air d'une catastrophe. L'écran de scoring travaille déjà sur ce mois.
    $periode = (string) ($_GET['periode'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
        $ref = setting('periodeProduits');
        $periode = (is_string($ref) && preg_match('/^\d{4}-\d{2}$/', $ref)) ? $ref : date('Y-m');
    }
    preg_match('/^(\d{4})-(\d{2})$/', $periode, $m);
    $from = sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
    $to   = date('Y-m-d', strtotime($from . ' +1 month -1 day'));

    $out = ['produitId' => (string) $pid, 'nom' => null, 'periode' => $periode,
        'du' => $from, 'au' => $to, 'magasins' => [],
        'reseau' => ['jete' => 0, 'vendu' => 0, 'taux' => null],
        'api' => ['configure' => PanelApi::configured(), 'erreur' => null]];
    if (!PanelApi::configured()) {
        $out['api']['erreur'] = 'compte consultant non configuré (Mon compte)';
        return $out;
    }

    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id');
    } catch (PDOException $e) { $shops = []; }

    // Ventes PAR MAGASIN : notre propre agrégat de caisse. L'API rend un
    // `sold_qty` identique pour tous les magasins (valeur réseau) — s'en servir
    // comme dénominateur par magasin donnerait des taux faux, d'autant plus
    // trompeurs qu'ils paraissent plausibles.
    $venteParShop = [];
    try {
        foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */ t.id_shop, SUM(tp.quantity) q
                           FROM transaction t JOIN transaction_product tp ON tp.id_transaction = t.id
                           WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ? AND tp.id_product = ?
                           GROUP BY t.id_shop",
            [$from . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($to . ' +1 day')), $pid]) as $v) {
            $venteParShop[(int) $v['id_shop']] = (float) $v['q'];
        }
    } catch (PDOException $eV) { /* caisse indisponible : ventes inconnues */ }

    $totJ = 0.0; $totV = 0.0;
    foreach ($shops as $sh) {
        $sid = (int) $sh['id'];
        $w = PanelApi::shopProductWaste($sid, $pid, $from, $to);
        $j = $w !== null ? (float) ($w['waste_qty'] ?? 0) : 0.0;
        $v = $venteParShop[$sid] ?? 0.0;
        if ($out['nom'] === null && $w !== null && !empty($w['product_name'])) { $out['nom'] = (string) $w['product_name']; }
        $den = $j + $v;
        $totJ += $j; $totV += $v;
        $out['magasins'][] = [
            'shopId' => (string) $sid, 'magasin' => (string) $sh['name'],
            'jete' => (int) round($j), 'vendu' => (int) round($v),
            // Pas de vente ni de rebut : la référence n'était pas proposée ici.
            // C'est différent d'un taux nul, et l'écran doit pouvoir le dire.
            'taux' => $den > 0 ? round($j / $den, 4) : null,
            'motif' => $w !== null && !empty($w['top_reason']) ? (string) $w['top_reason'] : null,
            'caPerdu' => $w !== null && isset($w['ca_waste_net']) ? round((float) $w['ca_waste_net'], 2) : null,
        ];
    }
    usort($out['magasins'], function ($a, $b) {
        if ($a['taux'] === null) { return 1; }
        if ($b['taux'] === null) { return -1; }
        return $b['taux'] <=> $a['taux'];
    });
    $den = $totJ + $totV;
    $out['reseau'] = ['jete' => (int) round($totJ), 'vendu' => (int) round($totV),
        'taux' => $den > 0 ? round($totJ / $den, 4) : null];
    $out['api']['erreur'] = PanelApi::$lastError;
    return $out;
}

/**
 * GET /pwa/probe?paths=a,b,c — quels chemins de l'API amont répondent.
 *
 * Sonde de branchement : avant de câbler l'ETP il faut savoir COMMENT lister
 * les employés d'une boutique. On essaie les chemins plausibles et on rapporte
 * ce qui répond, plutôt que d'en supposer un et d'écrire un calcul muet.
 */
function ep_pwa_probe(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $paths = array_filter(array_map('trim', explode(',', (string) ($_GET['paths'] ?? ''))));
    if (!$paths) { http_response_code(400); return ['error' => 'paths requis']; }
    $out = [];
    foreach (array_slice($paths, 0, 12) as $p) {
        PanelApi::$lastError = null;
        $r = PanelApi::brut($p);
        $apercu = null;
        if (is_array($r)) {
            $apercu = array_is_list($r)
                ? ['type' => 'liste', 'n' => count($r), 'clesPremier' => ($r && is_array($r[0])) ? array_slice(array_keys($r[0]), 0, 20) : null, 'premier' => $r[0] ?? null]
                : ['type' => 'objet', 'cles' => array_slice(array_keys($r), 0, 20)];
        }
        $out[] = ['chemin' => $p, 'erreur' => PanelApi::$lastError, 'apercu' => $apercu];
    }
    return ['resultats' => $out];
}

/**
 * GET /production/catalogue — référentiel produit RÉSEAU (partie franchiseur).
 *
 * Temps de production, batchs, capacité four, durée de vie, coût matière : rien
 * de tout cela n'existe côté panel ni en base partagée. Le cockpit en est la
 * source. `pwaId` rapproche la référence du catalogue de caisse, ce qui permet
 * de croiser avec les ventes et les pertes réelles.
 */
/**
 * Catalogue produit du réseau.
 *
 * Le catalogue N'EST PAS tenu par le cockpit : il vit dans `product`
 * (atelierby_db), avec ses catégories et ses gammes saisonnières. Le cockpit
 * n'ajoute que ce qui n'existe nulle part ailleurs — les paramètres de
 * production (temps, batchs, four) et le coût matière saisi à la main. On
 * enrichit donc, on ne duplique pas : dupliquer ferait diverger les deux
 * listes sans que personne ne s'en aperçoive.
 */
function ep_prod_catalogue(): array
{
    $enrich = [];   // id_product du panel → ligne cockpit
    $parRef = [];
    foreach (Db::rows('SELECT * FROM ceo_prod_product') as $r) {
        $parRef[(string) $r['ref']] = $r;
        if ($r['pwa_id'] !== null) { $enrich[(int) $r['pwa_id']] = $r; }
    }
    $plano = [];
    foreach (Db::rows('SELECT * FROM pla_placement') as $p) { $plano[(string) $p['ref']] = $p; }

    $reel = ep_prod_catalogue_reel($enrich, $parRef, $plano);
    if ($reel !== null) { return $reel; }

    // Repli : installation autonome, sans la base de caisse.
    $rows = Db::rows('SELECT * FROM ceo_prod_product WHERE actif = 1 ORDER BY categorie, nom');
    return array_map(function ($r) use ($plano) {
        $pl = $plano[$r['ref']] ?? null;
        $mat = $r['mat'] !== null ? (float) $r['mat'] : null;
        $prix = $r['prix'] !== null ? (float) $r['prix'] : null;
        return [
            'ref' => $r['ref'], 'nom' => $r['nom'], 'categorie' => $r['categorie'],
            'prep' => (int) $r['prep'], 'cuisson' => (int) $r['cuisson'], 'fin' => (int) $r['fin'],
            'bmin' => (int) $r['bmin'], 'bmult' => (int) $r['bmult'], 'four' => (int) $r['four'],
            'dlv' => (int) $r['dlv'], 'mat' => $mat, 'prix' => $prix,
            // Marge unitaire : disponible ici parce que le coût matière est
            // tenu par le réseau — l'API de caisse ne l'expose pas.
            'marge' => ($mat !== null && $prix !== null) ? round($prix - $mat, 3) : null,
            'margePct' => ($mat !== null && $prix > 0) ? round(($prix - $mat) / $prix, 4) : null,
            'commission' => caCommission($prix),
            'margeNette' => caMargeNette($prix, $mat),
            'margeNettePct' => caMargeNettePct($prix, $mat),
            'must' => (bool) $r['must'], 'qmin' => (int) $r['qmin'],
            'periods' => $r['periods'], 'profil' => $r['profil'],
            'parametre' => ficheRemplie($r),
            'pwaId' => $r['pwa_id'] !== null ? (int) $r['pwa_id'] : null,
            'zone' => $pl ? $pl['zone'] : null,
            'meuble' => $pl ? $pl['meuble'] : null,
            'niveau' => $pl ? $pl['niveau'] : null,
            'slot' => $pl && $pl['slot'] !== null ? (int) $pl['slot'] : null,
        ];
    }, $rows);
}

/**
 * Exploitation — le P&L court des magasins : jour, semaine, mois.
 *
 * Trois précautions de fond.
 *
 * D'abord, « aujourd'hui » n'est pas la date du jour mais le DERNIER JOUR
 * ENCODÉ en caisse. La caisse s'arrête au 14 juillet ; afficher un vrai
 * « aujourd'hui » rendrait des zéros partout, qu'on lirait comme un effondrement
 * du réseau au lieu d'un simple retard d'encodage.
 *
 * Ensuite, l'objectif du jour et de la semaine se déduit du budget MENSUEL au
 * prorata des jours d'ouverture : c'est une convention, elle est donc annoncée
 * (`objectifBase`) plutôt que présentée comme une cible saisie.
 *
 * Enfin, un magasin sans budget encodé n'a pas un objectif de zéro : il n'en a
 * pas. La différence décide de la couleur d'une pastille.
 */
function ep_exploitation(): array
{
    $out = ['jour' => null, 'semaine' => null, 'mois' => null, 'magasins' => [],
        'reseau' => [], 'objectifBase' => 'budget mensuel au prorata des jours',
        'avertissement' => null];

    // FRAÎCHEUR : quand le compte consultant est là, les trois blocs viennent
    // de l'API du panel — ancrés sur AUJOURD'HUI, pas sur la dernière journée
    // encodée en base (la caisse partagée a des jours de retard). Trois
    // plages, mêmes définitions que le calcul de repli ci-dessous. Si l'API
    // ne répond pas, on retombe sans bruit sur la caisse en base.
    if (PanelApi::configured()) {
        $api = ep_exploitation_api($out);
        if ($api !== null) { return $api; }
    }

    try {
        $d = Db::rows('SELECT MAX(DATE(insert_timestamp)) j FROM transaction');
    } catch (PDOException $e) { return $out + ['erreur' => 'caisse indisponible']; }
    $jour = ($d && !empty($d[0]['j'])) ? (string) $d[0]['j'] : null;
    if ($jour === null) { return $out + ['erreur' => 'aucune vente enregistrée']; }

    $ts   = strtotime($jour);
    $lundi = date('Y-m-d', strtotime('monday this week', $ts));
    $mois1 = date('Y-m-01', $ts);
    $out['jour'] = $jour; $out['semaine'] = $lundi; $out['mois'] = substr($mois1, 0, 7);
    if ($jour !== date('Y-m-d')) {
        $out['avertissement'] = 'dernier jour encodé en caisse : ' . $jour
            . ' (la caisse n\'a rien remonté depuis)';
    }

    // Un seul balayage : le mois courant par magasin ET par jour. Tout le reste
    // — jour, semaine, mois — s'en déduit sans repasser sur la table.
    $par = [];
    try {
        foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(9000) */
                                  id_shop, DATE(insert_timestamp) j,
                                  COUNT(DISTINCT ticket_key) tickets,
                                  SUM(total_gross_amount_after_discount) ca
                             FROM transaction
                            WHERE insert_timestamp >= ? AND insert_timestamp < ?
                         GROUP BY id_shop, j", [$mois1 . ' 00:00:00',
                            date('Y-m-d 00:00:00', strtotime($jour . ' +1 day'))]) as $r) {
            $par[(int) $r['id_shop']][(string) $r['j']] = ['ca' => (float) $r['ca'], 'tk' => (int) $r['tickets']];
        }
    } catch (PDOException $e) { return $out + ['erreur' => 'agrégat de caisse indisponible']; }

    // Objectif du mois : le budget validé, et à défaut le CA théorique de
    // l'étude de marché. Un magasin dont le budget n'est pas encore négocié a
    // quand même une cible — la rendre invisible affichait « sans budget » sur
    // une carte qui, ailleurs dans le cockpit, sait très bien ce qu'elle vise.
    $budget = []; $budgetSrc = [];
    try {
        foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE year = ? AND month = ?',
            [(int) date('Y', $ts), (int) date('n', $ts)]) as $b) {
            $sid = (string) $b['shop_id'];
            if ($b['revenue_budget'] !== null && (float) $b['revenue_budget'] > 0) {
                $budget[$sid] = (float) $b['revenue_budget']; $budgetSrc[$sid] = 'budget';
            } elseif ($b['ca_theorique'] !== null && (float) $b['ca_theorique'] > 0) {
                $budget[$sid] = (float) $b['ca_theorique']; $budgetSrc[$sid] = 'theorique';
            }
        }
    } catch (PDOException $e) { /* budget non encodé */ }

    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) {
            $noms[(int) $s['id']] = (string) $s['name'];
        }
    } catch (PDOException $e) { /* noms indisponibles */ }

    $joursMois   = (int) date('t', $ts);
    $joursEcoule = (int) date('j', $ts);
    $joursSem    = (int) round((strtotime($jour) - strtotime($lundi)) / 86400) + 1;

    $bloc = function (array $jours, ?float $obj): array {
        $ca = 0.0; $tk = 0;
        foreach ($jours as $x) { $ca += $x['ca']; $tk += $x['tk']; }
        return ['ca' => round($ca, 2), 'tickets' => $tk,
            'panier' => $tk > 0 ? round($ca / $tk, 2) : null,
            'objectif' => $obj !== null ? round($obj, 2) : null,
            'atteinte' => ($obj !== null && $obj > 0) ? round($ca / $obj, 4) : null];
    };

    $tot = ['jour' => [], 'semaine' => [], 'mois' => []];
    foreach ($par as $sid => $jours) {
        $b = $budget[(string) $sid] ?? null;
        $dJour = isset($jours[$jour]) ? [$jours[$jour]] : [];
        $dSem  = []; $dMois = [];
        foreach ($jours as $j => $x) {
            $dMois[] = $x;
            if ($j >= $lundi) { $dSem[] = $x; }
        }
        $ligne = ['shopId' => (string) $sid, 'magasin' => $noms[$sid] ?? ('Magasin ' . $sid),
            'budgetMois' => $b,
            'jour'    => $bloc($dJour, $b !== null ? $b / $joursMois : null),
            'semaine' => $bloc($dSem,  $b !== null ? $b * $joursSem / $joursMois : null),
            'mois'    => $bloc($dMois, $b !== null ? $b * $joursEcoule / $joursMois : null),
            'moisPlein' => $b, 'budgetSource' => $b === null ? null : ($budgetSrc[(string) $sid] ?? 'budget')];
        $out['magasins'][] = $ligne;
        foreach (['jour', 'semaine', 'mois'] as $p) { $tot[$p][] = $ligne[$p]; }
    }
    usort($out['magasins'], fn($a, $b2) => $b2['mois']['ca'] <=> $a['mois']['ca']);

    foreach (['jour', 'semaine', 'mois'] as $p) {
        $ca = 0.0; $tk = 0; $ob = 0.0; $aucun = true;
        foreach ($tot[$p] as $x) {
            $ca += $x['ca']; $tk += $x['tickets'];
            if ($x['objectif'] !== null) { $ob += $x['objectif']; $aucun = false; }
        }
        $out['reseau'][$p] = ['ca' => round($ca, 2), 'tickets' => $tk,
            'panier' => $tk > 0 ? round($ca / $tk, 2) : null,
            'objectif' => $aucun ? null : round($ob, 2),
            'atteinte' => (!$aucun && $ob > 0) ? round($ca / $ob, 4) : null];
    }
    $out['magasinsSansBudget'] = count($out['magasins']) - count($budget);
    return $out;
}

/**
 * Le tableau d'exploitation par l'API du panel — le chemin FRAIS.
 *
 * Trois appels (jour, semaine, mois), chacun agrégé par boutique côté ERP :
 * mêmes définitions que le calcul sur la caisse en base, mais servies jusqu'au
 * jour même. Renvoie null si l'API ne rend rien — l'appelant retombe alors sur
 * la caisse.
 */
function ep_exploitation_api(array $out): ?array
{
    $auj = date('Y-m-d');
    $ts = strtotime($auj);
    $plages = [
        'jour'    => [$auj, $auj],
        'semaine' => [date('Y-m-d', strtotime('monday this week', $ts)), $auj],
        'mois'    => [date('Y-m-01', $ts), $auj],
    ];
    $parPer = [];
    foreach ($plages as $p => [$du, $au]) {
        $liste = analyseListe(PanelApi::shopsSalesKpisEntre($du, $au) ?? []);
        if ($liste === [] && $p === 'mois') { return null; }   // l'API ne sert rien : repli
        foreach ($liste as $x) {
            $id = 0;
            foreach (['shop_id', 'id_shop', 'id'] as $c2) {
                if (isset($x[$c2]) && is_numeric($x[$c2])) { $id = (int) $x[$c2]; break; }
            }
            if ($id <= 0) { continue; }
            $parPer[$p][$id] = [
                'ca' => (float) (nombreOuNull($x, ['ca', 'turnover', 'revenue']) ?? 0),
                'tk' => (int) (nombreOuNull($x, ['tickets', 'receipts', 'transactions']) ?? 0)];
        }
    }
    if (empty($parPer['mois'])) { return null; }

    $out['jour'] = $auj;
    $out['semaine'] = $plages['semaine'][0];
    $out['mois'] = substr($plages['mois'][0], 0, 7);
    $out['source'] = 'API panel — ventes servies jusqu’au jour même';

    // Objectif du mois : le budget validé, et à défaut le CA théorique de
    // l'étude de marché. Un magasin dont le budget n'est pas encore négocié a
    // quand même une cible — la rendre invisible affichait « sans budget » sur
    // une carte qui, ailleurs dans le cockpit, sait très bien ce qu'elle vise.
    $budget = []; $budgetSrc = [];
    try {
        foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE year = ? AND month = ?',
            [(int) date('Y', $ts), (int) date('n', $ts)]) as $b) {
            $sid = (string) $b['shop_id'];
            if ($b['revenue_budget'] !== null && (float) $b['revenue_budget'] > 0) {
                $budget[$sid] = (float) $b['revenue_budget']; $budgetSrc[$sid] = 'budget';
            } elseif ($b['ca_theorique'] !== null && (float) $b['ca_theorique'] > 0) {
                $budget[$sid] = (float) $b['ca_theorique']; $budgetSrc[$sid] = 'theorique';
            }
        }
    } catch (PDOException $e) { /* budget non encodé */ }
    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) {
            $noms[(int) $s['id']] = (string) $s['name'];
        }
    } catch (PDOException $e) { /* noms indisponibles */ }

    $joursMois   = (int) date('t', $ts);
    $joursEcoule = (int) date('j', $ts);
    $joursSem    = (int) round((strtotime($auj) - strtotime($plages['semaine'][0])) / 86400) + 1;

    $bloc = static function (?array $x, ?float $obj): array {
        $ca = $x['ca'] ?? 0.0; $tk = $x['tk'] ?? 0;
        return ['ca' => round($ca, 2), 'tickets' => $tk,
            'panier' => $tk > 0 ? round($ca / $tk, 2) : null,
            'objectif' => $obj !== null ? round($obj, 2) : null,
            'atteinte' => ($obj !== null && $obj > 0) ? round($ca / $obj, 4) : null];
    };

    $tot = ['jour' => [], 'semaine' => [], 'mois' => []];
    foreach ($parPer['mois'] as $sid => $dMois) {
        // sales-kpis rend aussi des entrées techniques : un identifiant que le
        // référentiel `shops` ne connaît pas, zéro partout (« Magasin 10 »).
        // Sans nom ET sans la moindre vente du mois, la carte n'apprend rien —
        // on la retire. Un inconnu AVEC du chiffre resterait affiché sous son
        // numéro : masquer un CA réel serait pire qu'un nom manquant.
        if (!isset($noms[$sid]) && (float) ($dMois['ca'] ?? 0) <= 0) { continue; }
        $b = $budget[(string) $sid] ?? null;
        $ligne = ['shopId' => (string) $sid, 'magasin' => $noms[$sid] ?? ('Magasin ' . $sid),
            'budgetMois' => $b,
            'jour'    => $bloc($parPer['jour'][$sid] ?? null,    $b !== null ? $b / $joursMois : null),
            'semaine' => $bloc($parPer['semaine'][$sid] ?? null, $b !== null ? $b * $joursSem / $joursMois : null),
            'mois'    => $bloc($dMois,                           $b !== null ? $b * $joursEcoule / $joursMois : null),
            'moisPlein' => $b, 'budgetSource' => $b === null ? null : ($budgetSrc[(string) $sid] ?? 'budget')];
        $out['magasins'][] = $ligne;
        foreach (['jour', 'semaine', 'mois'] as $p) { $tot[$p][] = $ligne[$p]; }
    }
    usort($out['magasins'], fn ($a, $b2) => $b2['mois']['ca'] <=> $a['mois']['ca']);

    foreach (['jour', 'semaine', 'mois'] as $p) {
        $ca = 0.0; $tk = 0; $ob = 0.0; $aucun = true;
        foreach ($tot[$p] as $x) {
            $ca += $x['ca']; $tk += $x['tickets'];
            if ($x['objectif'] !== null) { $ob += $x['objectif']; $aucun = false; }
        }
        $out['reseau'][$p] = ['ca' => round($ca, 2), 'tickets' => $tk,
            'panier' => $tk > 0 ? round($ca / $tk, 2) : null,
            'objectif' => $aucun ? null : round($ob, 2),
            'atteinte' => (!$aucun && $ob > 0) ? round($ca / $ob, 4) : null];
    }
    $out['magasinsSansBudget'] = count($out['magasins']) - count($budget);
    return $out;
}

/**
 * P&L détaillé d'UN magasin sur une période : jour, semaine ou mois.
 *
 * Tout se calcule sur la caisse et les recettes, jamais sur une estimation :
 *  · le food cost vient du coût matière réel des références vendues, filtré
 *    par le même contrôle de vraisemblance que partout ailleurs ;
 *  · la main-d'œuvre vient des heures plannifiées × taux horaire ;
 *  · les frais généraux sont MENSUELS et n'existent pas au jour le jour : ils
 *    sont donc calés au prorata, et l'endpoint le dit (`ohProrata`) au lieu de
 *    laisser croire à une mesure quotidienne.
 */
function ep_exploitation_magasin(): array
{
    $sid = (int) ($_GET['id'] ?? 0);
    if ($sid <= 0) { http_response_code(400); return ['error' => 'magasin requis']; }
    $per = (string) ($_GET['periode'] ?? 'mois');
    if (!in_array($per, ['jour', 'semaine', 'mois'], true)) { $per = 'mois'; }

    // Date de référence : AUJOURD'HUI, pas le dernier jour de notre table de
    // caisse. Ce panneau ne lit que l'API du panel, dont les données vont
    // jusqu'à aujourd'hui ; l'ancrer sur notre copie — arrêtée au 14 juillet —
    // revenait à demander une période que l'API n'a pas de raison de servir,
    // puis à s'étonner qu'elle réponde pour la sienne. Les cartes du dessus
    // gardent leur propre date, celle de la caisse, et l'écran l'affiche.
    $date = date('Y-m-d');

    $out = ['shopId' => (string) $sid, 'periode' => $per, 'date' => $date,
        'magasin' => null, 'blocs' => []];
    try {
        $sh = Db::rows('SELECT name FROM shops WHERE id = ?', [$sid]);
        if ($sh) { $out['magasin'] = (string) $sh[0]['name']; }
    } catch (PDOException $e) { /* nom indisponible */ }

    $attente = function (string $titre, ?string $motif = null): array {
        return ['titre' => $titre, 'etat' => 'attente', 'source' => null, 'donnees' => null,
            'motif' => $motif ?: (PanelApi::$lastError ?: 'endpoint non disponible')];
    };
    if (!PanelApi::configured()) {
        foreach ([['kpis', 'Indicateurs de vente'], ['pnl', 'Compte de résultat'],
                  ['categories', 'Ventilation du chiffre d\'affaires'],
                  ['reseau', 'Positionnement réseau']] as $b) {
            $out['blocs'][$b[0]] = $attente($b[1], 'compte consultant non configuré (Mon compte)');
        }
        return $out;
    }

    // --- Indicateurs de tête
    $k = PanelApi::salesKpis($sid, $per, $date);
    $out['blocs']['kpis'] = $k === null ? $attente('Indicateurs de vente')
        : ['titre' => 'Indicateurs de vente', 'etat' => 'ok', 'source' => PanelApi::$lastPath,
           'donnees' => [
               'ca' => nombreOuNull($k, ['ca', 'turnover', 'revenue']),
               'tickets' => nombreOuNull($k, ['tickets', 'ticket_count']),
               'produits' => nombreOuNull($k, ['products', 'product_count']),
               'panier' => nombreOuNull($k, ['avg_basket', 'average_basket', 'basket_avg']),
               'produitsParClient' => nombreOuNull($k, ['products_per_ticket', 'products_per_client']),
           ]];

    // --- Compte de résultat : porte aussi la ventilation par catégorie et la
    // main-d'œuvre. Un seul appel suffit donc là où j'en prévoyais trois.
    $p = PanelApi::pnl($sid, $per, $date);
    if ($p === null) {
        $out['blocs']['pnl'] = $attente('Compte de résultat');
        $out['blocs']['categories'] = $attente('Ventilation du chiffre d\'affaires');
    } else {
        $srcPnl = $src = PanelApi::$lastPath;
        $replisPnl = PanelApi::$lastFallbacks;
        $caPnl = $ca = nombreOuNull($p['turnover'] ?? [], ['value', 'amount']) ?? nombreOuNull($p, ['turnover']);
        $poste = function (array $p, string $cle) use ($ca): array {
            $v = $p[$cle] ?? null;
            $val = is_array($v) ? nombreOuNull($v, ['value', 'amount']) : (is_numeric($v) ? (float) $v : null);
            $pct = is_array($v) ? nombreOuNull($v, ['pct', 'percent', 'percentage', 'ratio']) : null;
            // Le pourcentage n'est calculé QUE si le CA est connu et non nul :
            // une part de zéro ne veut rien dire, et un zéro affiché non plus.
            if ($pct === null && $val !== null && $ca !== null && $ca > 0) { $pct = round(100 * $val / $ca, 1); }
            return ['valeur' => $val, 'pct' => $pct,
                'delta' => is_array($v) ? nombreOuNull($v, ['delta', 'variation']) : null];
        };
        // L'API ancre ses périodes sur AUJOURD'HUI et ignore la date demandée :
        // mesuré en ligne, `date=2026-07-14` rend le mois d'août. Les chiffres
        // sont justes, mais pour une autre période que celle du reste de
        // l'écran. On compare donc ce qui est demandé à ce qui est rendu, et on
        // le dit — un écart tu est bien pire qu'un écart affiché.
        $ecart = null;
        $du = (string) ($p['date_from'] ?? ''); $au2 = (string) ($p['date_to'] ?? '');
        if ($du !== '' && $au2 !== '' && ($date < $du || $date > $au2)) {
            $ecart = 'l\'API a rendu ' . $du . ' → ' . $au2 . ', alors que l\'écran porte sur '
                . $date . ' — ces chiffres ne couvrent pas la même période que les cartes';
        }
        // Une route dédiée qui échoue et un repli qui réussit : l'écran doit
        // dire lequel il lit, sinon on attribue à la bonne source des chiffres
        // qui viennent d'une autre.
        if ($replisPnl) {
            $ecart = trim(($ecart ? $ecart . ' — ' : '')
                . 'route dédiée indisponible : ' . implode(' · ', $replisPnl));
        }
        $out['blocs']['pnl'] = ['titre' => 'Compte de résultat', 'etat' => 'ok', 'source' => $src,
            'avertissement' => $ecart,
            'donnees' => [
                'periode' => $p['period'] ?? null, 'du' => $p['date_from'] ?? null, 'au' => $p['date_to'] ?? null,
                'ca' => $ca, 'caDelta' => nombreOuNull($p['turnover'] ?? [], ['delta', 'variation']),
                'labour' => $poste($p, 'labour'),
                'overhead' => $poste($p, 'overhead'),
                'result' => $poste($p, 'result'),
                'food' => $poste($p, 'food_cost'),
            ]];

    }

    // --- Ventilation par catégorie. Endpoint dédié : c'est lui qui porte le
    // food cost, absent du compte de résultat. Repli sur la ventilation du
    // /pnl, qui donne les montants mais pas la marge — donc pas la couleur.
    $cs = PanelApi::categorySales($sid, $per, $date);
    $srcCat = PanelApi::$lastPath;
    $repliCat = PanelApi::$lastFallbacks;
    $errCat = $cs === null ? PanelApi::$lastError : null;
    // Extraction VALIDÉE. Accepter la première liste venue m'a fait afficher
    // les cinq boutiques du réseau comme si c'étaient des catégories : cinq
    // lignes vides, à la place d'un repli qui en donnait onze de vraies. Une
    // liste n'est retenue que si ses éléments ressemblent à des catégories —
    // un intitulé ET un montant.
    $estCategorie = static function ($x): bool {
        if (!is_array($x)) { return false; }
        $nom = false;
        foreach (['name', 'label', 'category', 'category_name'] as $c) {
            if (!empty($x[$c]) && is_string($x[$c])) { $nom = true; break; }
        }
        if (!$nom) { return false; }
        foreach (['value', 'amount', 'ca', 'turnover', 'total'] as $c) {
            if (isset($x[$c]) && is_numeric($x[$c])) { return true; }
        }
        return false;
    };
    $trouver = static function ($n, int $prof = 0) use (&$trouver, $estCategorie) {
        if ($prof > 3 || !is_array($n)) { return null; }
        if (array_is_list($n) && $n && $estCategorie($n[0])) { return $n; }
        foreach ($n as $v) {
            if (is_array($v)) { $r = $trouver($v, $prof + 1); if ($r !== null) { return $r; } }
        }
        return null;
    };
    $cats = null;
    if (is_array($cs)) {
        // Réponse par boutique : descendre d'abord dans la nôtre, sinon on
        // lirait les catégories d'un autre magasin sans s'en apercevoir.
        $mien = null;
        if (array_is_list($cs)) {
            foreach ($cs as $e) {
                if (!is_array($e)) { continue; }
                foreach (['shop_id', 'id_shop', 'id'] as $c) {
                    if (isset($e[$c]) && (int) $e[$c] === $sid) { $mien = $e; break 2; }
                }
            }
        }
        $cats = $trouver($mien ?? $cs);
        if ($cats === null) {
            $errCat = 'forme non reconnue, clés : '
                . implode(', ', array_slice(array_keys(array_is_list($cs) ? ($cs[0] ?? []) : $cs), 0, 12));
        }
    }
    if ($cats === null && isset($p['turnover']['categories']) && is_array($p['turnover']['categories'])) {
        $cats = $p['turnover']['categories'];
        // Quand TOUTES les variantes échouent, la trace de repli est vide et
        // seule `lastError` porte la raison : sans elle on écrivait « sans
        // réponse », ce qui ne dit pas pourquoi.
        $repliCat = array_merge($repliCat ?: [], [$errCat ?: 'category-sales sans réponse']);
        $srcCat = $srcPnl;
    }
    if (!is_array($cats) || !$cats) {
        $out['blocs']['categories'] = $attente('Ventilation du chiffre d\'affaires');
    } else {
        $caRef = $caPnl;
        if ($caRef === null || $caRef <= 0) {
            $caRef = array_sum(array_map(fn($c) => nombreOuNull($c, ['value', 'amount', 'ca', 'turnover']) ?? 0, $cats));
        }
        $avCat = $repliCat ? ('route dédiée indisponible : ' . implode(' · ', $repliCat)) : null;
        $out['blocs']['categories'] = ['titre' => 'Ventilation du chiffre d\'affaires', 'etat' => 'ok',
            'source' => $srcCat, 'avertissement' => $avCat,
            'donnees' => array_map(function ($c) use ($caRef) {
                $v = nombreOuNull($c, ['value', 'amount', 'ca', 'turnover']);
                $fc = nombreOuNull($c, ['food_cost_pct', 'fc_pct', 'fc', 'food_cost', 'foodcost']);
                $mg = nombreOuNull($c, ['margin_pct', 'margin', 'marge_pct', 'gross_margin_pct']);
                // La marge se déduit du food cost quand elle n'est pas donnée —
                // jamais l'inverse d'un champ absent : sans l'un ni l'autre, la
                // catégorie reste sans couleur plutôt que teintée d'une supposition.
                if ($mg === null && $fc !== null) { $mg = round(100 - $fc, 1); }
                return ['categorie' => (string) ($c['name'] ?? $c['label'] ?? $c['category'] ?? '—'),
                    'ca' => $v,
                    'partCa' => ($v !== null && $caRef > 0) ? round($v / $caRef, 4) : null,
                    'delta' => nombreOuNull($c, ['delta', 'variation']),
                    'fcPct' => $fc, 'margePct' => $mg];
            }, $cats)];
    }

    // --- Positionnement : les indicateurs de TOUTES les boutiques en un appel.
    // Un appel par boutique donnait le même résultat, mais multipliait les
    // aller-retours et le risque qu'une réponse manque sans qu'on le voie.
    $ks = PanelApi::shopsSalesKpis($per, $date);
    $liste = null;
    if (is_array($ks)) {
        foreach ([$ks, $ks['shops'] ?? null, $ks['data'] ?? null, $ks['items'] ?? null] as $cand) {
            if (is_array($cand) && $cand && array_is_list($cand)) { $liste = $cand; break; }
        }
    }
    if ($liste === null) {
        $out['blocs']['reseau'] = $attente('Positionnement réseau');
    } else {
        $lignes = [];
        foreach ($liste as $r) {
            $id = 0;
            foreach (['shop_id', 'id_shop', 'id'] as $c) {
                if (isset($r[$c]) && is_numeric($r[$c])) { $id = (int) $r[$c]; break; }
            }
            $nom = '';
            foreach (['representative_name', 'shop_name', 'name', 'label'] as $c) {
                if (!empty($r[$c]) && is_string($r[$c])) { $nom = trim($r[$c]); break; }
            }
            if ($id <= 0 && $nom === '') { continue; }
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom !== '' ? $nom : ('Magasin ' . $id),
                'moi' => $id === $sid,
                'panier' => nombreOuNull($r, ['avg_basket', 'average_basket', 'basket_avg']),
                'produitsParClient' => nombreOuNull($r, ['products_per_ticket', 'products_per_client']),
                'ca' => nombreOuNull($r, ['ca', 'turnover', 'revenue'])];
        }
        $out['blocs']['reseau'] = !$lignes ? $attente('Positionnement réseau')
            : ['titre' => 'Positionnement réseau', 'etat' => 'ok',
               'source' => PanelApi::$lastPath, 'avertissement' => null, 'donnees' => $lignes];
    }
    return $out;
}

/** Première clé numérique présente parmi plusieurs écritures possibles. */
function nombreOuNull(array $d, array $cles): ?float
{
    foreach ($cles as $c) {
        if (isset($d[$c]) && is_numeric($d[$c])) { return (float) $d[$c]; }
    }
    return null;
}

/**
 * Indicateurs de toutes les boutiques, N contre N-1.
 *
 * Un seul appel réseau (/consultant/shops/sales-kpis) plutôt qu'un par
 * boutique : mêmes chiffres, calculés de la même façon pour tout le monde, et
 * une seule occasion de manquer une réponse au lieu de cinq.
 *
 * `?debug=1` rend la forme brute d'une ligne — la comparaison N-1 n'est pas
 * documentée, et supposer un nom de champ est le moyen le plus sûr d'afficher
 * un écart qui n'existe pas.
 */
function ep_exploitation_reseau(): array
{
    $per = (string) ($_GET['periode'] ?? 'mois');
    if (!in_array($per, ['jour', 'semaine', 'mois', 'annee'], true)) { $per = 'mois'; }
    $date = date('Y-m-d');
    $out = ['periode' => $per, 'date' => $date, 'etat' => 'attente', 'source' => null,
        'magasins' => [], 'motif' => null];

    if (!PanelApi::configured()) {
        $out['motif'] = 'compte consultant non configuré (Mon compte)';
        return $out;
    }
    // Bornes de la période, puis LES MÊMES un an plus tôt. La comparaison N-1
    // n'est pas rendue par cet endpoint : plutôt que de la déduire d'une autre
    // source — qui ne compterait pas pareil — on lui repose la même question
    // sur l'exercice précédent. Deux appels, une seule définition du chiffre.
    $ts = strtotime($date);
    $du = $per === 'jour' ? $date
        : ($per === 'semaine' ? date('Y-m-d', strtotime('monday this week', $ts))
        : ($per === 'annee' ? date('Y-01-01', $ts) : date('Y-m-01', $ts)));
    $r = PanelApi::shopsSalesKpisEntre($du, $date);
    if (!is_array($r)) { $out['motif'] = PanelApi::$lastError ?: 'endpoint non disponible'; return $out; }
    $src = PanelApi::$lastPath;

    $extraire = static function ($rep): ?array {
        foreach ([$rep, $rep['shops'] ?? null, $rep['data'] ?? null,
                  $rep['items'] ?? null, $rep['results'] ?? null] as $c) {
            if (is_array($c) && $c && array_is_list($c)) { return $c; }
        }
        return null;
    };
    $liste = $extraire($r);
    if ($liste === null) {
        $out['motif'] = 'forme non reconnue, clés : ' . implode(', ', array_slice(array_keys($r), 0, 12));
        return $out;
    }
    $n1par = [];
    $rn1 = PanelApi::shopsSalesKpisEntre(date('Y-m-d', strtotime($du . ' -1 year')),
                                         date('Y-m-d', strtotime($date . ' -1 year')));
    foreach ($extraire(is_array($rn1) ? $rn1 : []) ?? [] as $x) {
        foreach (['shop_id', 'id_shop', 'id'] as $c) {
            if (isset($x[$c]) && is_numeric($x[$c])) {
                $n1par[(int) $x[$c]] = nombreOuNull($x, ['ca', 'turnover', 'revenue']); break;
            }
        }
    }
    // Les noms ne sont pas dans les indicateurs : « Magasin 2 » n'aide personne.
    $noms = [];
    foreach (PanelApi::consultantShops() ?? [] as $sh) {
        $id = (int) ($sh['id'] ?? 0);
        if ($id <= 0) { continue; }
        foreach (['representative_name', 'name', 'label'] as $c) {
            if (!empty($sh[$c]) && is_string($sh[$c])) { $noms[$id] = trim($sh[$c]); break; }
        }
    }
    $out['etat'] = 'ok';
    $out['source'] = $src;
    $out['du'] = $du; $out['au'] = $date;
    if (!empty($_GET['debug'])) { $out['brut'] = array_slice($liste, 0, 2); }

    foreach ($liste as $x) {
        if (!is_array($x)) { continue; }
        $id = 0;
        foreach (['shop_id', 'id_shop', 'id'] as $c) {
            if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; }
        }
        $n  = nombreOuNull($x, ['ca', 'turnover', 'revenue', 'value', 'total']);
        $n1 = $n1par[$id] ?? nombreOuNull($x, ['ca_n1', 'previous', 'last_year']);
        // Aucun écart sans un N-1 non nul : un « 0 % » calculé faute de mieux se
        // lirait comme une stabilité alors qu'il signifie une absence.
        $ec = ($n !== null && $n1 !== null && $n1 != 0.0) ? round(100 * ($n - $n1) / $n1, 1) : null;
        $out['magasins'][] = ['shopId' => (string) $id,
            'magasin' => $noms[$id] ?? ('Magasin ' . $id),
            'n' => $n, 'n1' => $n1, 'ecart' => $ec,
            'tickets' => nombreOuNull($x, ['tickets', 'ticket_count']),
            'panier' => nombreOuNull($x, ['avg_basket', 'average_basket']),
            'produits' => nombreOuNull($x, ['products', 'product_count'])];
    }
    usort($out['magasins'], fn($a, $b) => ($b['n'] ?? 0) <=> ($a['n'] ?? 0));
    // Dire si la comparaison N-1 manque, plutôt que d'afficher une colonne vide
    // que chacun interprétera à sa façon.
    if ($out['magasins'] && !array_filter($out['magasins'], fn($m) => $m['ecart'] !== null)) {
        $out['motif'] = 'l\'API ne renvoie pas de comparaison N-1 sur cet appel';
    }
    return $out;
}

/**
 * GET /exploitation/rentabilite?periode=semaine|mois — le résultat net par jour
 * et par magasin, comme l'« Analyse rentabilité » de la PWA consultant.
 *
 * La construction est celle de la PWA, vérifiée chiffre à chiffre sur ses
 * écrans (Halle, mardi 11/08 : CA 1 186 €, marge brute 765,24 €, overhead
 * 420,73 €/jour = 13 042,65 ÷ 31) :
 *
 *  - le CA et la marge BRUTE par jour viennent de margin-heatmap (volet
 *    `days`) — le coût matière est CA − marge brute ;
 *  - labour et overhead viennent du P&L MENSUEL, répartis par jour
 *    d'ouverture : jours du mois plein dont le jour de semaine a montré de
 *    l'activité sur le mois courant (une boutique 7 j/7 en août → 31) ;
 *  - résultat net du jour = marge brute − labour/j − overhead/j.
 *
 * LIMITE ASSUMÉE : le panel ne rend le P&L mensuel que pour le mois COURANT
 * (/pnl/monthly répond 422 sur toute forme d'appel, la route générique ignore
 * la date transmise — mesuré). Un jour d'un autre mois garde donc son CA et sa
 * marge brute, mais son résultat net est nul avec le motif dit : inventer un
 * labour de juillet à partir d'août serait un chiffre faux présenté juste.
 */
function ep_exploitation_rentabilite(): array
{
    $per = (($_GET['periode'] ?? 'semaine') === 'mois') ? 'mois' : 'semaine';
    if (!PanelApi::configured()) {
        return ['indispo' => true, 'motif' => 'compte consultant non configuré (Mon compte)'];
    }
    $auj = new DateTimeImmutable('today');
    if ($per === 'semaine') {
        // Dernière semaine PLEINE : le lundi précédant le lundi de la semaine
        // en cours. Une semaine entamée mélangerait jours réels et cases vides.
        $du = $auj->modify('monday this week')->modify('-7 days');
        $au = $du->modify('+6 days');
    } else {
        $du = $auj->modify('first day of this month');
        $au = $auj; // les jours à venir n'ont pas de ventes, inutile de les demander
    }
    $duS = $du->format('Y-m-d'); $auS = $au->format('Y-m-d');
    $moisCourant = $auj->format('Y-m');
    $mDebut = $auj->modify('first day of this month')->format('Y-m-d');

    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    } catch (PDOException $e) {
        $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name");
    }

    $paths = [];
    foreach ($shops as $s) {
        $id = (int) $s['id'];
        $paths['hm' . $id] = '/consultant/shops/' . $id . '/margin-heatmap?from=' . $duS . '&to=' . $auS;
        // En vue mois, la fenêtre EST le mois courant : un seul appel sert les
        // jours et la détection des jours d'ouverture.
        if ($per === 'semaine') {
            $paths['hmM' . $id] = '/consultant/shops/' . $id . '/margin-heatmap?from=' . $mDebut . '&to=' . $auj->format('Y-m-d');
        }
        $paths['pnl' . $id] = '/consultant/shops/' . $id . '/pnl?period=month&date=' . $auj->format('Y-m-d');
        // Le P&L JOUR par jour : matière, main-d'œuvre, charges et résultat déjà
        // répartis par la source. Quand il répond, on ne répartit plus rien.
        $paths['pnlj' . $id] = '/consultant/shops/' . $id . '/pnl/daily?'
            . http_build_query(['date_from' => $duS, 'date_to' => $auS, 'from' => $duS, 'to' => $auS]);
    }
    $res = PanelApi::getParallele($paths, 6);

    $joursMois = (int) $auj->format('t');
    $premier = new DateTimeImmutable($moisCourant . '-01');
    $magasins = [];
    foreach ($shops as $s) {
        $id = (int) $s['id'];
        $hm = $res['hm' . $id] ?? null;
        if (!is_array($hm) || !isset($hm['days'])) {
            $magasins[] = ['id' => (string) $id, 'nom' => $s['name'], 'indispo' => true,
                'motif' => 'margin-heatmap sans réponse pour ce magasin'];
            continue;
        }
        // Jours d'ouverture du mois plein : les jours de semaine vus actifs sur
        // le mois courant, comptés sur tout le mois. C'est le diviseur de la
        // PWA (« mois ÷ jours ouverts »).
        $hmM = $per === 'semaine' ? ($res['hmM' . $id] ?? null) : $hm;
        $wdActifs = [];
        foreach ((array) ($hmM['days'] ?? []) as $d) {
            if (!empty($d['has_data']) && (float) ($d['ca'] ?? 0) > 0) { $wdActifs[(int) $d['weekday']] = true; }
        }
        $joursOuverts = 0;
        for ($i = 0; $i < $joursMois; $i++) {
            if (isset($wdActifs[(int) $premier->modify('+' . $i . ' days')->format('N')])) { $joursOuverts++; }
        }
        $pnl = $res['pnl' . $id] ?? null;
        $labourM = is_array($pnl) ? ($pnl['labour']['value'] ?? null) : null;
        $overM   = is_array($pnl) ? ($pnl['overhead']['value'] ?? null) : null;
        $labJ = ($labourM !== null && $joursOuverts > 0) ? $labourM / $joursOuverts : null;
        $ovJ  = ($overM !== null && $joursOuverts > 0) ? $overM / $joursOuverts : null;

        // Le P&L quotidien, s'il a répondu : une ligne par date, avec le
        // résultat DÉJÀ calculé. Le reste du bloc s'en sert quand la date y
        // figure, et retombe sinon sur la répartition au prorata.
        $pj = [];
        $pjr = $res['pnlj' . $id] ?? null;
        foreach ((array) (is_array($pjr) ? ($pjr['days'] ?? []) : []) as $x) {
            $dt = (string) ($x['date'] ?? '');
            if ($dt !== '') { $pj[$dt] = $x; }
        }
        $srcJour = $pj !== [] ? 'P&L quotidien du panel' : 'labour et overhead du mois répartis sur les jours ouverts';

        $jours = []; $caTot = 0.0; $netTot = 0.0; $netOk = true;
        foreach ((array) $hm['days'] as $d) {
            $ca = (float) ($d['ca'] ?? 0); $mb = (float) ($d['margin_value'] ?? 0);
            $ouvert = !empty($d['has_data']) && $ca > 0;
            $dansMois = str_starts_with((string) ($d['date'] ?? ''), $moisCourant);
            $lj = $dansMois ? $labJ : null; $oj = $dansMois ? $ovJ : null;
            $net = ($ouvert && $lj !== null && $oj !== null) ? $mb - $lj - $oj : null;
            // La source du jour l'emporte sur la reconstruction : elle connaît
            // les heures réellement prestées ce jour-là, pas une moyenne.
            $q = $pj[(string) ($d['date'] ?? '')] ?? null;
            if ($ouvert && is_array($q)) {
                $rev = isset($q['revenue']) ? (float) $q['revenue'] : null;
                $mat = isset($q['material']) ? (float) $q['material'] : null;
                if ($rev !== null && $mat !== null) { $mb = $rev - $mat; }
                if (isset($q['labour'])) { $lj = (float) $q['labour']; }
                if (isset($q['overhead'])) { $oj = (float) $q['overhead']; }
                $net = isset($q['result']) ? (float) $q['result']
                    : (($lj !== null && $oj !== null) ? $mb - $lj - $oj : null);
            }
            if ($ouvert) { $caTot += $ca; if ($net === null) { $netOk = false; } else { $netTot += $net; } }
            $jours[] = [
                'date' => $d['date'], 'wd' => (int) ($d['weekday'] ?? 0),
                'ouvert' => $ouvert,
                'ca' => $ouvert ? round($ca, 2) : null,
                'tickets' => $ouvert ? (int) ($d['tickets'] ?? 0) : null,
                'panier' => $ouvert ? round((float) ($d['avg_basket'] ?? 0), 2) : null,
                'coutMatiere' => $ouvert ? round($ca - $mb, 2) : null,
                'margeBrute' => $ouvert ? round($mb, 2) : null,
                'margePct' => $ouvert && $ca > 0 ? round($mb / $ca * 100, 1) : null,
                'labourJour' => $ouvert && $lj !== null ? round($lj, 2) : null,
                'overheadJour' => $ouvert && $oj !== null ? round($oj, 2) : null,
                'net' => $net !== null ? round($net, 2) : null,
                'netPct' => $net !== null && $ca > 0 ? round($net / $ca * 100, 1) : null,
                'motifNet' => $ouvert && $net === null
                    ? ($dansMois ? 'P&L mensuel sans réponse — labour et overhead indisponibles'
                                 : 'le panel ne rend le P&L mensuel que pour le mois courant — labour et overhead de ce mois-là indisponibles')
                    : null,
            ];
        }
        $magasins[] = ['id' => (string) $id, 'nom' => $s['name'],
            'sourceJour' => $srcJour,
            'joursOuverts' => $joursOuverts,
            'labourMois' => $labourM !== null ? round((float) $labourM, 2) : null,
            'overheadMois' => $overM !== null ? round((float) $overM, 2) : null,
            'jours' => $jours,
            'total' => ['ca' => round($caTot, 2),
                'net' => $netOk && $caTot > 0 ? round($netTot, 2) : null,
                'netPct' => $netOk && $caTot > 0 ? round($netTot / $caTot * 100, 1) : null]];
    }

    return ['periode' => $per, 'du' => $duS, 'au' => $auS, 'mois' => $moisCourant,
        'magasins' => $magasins,
        'source' => 'API panel — P&L quotidien quand la route le sert, sinon margin-heatmap avec labour et overhead du mois répartis par jour d\'ouverture'];
}

/** Les six mêmes jours de semaine précédant une date : six vendredis avant un
 *  vendredi. C'est la référence de comparaison de l'écran — un vendredi ne se
 *  compare pas à un mardi, et l'an dernier compare deux saisons différentes. */
function jourReference(string $date, int $n = 6): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) { $out[] = date('Y-m-d', strtotime($date . ' -' . (7 * $i) . ' days')); }
    return $out;
}

/** Le nom du jour de la semaine, au pluriel — « vendredis ». */
function jourNomSemaine(string $date, bool $pluriel = false): string
{
    $noms = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi',
        6 => 'samedi', 7 => 'dimanche'];
    $n = $noms[(int) date('N', strtotime($date))] ?? '';
    return $pluriel ? $n . 's' : $n;
}

/**
 * GET /exploitation/jour[?date=YYYY-MM-DD] — le résultat d'UNE journée, magasin
 * par magasin, puis le détail du magasin qu'on ouvre.
 *
 * Ce que l'API du panel mesure vraiment, et ce qui est reconstitué :
 *  · ventes, tickets, panier, produits par client : mesurés, pour la date
 *    demandée (sales/kpis et margin-heatmap acceptent des bornes) ;
 *  · coût matière et marge brute : margin-heatmap — la marge est brute, donc
 *    coût matière = CA − marge ;
 *  · main-d'œuvre : mesurée AU JOUR seulement pour aujourd'hui (le /pnl
 *    quotidien ignore la date demandée et rend toujours le jour courant). Pour
 *    une date passée, la masse salariale du mois est répartie sur les jours
 *    d'ouverture, et la ligne le dit (`labourSource`) ;
 *  · frais généraux : le panel ne les tient qu'au MOIS. Ils sont donc toujours
 *    répartis par jour d'ouverture — jamais présentés comme une mesure ;
 *  · ventilation par catégorie : mesurée à la date demandée.
 *
 * LA COMPARAISON. Pas « vs N-1 » : la moyenne des SIX MÊMES JOURS DE SEMAINE
 * qui précèdent — six vendredis avant un vendredi. Un jour de boulangerie
 * ressemble d'abord au même jour de la semaine d'avant : comparer un vendredi
 * à un mardi, ou à un vendredi d'il y a un an (autre saison, autre gamme,
 * autres prix), fabrique un écart qui ne dit rien. Six jours lissent une
 * fermeture ou un jour de fête sans diluer la tendance. Les jours fermés sont
 * écartés du calcul, et le nombre de jours réellement retenus voyage avec la
 * réponse (`refJours`) — comparer sur deux jours n'a pas le poids de six.
 *
 * Le résultat du jour = CA − coût matière − main-d'œuvre − frais généraux.
 * Il n'est jamais deviné : sans l'un des quatre, il vaut null et le motif suit.
 */
/**
 * Le PROFIL de semaine d'un magasin : ce que pèse chaque jour, en moyenne.
 *
 * Il servait déjà à répartir le budget du jour, mais il vivait le temps d'une
 * requête. Il est désormais écrit : un mois où le panel rend une fenêtre trop
 * courte garde ainsi le rythme mesuré la veille, et le profil devient une
 * donnée du cockpit — lisible, datée, réutilisable ailleurs (budget, rapports).
 */
function ensureProfilJour(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_shop_profil_jour ('
        . 'shop_id VARCHAR(8) NOT NULL,'
        . 'jour TINYINT NOT NULL,'            // 1 = lundi … 7 = dimanche
        . 'ca_moyen DECIMAL(12,2) NULL,'
        . 'jours INT NOT NULL DEFAULT 0,'     // occurrences observées
        . 'du DATE NULL, au DATE NULL,'
        . 'maj DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Écrire le profil d'un magasin — un jour de semaine par ligne. */
function profilJourEcrire(string $shopId, array $parWd, ?string $du, ?string $au): void
{
    ensureProfilJour();
    $now = date('Y-m-d H:i:s');
    foreach ($parWd as $wd => $v) {
        if ((int) ($v['n'] ?? 0) < 1) { continue; }
        Db::exec('INSERT INTO ceo_shop_profil_jour (shop_id, jour, ca_moyen, jours, du, au, maj)
                  VALUES (?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE ca_moyen = VALUES(ca_moyen), jours = VALUES(jours),
                    du = VALUES(du), au = VALUES(au), maj = VALUES(maj)',
            [$shopId, (int) $wd, round($v['s'] / $v['n'], 2), (int) $v['n'], $du, $au, $now]);
    }
}

/** Relire le profil mémorisé : [jour => ['moyenne'=>…, 'jours'=>…, 'maj'=>…]]. */
function profilJourLire(string $shopId): array
{
    ensureProfilJour();
    $out = [];
    try {
        foreach (Db::rows('SELECT jour, ca_moyen, jours, maj FROM ceo_shop_profil_jour WHERE shop_id = ?', [$shopId]) as $r) {
            $out[(int) $r['jour']] = ['moyenne' => $r['ca_moyen'] !== null ? (float) $r['ca_moyen'] : null,
                'jours' => (int) $r['jours'], 'maj' => $r['maj']];
        }
    } catch (PDOException $e) { /* table absente : pas de mémoire */ }
    return $out;
}

/**
 * Le PROFIL HORAIRE d'un magasin, jour de semaine par jour de semaine.
 *
 * C'est lui qui permet de dire, à 15 h, ce que la journée fera au total : on
 * connaît la part du chiffre déjà passée. Il coûte six lectures (les six
 * derniers mêmes jours), on ne les refait donc qu'UNE FOIS PAR SEMAINE et on
 * garde le résultat.
 */
function ensureProfilHeure(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_shop_profil_heure ('
        . 'shop_id VARCHAR(8) NOT NULL,'
        . 'jour TINYINT NOT NULL,'           // 1 = lundi … 7 = dimanche
        . 'heure TINYINT NOT NULL,'          // 0 … 23
        . 'ca_moyen DECIMAL(12,2) NULL,'
        . 'part DECIMAL(6,3) NULL,'          // % du CA de la journée
        . 'jours INT NOT NULL DEFAULT 0,'
        . 'maj DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, jour, heure)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Le profil horaire mémorisé : la part de chaque heure ET le montant moyen
 * qu'elle rapporte. Les deux servent : la part dit où l'on en est dans la
 * journée, les montants disent ce que les heures restantes rapportent
 * d'habitude.
 */
function profilHeureLire(string $shopId, int $jour): array
{
    ensureProfilHeure();
    $parts = []; $montants = []; $n = 0; $maj = null;
    try {
        foreach (Db::rows('SELECT heure, part, ca_moyen, jours, maj FROM ceo_shop_profil_heure
                            WHERE shop_id = ? AND jour = ? ORDER BY heure', [$shopId, $jour]) as $r) {
            $h = (int) $r['heure'];
            if ($r['part'] !== null) { $parts[$h] = (float) $r['part']; }
            if ($r['ca_moyen'] !== null) { $montants[$h] = (float) $r['ca_moyen']; }
            $n = max($n, (int) $r['jours']); $maj = $r['maj'];
        }
    } catch (PDOException $e) { /* table absente */ }
    return ['parts' => $parts, 'montants' => $montants, 'jours' => $n, 'maj' => $maj];
}

/**
 * Reconstruire le profil horaire d'un jour de semaine : les six dernières
 * occurrences, heure par heure. Rend le nombre de journées retenues.
 */
function profilHeureBatir(int $shopId, string $date): int
{
    ensureProfilHeure();
    $req = [];
    for ($i = 1; $i <= 6; $i++) {
        $d = date('Y-m-d', strtotime($date . ' -' . (7 * $i) . ' days'));
        $req[$d] = '/consultant/shops/' . $shopId . '/margin-heatmap?' . http_build_query(['from' => $d, 'to' => $d]);
    }
    $somme = []; $retenus = 0;
    foreach (PanelApi::getParallele($req, 4) as $d => $r) {
        $h = is_array($r) ? ($r['hours'] ?? null) : null;
        if (!is_array($h)) { continue; }
        $tot = 0.0;
        foreach ($h as $x) { $tot += (float) ($x['ca'] ?? 0); }
        if ($tot <= 0) { continue; }          // magasin fermé ce jour-là
        $retenus++;
        foreach ($h as $x) {
            $hh = (int) ($x['hour'] ?? -1);
            if ($hh < 0 || $hh > 23) { continue; }
            $somme[$hh] = ($somme[$hh] ?? 0) + (float) ($x['ca'] ?? 0);
        }
    }
    if ($retenus === 0) { return 0; }
    $total = array_sum($somme);
    if ($total <= 0) { return 0; }
    $jour = (int) date('N', strtotime($date));
    $now = date('Y-m-d H:i:s');
    Db::exec('DELETE FROM ceo_shop_profil_heure WHERE shop_id = ? AND jour = ?', [(string) $shopId, $jour]);
    foreach ($somme as $hh => $v) {
        if ($v <= 0) { continue; }
        Db::exec('INSERT INTO ceo_shop_profil_heure (shop_id, jour, heure, ca_moyen, part, jours, maj)
                  VALUES (?,?,?,?,?,?,?)',
            [(string) $shopId, $jour, (int) $hh, round($v / $retenus, 2), round(100 * $v / $total, 3), $retenus, $now]);
    }
    return $retenus;
}

function ep_exploitation_jour(): array
{
    $auj  = date('Y-m-d');
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $auj) { $date = $auj; }
    $estAuj = $date === $auj;
    $refDates = jourReference($date);
    $refLibelle = 'moyenne des 6 derniers ' . jourNomSemaine($date, true);

    $seuils = ['food' => 32.0, 'labour' => 33.0, 'overhead' => 13.5];
    try {
        foreach (Db::rows("SELECT code, seuil_bas, seuil_haut FROM kpi
                            WHERE code IN ('food','labour','overhead')") as $k) {
            $seuils[(string) $k['code']] = $k['seuil_haut'] !== null
                ? (float) $k['seuil_haut'] : (float) $k['seuil_bas'];
        }
    } catch (PDOException $e) { /* seuils par défaut */ }

    $out = ['date' => $date, 'aujourdhui' => $auj, 'estAujourdhui' => $estAuj,
        'seuils' => $seuils, 'magasins' => [], 'reseau' => null,
        'reference' => ['libelle' => $refLibelle, 'jours' => $refDates,
            'jourSemaine' => jourNomSemaine($date), 'nom' => jourNomSemaine($date, true),
            'explication' => 'Chaque écart compare la journée à la ' . $refLibelle
                . ' (' . implode(', ', array_map(fn ($d) => date('d/m', strtotime($d)), $refDates))
                . '). Les jours de fermeture sont écartés du calcul. Un même jour de semaine se'
                . ' compare à lui-même : le trafic d’une boulangerie dépend d’abord du jour, pas du mois.'],
        'source' => 'API panel — ventes et marge brute mesurées à la date, frais généraux mensuels répartis par jour d’ouverture'];
    if (!PanelApi::configured()) {
        return $out + ['indispo' => true, 'motif' => 'compte consultant non configuré (Mon compte)'];
    }

    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    } catch (PDOException $e) {
        $shops = Db::rows("SELECT id, name FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name");
    }
    if (!$shops) { return $out + ['indispo' => true, 'motif' => 'aucun magasin actif']; }

    $mois1  = date('Y-m-01', strtotime($date));
    $depuis = min($mois1, $refDates[count($refDates) - 1]);

    // L'OBJECTIF du jour : le budget validé du mois, à défaut le CA théorique
    // de l'étude — la règle du cockpit — ramené au jour d'ouverture. Un mois
    // sans budget n'a pas d'objectif : on ne fabrique pas de zéro.
    $budMois = [];
    try {
        foreach (Db::rows('SELECT shop_id, revenue_budget, ca_theorique FROM ceo_shop_month_perf
                            WHERE year = ? AND month = ?',
            [(int) date('Y', strtotime($date)), (int) date('n', strtotime($date))]) as $b2) {
            $v = null; $src = null;
            if ($b2['revenue_budget'] !== null && (float) $b2['revenue_budget'] > 0) {
                $v = (float) $b2['revenue_budget']; $src = 'budget';
            } elseif ($b2['ca_theorique'] !== null && (float) $b2['ca_theorique'] > 0) {
                $v = (float) $b2['ca_theorique']; $src = 'theorique';
            }
            if ($v !== null) { $budMois[(string) $b2['shop_id']] = ['montant' => $v, 'source' => $src]; }
        }
    } catch (PDOException $e) { /* budget non encodé : pas d'objectif */ }
    // « The heatmap window cannot exceed 31 days » (mesuré sur la route) : la
    // fenêtre qui va du plus ancien jour de référence à aujourd'hui fait 43
    // jours, elle part donc en tranches de 31 jours au plus. Les jours des
    // deux tranches sont ensuite recollés.
    $fenetres = [];
    $cur = $depuis;
    while ($cur <= $date) {
        $fin = date('Y-m-d', min(strtotime($cur . ' +30 days'), strtotime($date)));
        $fenetres[] = [$cur, $fin];
        $cur = date('Y-m-d', strtotime($fin . ' +1 day'));
    }
    $paths = ['cats' => '/consultant/shops/category-sales?'
        . http_build_query(['date_from' => $date, 'date_to' => $date])];
    // Une lecture par jour de référence, pour TOUS les magasins à la fois :
    // six appels au total, pas six par magasin.
    foreach ($refDates as $i => $rd) {
        $paths['catsR' . $i] = '/consultant/shops/category-sales?'
            . http_build_query(['date_from' => $rd, 'date_to' => $rd]);
    }
    foreach ($shops as $s) {
        $id = (int) $s['id'];
        // Les fenêtres portent la journée, la série du mois ET les six jours
        // de référence.
        foreach ($fenetres as $k => [$du, $au2]) {
            $paths['hm' . $id . '_' . $k] = '/consultant/shops/' . $id . '/margin-heatmap?'
                . http_build_query(['from' => $du, 'to' => $au2]);
        }
        $paths['kpi' . $id]  = '/shops/' . $id . '/statistics/sales/kpis?'
            . http_build_query(['date_from' => $date, 'date_to' => $date]);
        $paths['pnlM' . $id] = '/consultant/shops/' . $id . '/pnl?period=month&date=' . $auj;
        // Le /pnl du jour ne sert QUE pour aujourd'hui : sur une date passée il
        // rendrait la main-d'œuvre du jour courant.
        if ($estAuj) { $paths['pnlJ' . $id] = '/consultant/shops/' . $id . '/pnl'; }
        // Les HEURES de la journée regardée : c'est la part déjà écoulée qui
        // permet de projeter la fin de journée. Une seule date, sinon la
        // route agrège les heures de toute la fenêtre.
        $paths['hj' . $id] = '/consultant/shops/' . $id . '/margin-heatmap?'
            . http_build_query(['from' => $date, 'to' => $date]);
    }
    $res = PanelApi::getParallele($paths, 6);

    /** La ventilation d'une réponse category-sales : [magasin => [catégorie => CA]]. */
    $lireCats = static function ($cs): array {
        $par = [];
        $liste = is_array($cs) ? ($cs['shops'] ?? (array_is_list($cs) ? $cs : [])) : [];
        foreach ((array) $liste as $e) {
            if (!is_array($e)) { continue; }
            $id = 0;
            foreach (['shop_id', 'id_shop', 'id'] as $c) {
                if (isset($e[$c]) && is_numeric($e[$c])) { $id = (int) $e[$c]; break; }
            }
            if ($id <= 0 || !isset($e['categories']) || !is_array($e['categories'])) { continue; }
            foreach ($e['categories'] as $c) {
                $v = nombreOuNull($c, ['ca', 'value', 'amount', 'turnover']);
                $nom = (string) ($c['name'] ?? $c['label'] ?? '');
                if ($nom === '' || $v === null) { continue; }
                $par[$id][$nom] = ($par[$id][$nom] ?? 0) + $v;
            }
        }
        return $par;
    };
    $catsJour = $lireCats($res['cats'] ?? null);
    $catsRef = [];
    foreach ($refDates as $i => $rd) { $catsRef[$rd] = $lireCats($res['catsR' . $i] ?? null); }

    // Le PLANNING de la journée, par personne : qui est en poste, de quand à
    // quand — la face humaine des ventes par heure. Le CA attribué se calcule
    // dans la boucle, une fois les heures du magasin connues.
    $planParShop = [];
    try {
        $empsJ = function_exists('venteEmployes') ? venteEmployes() : [];
        foreach (Db::rows('SELECT id_employee, start_hour, end_hour FROM franchisee_employee_schedule
                            WHERE work_date = ? ORDER BY start_hour', [$date]) as $pj) {
            $ej = $empsJ[(int) $pj['id_employee']] ?? null;
            if ($ej === null) { continue; }
            $dj = substr((string) $pj['start_hour'], 0, 5); $fj = substr((string) $pj['end_hour'], 0, 5);
            $hD = (int) substr($dj, 0, 2) + ((int) substr($dj, 3, 2)) / 60;
            $hF = (int) substr($fj, 0, 2) + ((int) substr($fj, 3, 2)) / 60;
            if ($hF <= $hD) { continue; }   // un service qui passe minuit sort du cadre du jour
            $planParShop[(string) $ej['shop']][] = ['nom' => $ej['nom'], 'debut' => $dj, 'fin' => $fj,
                'hD' => $hD, 'hF' => $hF, 'h' => round($hF - $hD, 2)];
        }
    } catch (PDOException $e) { /* planning illisible : les lignes s'en passent */ }

    $GLOBALS['_profilHRefaits'] = 0;
    $joursMois = (int) date('t', strtotime($date));
    $premier   = new DateTimeImmutable($mois1);
    $moisAff   = substr($date, 0, 7);
    $lignes = [];
    foreach ($shops as $s) {
        $id = (int) $s['id']; $nom = (string) $s['name'];
        $parJour = []; $repondu = false;
        foreach (array_keys($fenetres) as $k) {
            $hm = $res['hm' . $id . '_' . $k] ?? null;
            if (!is_array($hm) || !isset($hm['days'])) { continue; }
            $repondu = true;
            foreach ((array) $hm['days'] as $d) {
                $j = (string) ($d['date'] ?? '');
                if ($j === '') { continue; }
                $ca = (float) ($d['ca'] ?? 0);
                $parJour[$j] = ['ca' => $ca, 'mb' => (float) ($d['margin_value'] ?? 0),
                    'tickets' => (int) ($d['tickets'] ?? 0), 'weekday' => (int) ($d['weekday'] ?? 0),
                    'ouvert' => !empty($d['has_data']) && $ca > 0];
            }
        }
        if (!$repondu) {
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => false,
                'motif' => 'margin-heatmap sans réponse pour ce magasin'];
            continue;
        }
        ksort($parJour);

        // Jours d'ouverture du mois : les jours de semaine vus actifs, comptés
        // sur le mois entier. Même diviseur que l'écran rentabilité.
        $wdActifs = [];
        foreach ($parJour as $j => $d) {
            if ($d['ouvert'] && str_starts_with($j, $moisAff)) { $wdActifs[$d['weekday']] = true; }
        }
        $joursOuverts = 0;
        for ($i = 0; $i < $joursMois; $i++) {
            if (isset($wdActifs[(int) $premier->modify('+' . $i . ' days')->format('N')])) { $joursOuverts++; }
        }

        $pnlM = $res['pnlM' . $id] ?? null;
        $labourMois = is_array($pnlM) ? nombreOuNull((array) ($pnlM['labour'] ?? []), ['value', 'amount']) : null;
        // Frais généraux : le détail mensuel PRIME sur la valeur de tête. Mesuré
        // en ligne, /pnl rend `overhead.value = 0` et le vrai montant dans
        // `overhead.breakdown.month` — lire la tête d'abord donnait un zéro
        // parfaitement crédible, et un résultat du jour surévalué d'autant.
        $ohMois = is_array($pnlM)
            ? nombreOuNull((array) ($pnlM['overhead']['breakdown']['month'] ?? []), ['value', 'amount'])
            : null;
        if (($ohMois === null || $ohMois <= 0) && is_array($pnlM)) {
            $v = nombreOuNull((array) ($pnlM['overhead'] ?? []), ['value', 'amount']);
            if ($v !== null && $v > 0) { $ohMois = $v; }
        }
        $labJ = ($labourMois !== null && $joursOuverts > 0) ? $labourMois / $joursOuverts : null;
        $ohJ  = ($ohMois !== null && $joursOuverts > 0) ? $ohMois / $joursOuverts : null;

        $serie = [];
        foreach ($parJour as $j => $d) {
            if (!str_starts_with($j, $moisAff)) { continue; }   // la série montre le MOIS
            $net = ($d['ouvert'] && $labJ !== null && $ohJ !== null) ? $d['mb'] - $labJ - $ohJ : null;
            $serie[] = ['date' => $j, 'ouvert' => $d['ouvert'],
                'ca' => $d['ouvert'] ? round($d['ca'], 2) : null,
                'tickets' => $d['ouvert'] ? $d['tickets'] : null,
                'net' => $net !== null ? round($net, 2) : null,
                'netPct' => ($net !== null && $d['ca'] > 0) ? round($net / $d['ca'] * 100, 1) : null];
        }

        $jour = $parJour[$date] ?? null;
        if ($jour === null || !$jour['ouvert']) {
            $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => false,
                'motif' => 'aucune vente ce jour-là', 'serie' => $serie];
            continue;
        }

        // --- la référence : les mêmes jours de semaine, fermetures écartées
        $refOuverts = [];
        foreach ($refDates as $rd) {
            if (isset($parJour[$rd]) && $parJour[$rd]['ouvert']) { $refOuverts[] = $rd; }
        }
        $refN = count($refOuverts);
        $refCa = $refN ? array_sum(array_map(fn ($d2) => $parJour[$d2]['ca'], $refOuverts)) / $refN : null;
        $refTickets = $refN ? array_sum(array_map(fn ($d2) => $parJour[$d2]['tickets'], $refOuverts)) / $refN : null;
        $ecart = static fn (?float $v, ?float $ref): ?float
            => ($v !== null && $ref !== null && $ref > 0) ? round(($v / $ref - 1) * 100, 1) : null;

        $ca = $jour['ca'];
        $mb = $jour['mb'];
        $fc = $ca - $mb;
        $k = $res['kpi' . $id] ?? null;
        $tickets  = (int) (nombreOuNull(is_array($k) ? $k : [], ['tickets', 'ticket_count']) ?? $jour['tickets']);
        $produits = nombreOuNull(is_array($k) ? $k : [], ['products', 'product_count']);
        $panier   = nombreOuNull(is_array($k) ? $k : [], ['avg_basket', 'average_basket'])
            ?? ($tickets > 0 ? $ca / $tickets : null);
        $ppc      = nombreOuNull(is_array($k) ? $k : [], ['products_per_ticket', 'products_per_client'])
            ?? (($produits !== null && $tickets > 0) ? $produits / $tickets : null);

        $pnlJ = $estAuj ? ($res['pnlJ' . $id] ?? null) : null;
        $labourReel = is_array($pnlJ) ? nombreOuNull((array) ($pnlJ['labour'] ?? []), ['value', 'amount']) : null;
        $labour = $labourReel !== null ? $labourReel : $labJ;
        $labourSource = $labourReel !== null ? 'mesure' : ($labJ !== null ? 'reparti' : null);
        $oh = $ohJ;
        $net = ($labour !== null && $oh !== null) ? $ca - $fc - $labour - $oh : null;

        // --- catégories : le CA du jour, comparé à la moyenne des mêmes jours.
        // Une catégorie absente d'un jour de référence OUVERT compte zéro : ne
        // pas la compter reviendrait à comparer à ses seuls bons jours.
        $cats = [];
        $totCat = 0.0;
        foreach (($catsJour[$id] ?? []) as $nomCat => $v) {
            if ($v <= 0) { continue; }
            $totCat += $v;
            $refCat = null;
            if ($refN) {
                $somme = 0.0;
                foreach ($refOuverts as $rd) { $somme += (float) ($catsRef[$rd][$id][$nomCat] ?? 0); }
                $refCat = $somme / $refN;
            }
            $cats[] = ['categorie' => (string) $nomCat, 'ca' => round($v, 2),
                'ref' => $refCat !== null ? round($refCat, 2) : null,
                'delta' => $ecart($v, $refCat)];
        }
        foreach ($cats as &$c) { $c['part'] = $totCat > 0 ? round($c['ca'] / $totCat, 4) : null; }
        unset($c);
        usort($cats, fn ($a, $b) => $b['ca'] <=> $a['ca']);

        // ── L'OBJECTIF DU JOUR.
        //
        // Diviser le budget du mois par le nombre de jours serait malhonnête :
        // un dimanche ne ressemble pas à un lundi, et deux mois n'ont pas le
        // même nombre de dimanches. On répartit donc le budget selon le POIDS
        // RÉEL de chaque jour de semaine, mesuré sur CE magasin, sur les six
        // dernières semaines déjà lues ici — rien à demander de plus.
        //
        //   poids(jour) = CA moyen de ce jour de semaine
        //   attendu(mois) = somme des poids de tous les jours du mois
        //   objectif(jour) = budget du mois × poids(jour) / attendu(mois)
        //
        // Un jour de semaine jamais ouvert pèse zéro et ne prend rien aux
        // autres. Sans historique suffisant, on retombe sur la répartition
        // plate — et l'écran dit laquelle des deux a servi.
        $parWd = [];
        foreach ($parJour as $j2 => $d2) {
            if (empty($d2['ouvert'])) { continue; }
            $wd2 = (int) date('N', strtotime($j2));
            $parWd[$wd2]['s'] = ($parWd[$wd2]['s'] ?? 0) + (float) $d2['ca'];
            $parWd[$wd2]['n'] = ($parWd[$wd2]['n'] ?? 0) + 1;
        }
        $moyWd = [];
        foreach ($parWd as $wd2 => $v2) { if ($v2['n'] > 0) { $moyWd[$wd2] = $v2['s'] / $v2['n']; } }
        $attMois = 0.0;
        for ($i2 = 0; $i2 < $joursMois; $i2++) {
            $attMois += $moyWd[(int) $premier->modify('+' . $i2 . ' days')->format('N')] ?? 0.0;
        }
        // Le profil mesuré est ÉCRIT : il devient une donnée du cockpit, pas un
        // calcul jetable.
        $dates = array_keys($parJour);
        profilJourEcrire((string) $id, $parWd, $dates ? (string) min($dates) : null, $dates ? (string) max($dates) : null);

        $wdJour = (int) date('N', strtotime($date));
        $vus = (int) ($parWd[$wdJour]['n'] ?? 0);
        // Deux occurrences au moins : un seul dimanche observé n'est pas un
        // profil, c'est une anecdote. Si la fenêtre vive n'en montre pas assez,
        // le profil MÉMORISÉ prend le relais — un rythme ne change pas d'un
        // jour à l'autre.
        $profilSrc = 'vif';
        if ($vus < 2 || $attMois <= 0) {
            $mem = profilJourLire((string) $id);
            $moyMem = []; $vusMem = 0;
            foreach ($mem as $wdM => $vM) {
                if ($vM['moyenne'] !== null && $vM['jours'] >= 1) { $moyMem[(int) $wdM] = (float) $vM['moyenne']; }
            }
            $vusMem = (int) ($mem[$wdJour]['jours'] ?? 0);
            if ($vusMem >= 2 && isset($moyMem[$wdJour])) {
                $attMem = 0.0;
                for ($i3 = 0; $i3 < $joursMois; $i3++) {
                    $attMem += $moyMem[(int) $premier->modify('+' . $i3 . ' days')->format('N')] ?? 0.0;
                }
                if ($attMem > 0) { $moyWd = $moyMem; $attMois = $attMem; $vus = $vusMem; $profilSrc = 'memoire'; }
            }
        }
        $part = ($attMois > 0 && $vus >= 2 && isset($moyWd[$wdJour])) ? $moyWd[$wdJour] / $attMois : null;

        // ── PROJECTION DE FIN DE JOURNÉE.
        //
        // Le profil horaire du même jour de semaine dit quelle part du chiffre
        // est déjà passée à cette heure-ci. Le reste est une règle de trois.
        // Il est MÉMORISÉ et rebâti une fois par semaine : six lectures par
        // magasin, pas à chaque affichage. Et au plus deux magasins rafraîchis
        // par requête — un écran ne doit pas payer le rattrapage de tous.
        $proj = null; $projPart = null; $projHeure = null; $projJours = 0; $projMotif = null;
        $projReste = null; $projRythme = null;
        if ($estAuj) {
            $ph = profilHeureLire((string) $id, $wdJour);
            $vieux = $ph['maj'] === null || strtotime((string) $ph['maj']) < strtotime('-7 days');
            if (($ph['parts'] === [] || $vieux) && $GLOBALS['_profilHRefaits'] < 2) {
                $GLOBALS['_profilHRefaits']++;
                if (profilHeureBatir($id, $date) > 0) { $ph = profilHeureLire((string) $id, $wdJour); }
            }
            $hj = $res['hj' . $id] ?? null;
            $heuresJour = is_array($hj) ? ($hj['hours'] ?? null) : null;
            $derniere = -1;
            foreach ((array) $heuresJour as $x) {
                if ((float) ($x['ca'] ?? 0) > 0) { $derniere = max($derniere, (int) ($x['hour'] ?? -1)); }
            }
            if ($ph['parts'] === []) { $projMotif = 'profil horaire indisponible'; }
            elseif ($derniere < 0) { $projMotif = 'aucune vente encore aujourd’hui'; }
            else {
                $cum = 0.0; $reste = 0.0;
                foreach ($ph['parts'] as $h4 => $p4) { if ((int) $h4 <= $derniere) { $cum += (float) $p4; } }
                foreach ($ph['montants'] as $h5 => $m5) { if ((int) $h5 > $derniere) { $reste += (float) $m5; } }
                $projPart = round($cum, 1);
                $projHeure = $derniere;
                $projJours = (int) $ph['jours'];
                $projReste = round($reste, 2);
                // Sous 30 % de journée écoulée, une projection est du vent :
                // trois quarts du chiffre restent à faire et la moindre heure
                // creuse la ferait mentir.
                if ($cum >= 30 && $ca > 0) {
                    // CE QUI EST AFFICHÉ : le réalisé PLUS ce que les heures
                    // restantes rapportent d'habitude — la moyenne des six
                    // mêmes jours, heure par heure. On additionne des euros
                    // observés, on n'extrapole pas une tendance.
                    $proj = round($ca + $reste, 2);
                    // Second regard : la même journée prolongée AU RYTHME DU
                    // JOUR (règle de trois sur la part écoulée). Elle dit ce
                    // que donnerait la journée si l'après-midi ressemblait à la
                    // matinée. Les deux ensemble encadrent la vérité.
                    $projRythme = round($ca / ($cum / 100), 2);
                } else { $projMotif = 'moins de 30 % de la journée écoulée'; }
            }
        }

        $bm = $budMois[(string) $id] ?? null;
        $objMois = $bm !== null ? round($bm['montant'], 2) : null;
        $objSrc  = $bm !== null ? $bm['source'] : null;
        $objBase = $part !== null ? 'profil' : 'plat';
        $objJour = null;
        if ($bm !== null) {
            $objJour = $part !== null ? round($bm['montant'] * $part, 2)
                : ($joursOuverts > 0 ? round($bm['montant'] / $joursOuverts, 2) : null);
        }
        // Les ventes PAR HEURE du jour (déjà lues par la route des heures) et
        // le planning par personne : la sparkline de la vue réseau et le Gantt
        // du détail. Le CA attribué = le CA de chaque heure partagé entre les
        // personnes en poste cette heure-là.
        $hjV = $res['hj' . $id] ?? null;
        $heuresCa = [];
        foreach ((array) (is_array($hjV) ? ($hjV['hours'] ?? []) : []) as $x9) {
            $h9 = (int) ($x9['hour'] ?? -1);
            if ($h9 >= 0 && $h9 <= 23) { $heuresCa[$h9] = round((float) ($x9['ca'] ?? 0), 2); }
        }
        ksort($heuresCa);
        // La nuit ne se dessine pas : la série se coupe à la première et à la
        // dernière heure vendue — sinon la sparkline traîne 24 barres vides.
        $actives = array_keys(array_filter($heuresCa, static fn ($v9) => $v9 > 0));
        if ($actives !== []) {
            $heuresCa = array_filter($heuresCa,
                static fn ($h9) => $h9 >= min($actives) && $h9 <= max($actives), ARRAY_FILTER_USE_KEY);
        }
        $planM = $planParShop[(string) $id] ?? [];
        foreach ($planM as $i9 => $p9) {
            $att = 0.0;
            foreach ($heuresCa as $h9 => $ca9) {
                if ($ca9 <= 0 || $p9['hD'] >= $h9 + 1 || $p9['hF'] <= $h9) { continue; }
                $nb9 = 0;
                foreach ($planM as $q9) { if ($q9['hD'] < $h9 + 1 && $q9['hF'] > $h9) { $nb9++; } }
                $att += $ca9 / max(1, $nb9);
            }
            $planM[$i9]['ca'] = $att > 0 ? round($att, 2) : null;
            $planM[$i9]['caH'] = ($att > 0 && $p9['h'] > 0) ? round($att / $p9['h'], 2) : null;
            unset($planM[$i9]['hD'], $planM[$i9]['hF']);
        }
        usort($planM, static fn ($a9, $b9) => [$a9['debut'], $a9['nom']] <=> [$b9['debut'], $b9['nom']]);

        $lignes[] = ['shopId' => (string) $id, 'magasin' => $nom, 'ouvert' => true,
            'heures' => array_map(static fn ($h9) => ['h' => $h9, 'ca' => $heuresCa[$h9]], array_keys($heuresCa)),
            'planning' => $planM,
            'ca' => round($ca, 2),
            'refCa' => $refCa !== null ? round($refCa, 2) : null,
            'refJours' => $refN,
            'caDelta' => $ecart($ca, $refCa),
            'tickets' => $tickets,
            'refTickets' => $refTickets !== null ? round($refTickets) : null,
            'ticketsDelta' => $ecart((float) $tickets, $refTickets),
            'panier' => $panier !== null ? round($panier, 2) : null,
            'produits' => $produits !== null ? (int) $produits : null,
            'produitsParClient' => $ppc !== null ? round($ppc, 2) : null,
            'coutMatiere' => round($fc, 2), 'coutMatierePct' => $ca > 0 ? round($fc / $ca * 100, 1) : null,
            'margeBrute' => round($mb, 2), 'margeBrutePct' => $ca > 0 ? round($mb / $ca * 100, 1) : null,
            'labour' => $labour !== null ? round($labour, 2) : null,
            'labourPct' => ($labour !== null && $ca > 0) ? round($labour / $ca * 100, 1) : null,
            'labourSource' => $labourSource,
            'overhead' => $oh !== null ? round($oh, 2) : null,
            'overheadPct' => ($oh !== null && $ca > 0) ? round($oh / $ca * 100, 1) : null,
            'overheadMois' => $ohMois !== null ? round($ohMois, 2) : null,
            'labourMois' => $labourMois !== null ? round($labourMois, 2) : null,
            'joursOuverts' => $joursOuverts,
            'objectifJour' => $objJour, 'objectifSource' => $objSrc,
            'objectifMois' => $objMois, 'objectifBase' => $objBase,
            'objectifPart' => $part === null ? null : round($part * 100, 2),
            'objectifJoursVus' => $vus,
            'objectifProfil' => $part === null ? null : $profilSrc,
            'projection' => $proj,
            'projectionPart' => $projPart, 'projectionHeure' => $projHeure,
            'projectionJours' => $projJours, 'projectionMotif' => $projMotif,
            'projectionReste' => $projReste, 'projectionRythme' => $projRythme,
            'projectionAtteinte' => ($proj !== null && $objJour !== null && $objJour > 0)
                ? round($proj / $objJour, 4) : null,
            'objectifJourNom' => jourNomSemaine($date),
            'objectifAtteinte' => ($objJour !== null && $objJour > 0) ? round($ca / $objJour, 4) : null,
            'net' => $net !== null ? round($net, 2) : null,
            'netPct' => ($net !== null && $ca > 0) ? round($net / $ca * 100, 1) : null,
            'motifNet' => $net === null ? 'P&L mensuel sans réponse — main-d’œuvre ou frais généraux indisponibles' : null,
            'categories' => $cats, 'serie' => $serie];
    }
    usort($lignes, fn ($a, $b) => ($b['ca'] ?? -1) <=> ($a['ca'] ?? -1));
    $out['magasins'] = $lignes;

    // --- Réseau : la somme de ce qui est connu, jamais une extrapolation.
    $t = ['ca' => 0.0, 'refCa' => 0.0, 'coutMatiere' => 0.0, 'margeBrute' => 0.0, 'labour' => 0.0,
        'overhead' => 0.0, 'net' => 0.0, 'tickets' => 0, 'produits' => 0];
    $ouverts = 0; $netComplet = true; $refComplet = true; $refMin = null; $cats = [];
    foreach ($lignes as $l) {
        if (empty($l['ouvert'])) { continue; }
        $ouverts++;
        $t['ca'] += $l['ca']; $t['coutMatiere'] += $l['coutMatiere']; $t['margeBrute'] += $l['margeBrute'];
        $t['tickets'] += $l['tickets']; $t['produits'] += (int) ($l['produits'] ?? 0);
        if ($l['refCa'] === null) { $refComplet = false; }
        else { $t['refCa'] += $l['refCa']; $refMin = $refMin === null ? $l['refJours'] : min($refMin, $l['refJours']); }
        if ($l['labour'] === null || $l['overhead'] === null) { $netComplet = false; }
        else { $t['labour'] += $l['labour']; $t['overhead'] += $l['overhead']; $t['net'] += $l['net']; }
        foreach ($l['categories'] as $c) {
            $e = $cats[$c['categorie']] ?? ['categorie' => $c['categorie'], 'ca' => 0.0, 'ref' => 0.0, 'sansRef' => false];
            $e['ca'] += $c['ca'];
            if ($c['ref'] === null) { $e['sansRef'] = true; } else { $e['ref'] += $c['ref']; }
            $cats[$c['categorie']] = $e;
        }
    }
    $catsR = [];
    $totR = array_sum(array_map(fn ($c) => $c['ca'], $cats)) ?: 0.0;
    foreach ($cats as $c) {
        $catsR[] = ['categorie' => $c['categorie'], 'ca' => round($c['ca'], 2),
            'part' => $totR > 0 ? round($c['ca'] / $totR, 4) : null,
            'ref' => $c['sansRef'] ? null : round($c['ref'], 2),
            'delta' => (!$c['sansRef'] && $c['ref'] > 0) ? round(($c['ca'] / $c['ref'] - 1) * 100, 1) : null];
    }
    usort($catsR, fn ($a, $b) => $b['ca'] <=> $a['ca']);
    $ca = $t['ca'];
    $out['reseau'] = ['magasins' => $ouverts, 'ca' => round($ca, 2), 'tickets' => $t['tickets'],
        'produits' => $t['produits'],
        'refCa' => $refComplet ? round($t['refCa'], 2) : null,
        'refJours' => $refMin,
        'caDelta' => ($refComplet && $t['refCa'] > 0) ? round(($ca / $t['refCa'] - 1) * 100, 1) : null,
        'panier' => $t['tickets'] > 0 ? round($ca / $t['tickets'], 2) : null,
        'produitsParClient' => $t['tickets'] > 0 ? round($t['produits'] / $t['tickets'], 2) : null,
        'coutMatiere' => round($t['coutMatiere'], 2),
        'coutMatierePct' => $ca > 0 ? round($t['coutMatiere'] / $ca * 100, 1) : null,
        'margeBrute' => round($t['margeBrute'], 2),
        'margeBrutePct' => $ca > 0 ? round($t['margeBrute'] / $ca * 100, 1) : null,
        'labour' => $netComplet ? round($t['labour'], 2) : null,
        'labourPct' => ($netComplet && $ca > 0) ? round($t['labour'] / $ca * 100, 1) : null,
        'overhead' => $netComplet ? round($t['overhead'], 2) : null,
        'overheadPct' => ($netComplet && $ca > 0) ? round($t['overhead'] / $ca * 100, 1) : null,
        'net' => $netComplet ? round($t['net'], 2) : null,
        'netPct' => ($netComplet && $ca > 0) ? round($t['net'] / $ca * 100, 1) : null,
        'categories' => $catsR];
    return $out;
}

/**
 * GET /stores/kpis-annuels — trois lectures mensuelles sur l'année en cours,
 * par magasin : clients par jour, ticket moyen, articles par ticket.
 *
 * Une passe sales-kpis PAR MOIS écoulé (janvier → aujourd'hui), en parallèle :
 * l'API ne rend pas de série mensuelle, on la reconstruit borne à borne — même
 * technique que le CA mensuel de la centrale d'achat.
 *
 *  - clients/jour = tickets du mois ÷ jours du mois (mois en cours : jours
 *    écoulés). Le réseau ouvre 7 j/7 (mesuré sur margin-heatmap) : le jour
 *    calendaire EST le jour d'ouverture ;
 *  - ticket moyen = CA ÷ tickets — recalculé plutôt que lu (avg_basket sert de
 *    repli), pour que les trois tableaux sortent des mêmes deux chiffres ;
 *  - articles/ticket = produits vendus ÷ tickets.
 */
function ep_stores_kpis_annuels(): array
{
    if (!PanelApi::configured()) {
        return ['indispo' => true, 'motif' => 'compte consultant non configuré (Mon compte)'];
    }
    $annee = (int) date('Y');
    $moisMax = (int) date('n');
    $auj = date('Y-m-d');
    $paths = [];
    for ($m = 1; $m <= $moisMax; $m++) {
        $du = sprintf('%04d-%02d-01', $annee, $m);
        $au = min(date('Y-m-t', strtotime($du)), $auj);
        $paths['m' . $m] = '/consultant/shops/sales-kpis?' . http_build_query(['date_from' => $du, 'date_to' => $au]);
    }
    $res = PanelApi::getParallele($paths, 4);

    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) {
            $noms[(int) $s['id']] = (string) $s['name'];
        }
    } catch (PDOException $e) { /* référentiel indisponible : les numéros feront foi */ }

    $par = []; $reseau = [];
    for ($m = 1; $m <= $moisMax; $m++) {
        $liste = analyseListe($res['m' . $m] ?? []);
        $jours = ($m < $moisMax) ? (int) date('t', strtotime(sprintf('%04d-%02d-01', $annee, $m))) : (int) date('j');
        $tCa = 0.0; $tTk = 0; $tPr = 0; $aTk = false; $aPr = false;
        foreach ($liste as $x) {
            if (!is_array($x)) { continue; }
            $id = 0;
            foreach (['shop_id', 'id_shop', 'id'] as $c) {
                if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; }
            }
            if ($id <= 0) { continue; }
            $ca = nombreOuNull($x, ['ca', 'turnover', 'revenue']);
            $tk = nombreOuNull($x, ['tickets', 'receipts', 'transactions']);
            $pr = nombreOuNull($x, ['products', 'items', 'product_count']);
            // Même règle que le P&L : une entrée que le référentiel ne connaît
            // pas et qui n'a rien vendu est technique, pas commerciale.
            if (!isset($noms[$id]) && ($ca ?? 0) <= 0) { continue; }
            $panier = ($ca !== null && $tk !== null && $tk > 0) ? $ca / $tk
                : nombreOuNull($x, ['avg_basket', 'average_basket']);
            $par[$id][$m] = [
                'clientsJour' => ($tk !== null && $jours > 0) ? round($tk / $jours, 1) : null,
                'panier' => $panier !== null ? round($panier, 2) : null,
                'items' => ($pr !== null && $tk !== null && $tk > 0) ? round($pr / $tk, 2) : null,
            ];
            if ($ca !== null) { $tCa += $ca; }
            if ($tk !== null) { $tTk += (int) $tk; $aTk = true; }
            if ($pr !== null) { $tPr += (int) $pr; $aPr = true; }
        }
        // La ligne réseau se calcule sur les SOMMES, pas sur la moyenne des
        // moyennes : un panier réseau est le CA total ÷ les tickets totaux —
        // pondéré de fait par la taille de chaque magasin.
        $reseau[$m] = [
            'clientsJour' => ($aTk && $jours > 0) ? round($tTk / $jours, 1) : null,
            'panier' => ($aTk && $tTk > 0) ? round($tCa / $tTk, 2) : null,
            'items' => ($aPr && $tTk > 0) ? round($tPr / $tTk, 2) : null,
        ];
    }
    $magasins = [];
    foreach ($par as $id => $mois) {
        $magasins[] = ['id' => (string) $id, 'nom' => $noms[$id] ?? ('Magasin ' . $id), 'mois' => $mois];
    }
    usort($magasins, fn ($a, $b) => strcmp($a['nom'], $b['nom']));
    return ['annee' => $annee, 'moisMax' => $moisMax, 'magasins' => $magasins, 'reseau' => $reseau,
        'source' => 'API panel — sales-kpis mois par mois ; clients/jour = tickets ÷ jours du mois (mois en cours : jours écoulés)'];
}

/**
 * Analyse d'une catégorie ou d'une référence dans le temps.
 *
 * Une série se construit en interrogeant l'API sur des bornes successives :
 * aucune route ne rend d'historique par catégorie ou par référence. Le nombre
 * de points est donc PLAFONNÉ — chaque point est un aller-retour, et un
 * graphique de vingt-quatre mois ferait attendre une demi-minute pour une
 * précision que personne ne lit.
 *
 * Deux sources, selon ce qu'on regarde :
 *  · catégorie → /consultant/shops/category-sales, ventilé par boutique ;
 *  · référence → /shops/{id}/products/waste, dont `sold_qty` est une valeur
 *    RÉSEAU. Employée par boutique elle serait fausse ; au niveau réseau,
 *    c'est exactement ce qu'on cherche.
 *
 * Les deux séries mesurent donc le RÉSEAU. Le rebut a été écarté : `waste_qty`
 * est propre au magasin alors que `sold_qty` est réseau — les afficher côte à
 * côte donnait un taux de perte divisé par le nombre de magasins, faux et
 * d'autant plus crédible qu'il paraissait bas. Un rebut réseau exigerait un
 * appel par magasin ET par point (24 allers-retours pour six mois) : hors
 * budget pour un écran interactif.
 */
function ep_produits_analyse(): array
{
    // Trois niveaux, et deux sources bien distinctes. Les GROUPES (douze) sont
    // les seuls que la caisse ventile en chiffre d'affaires ; les CATÉGORIES
    // (quatre-vingt-une) et les références ne se lisent qu'en volume, sur la
    // route des rebuts qui porte `category_name` pour chaque produit.
    $type = (string) ($_GET['type'] ?? 'categorie');
    if (!in_array($type, ['categorie', 'souscategorie', 'produit'], true)) { $type = 'categorie'; }
    $cle  = trim((string) ($_GET['cle'] ?? ''));
    $gran = (string) ($_GET['granularite'] ?? 'mois');
    if (!in_array($gran, ['mois', 'trimestre', 'annee'], true)) { $gran = 'mois'; }
    $out = ['type' => $type, 'cle' => $cle, 'granularite' => $gran,
        'points' => [], 'source' => null, 'motif' => null, 'plafond' => null,
        'libelle' => $cle, 'mesure' => $type === 'categorie' ? "chiffre d'affaires réseau" : 'volume vendu réseau',
        'unite' => $type === 'categorie' ? '€' : 'u'];
    if ($cle === '') { http_response_code(400); return $out + ['error' => 'sélection requise']; }
    if (!PanelApi::configured()) { $out['motif'] = 'compte consultant non configuré'; return $out; }

    // Bornes des points, du plus ancien au plus récent. Le dernier point est
    // marqué « en cours » : un mois entamé comparé à des mois clos ressemble
    // toujours à un effondrement, et ce n'en est pas un.
    $n = $gran === 'mois' ? 6 : ($gran === 'trimestre' ? 4 : 3);
    $out['plafond'] = $n;
    $auj = time();
    $bornes = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        if ($gran === 'mois') {
            $t = strtotime("-$i month", $auj);
            $bornes[] = ['lib' => strftime_fr($t, 'M Y'), 'du' => date('Y-m-01', $t), 'au' => date('Y-m-t', $t)];
        } elseif ($gran === 'trimestre') {
            $t = strtotime('-' . ($i * 3) . ' month', $auj);
            $q = (int) ceil((int) date('n', $t) / 3);
            $m1 = ($q - 1) * 3 + 1;
            $d1 = sprintf('%04d-%02d-01', (int) date('Y', $t), $m1);
            $bornes[] = ['lib' => 'T' . $q . ' ' . date('Y', $t), 'du' => $d1,
                'au' => date('Y-m-t', strtotime($d1 . ' +2 month'))];
        } else {
            $y = (int) date('Y', $auj) - $i;
            $bornes[] = ['lib' => (string) $y, 'du' => $y . '-01-01', 'au' => $y . '-12-31'];
        }
    }

    // Ne jamais interroger au-delà d'aujourd'hui : l'API rendrait une période
    // vide qu'on lirait comme une chute à zéro. Les bornes N-1 reprennent la
    // MÊME étendue décalée d'un an : comparer un mois entamé à un mois entier
    // de l'an dernier annoncerait un effondrement qui n'existe pas.
    foreach ($bornes as $i => $b) {
        $bornes[$i]['au'] = min($b['au'], date('Y-m-d'));
        $bornes[$i]['encours'] = $b['au'] > date('Y-m-d');
        $bornes[$i]['n1du'] = date('Y-m-d', strtotime($b['du'] . ' -1 year'));
        $bornes[$i]['n1au'] = date('Y-m-d', strtotime($bornes[$i]['au'] . ' -1 year'));
    }

    // Les plages élémentaires des deux exercices sont réunies avant tout appel :
    // elles partent ensemble, et celles déjà mémorisées ne repartent pas.
    $cN = $cN1 = $toutes = [];
    foreach ($bornes as $i => $b) {
        $cN[$i]  = analysePlages($type, $b['du'], $b['au']);
        $cN1[$i] = analysePlages($type, $b['n1du'], $b['n1au']);
        $toutes = array_merge($toutes, $cN[$i], $cN1[$i]);
    }
    $agg = analyseAgregats($type, $toutes);

    // Le détail par magasin n'existe que pour les catégories : la route des
    // références rend un `sold_qty` RÉSEAU, identique d'un magasin à l'autre
    // (seul le rebut y est propre au magasin). Plutôt que de présenter cinq
    // courbes superposées qui vaudraient toutes la même chose, l'écran le dit.
    // Le détail par magasin existe aux trois niveaux, mais il ne mesure pas la
    // même chose : le chiffre d'affaires pour les groupes, le REBUT en dessous.
    // Mesuré sur une même référence et un même mois, `sold_qty` vaut 5165 dans
    // les quatre boutiques quand `waste_qty` y vaut 47, 34, 5 et 30 : la vente
    // est une valeur réseau, le rebut non. Tracer quatre courbes de ventes
    // identiques aurait donné un « chacun sa part » entièrement faux.
    $out['parMagasin'] = 'ok';
    $out['parMagasinMesure'] = $type === 'categorie' ? "chiffre d'affaires" : 'rebut';
    $out['parMagasinUnite'] = $type === 'categorie' ? '€' : 'u';
    $out['parMagasinMotif'] = $type === 'categorie' ? null
        : 'Par magasin, ce niveau montre le REBUT : c\'est la seule grandeur que l\'API rende '
        . 'boutique par boutique ici — le volume vendu y est une valeur réseau, identique partout.';
    $out['magasins'] = [];
    $vus = [];
    foreach ($agg as $a) {
        foreach ((array) $a as $sid => $e) {
            if ($type === 'categorie') { $vus[(string) $sid] = true; continue; }
            foreach ((array) ($e['w'] ?? []) as $s2 => $_) { $vus[(string) $s2] = true; }
        }
    }
    $noms = analyseNoms();
    foreach (array_keys($vus) as $sid) {
        $out['magasins'][] = ['id' => (string) $sid, 'nom' => $noms[(string) $sid] ?? ('Magasin ' . $sid)];
    }
    usort($out['magasins'], fn($a, $b) => strcmp($a['nom'], $b['nom']));

    foreach ($bornes as $i => $b) {
        [$val, $par, $rendu, $lib] = analyseLire($type, $agg, $cN[$i], $cle);
        [$n1, $parN1] = analyseLire($type, $agg, $cN1[$i], $cle);
        if ($lib !== null) { $out['libelle'] = $lib; }
        $out['points'][] = ['libelle' => $b['lib'], 'du' => $b['du'], 'au' => $b['au'],
            'valeur' => $val, 'enCours' => $b['encours'],
            'n1' => $n1, 'n1du' => $b['n1du'], 'n1au' => $b['n1au'],
            // L'écart ne se calcule que sur deux chiffres connus : « +100 % »
            // face à un N-1 absent dirait une croissance là où il n'y a qu'un
            // trou dans l'historique.
            'delta' => ($val !== null && $n1 !== null && $n1 != 0) ? round(($val - $n1) / $n1, 4) : null,
            'parMagasin' => $par, 'parMagasinN1' => $parN1,
            'motif' => $val !== null ? null : (!$rendu ? 'aucune donnée'
                : ['categorie' => 'groupe absent de la réponse',
                   'souscategorie' => 'catégorie sans vente sur la période',
                   'produit' => 'référence non vendue sur la période'][$type])];
    }



    $connus = array_filter($out['points'], fn($p) => $p['valeur'] !== null);
    if (!$connus) {
        $raisons = array_unique(array_filter(array_column($out['points'], 'motif')));
        $out['motif'] = 'aucune donnée sur cette sélection — '
            . (PanelApi::$lastError ?: ($raisons ? implode(' ; ', $raisons)
                : 'l\'API n\'a rien rendu pour ces périodes'));
    }
    return $out;
}

/**
 * Plages élémentaires couvrant [du, au] pour le type demandé.
 *
 * `category-sales` expire dès qu'on dépasse le mois : ses points se
 * reconstituent en additionnant leurs mois, ce que la nature additive du CA
 * autorise. La route des références encaisse les bornes larges — la découper
 * obligerait à SUPPOSER que les quantités s'additionnent d'un mois à l'autre,
 * autant le lui demander directement.
 */
function analysePlages(string $type, string $du, string $au): array
{
    if ($du > $au) { return []; }
    if ($type !== 'categorie') { return [$du . '.' . $au]; }
    $out = [];
    foreach (analyseMois($du, $au) as $mm) { $out[] = $mm[0] . '.' . $mm[1]; }
    return $out;
}

/** Identifiant de boutique d'une ligne, quelle que soit son orthographe. */
function analyseShopId(array $ligne): string
{
    foreach (['shop_id', 'id_shop', 'id'] as $c) {
        if (isset($ligne[$c]) && is_numeric($ligne[$c])) { return (string) (int) $ligne[$c]; }
    }
    return '0';
}

/** Noms des boutiques — « Magasin 3 » n'aide personne à lire une courbe. */
function analyseNoms(): array
{
    static $n = null;
    if ($n !== null) { return $n; }
    $n = [];
    foreach (analyseListe(PanelApi::consultantShops()) as $sh) {
        $id = analyseShopId($sh);
        if ($id === '0') { continue; }
        foreach (['representative_name', 'name', 'label'] as $c) {
            if (!empty($sh[$c]) && is_string($sh[$c])) { $n[$id] = trim($sh[$c]); break; }
        }
    }
    return $n;
}

/**
 * Agrégats des plages demandées : mémoire d'abord, API pour le reste.
 *
 * Les catégories sont conservées VENTILÉES PAR BOUTIQUE. Agréger dès la lecture
 * aurait interdit la vue par magasin sans tout relire, et l'agrégat réseau se
 * retrouve de toute façon en sommant — l'inverse n'est pas vrai.
 */
function analyseAgregats(string $type, array $cles): array
{
    $pref = $type === 'categorie' ? 'an.cat2.' : 'an.prod3.';
    $agg = []; $req = [];
    foreach (array_unique($cles) as $k) {
        $c = analyseCache($pref . $k);
        if ($c !== null) { $agg[$k] = $c; continue; }
        [$du, $au] = explode('.', $k, 2);
        if ($type === 'categorie') {
            $req[$k] = '/consultant/shops/category-sales?' . http_build_query(
                ['shop_id' => analyseShop(), 'date_from' => $du, 'date_to' => $au]);
        } else {
            foreach (analyseShops() as $sid) {
                $req[$k . '#' . $sid] = '/shops/' . $sid . '/products/waste?' . http_build_query(
                    ['from' => $du, 'date_from' => $du, 'to' => $au, 'date_to' => $au]);
            }
        }
    }
    foreach (PanelApi::getParallele($req) as $rk => $r) {
        $k = strstr((string) $rk, '#', true) ?: (string) $rk;
        $sidReq = (string) (substr((string) $rk, strlen($k) + 1) ?: '0');
        if ($type === 'categorie') {
            // Le vocabulaire de cette route est celui des GROUPES (douze), pas
            // celui des 81 catégories du catalogue : « Boissons chaudes » n'y
            // existe pas, seul « Boissons ». Le sélecteur étant alimenté par la
            // route elle-même, aucune sélection ne peut retomber dans le vide.
            foreach (analyseListe($r) as $sh) {
                $sid = analyseShopId($sh);
                foreach (($sh['categories'] ?? []) as $c) {
                    $nom = trim((string) ($c['name'] ?? ''));
                    if ($nom === '') { continue; }
                    $v = nombreOuNull($c, ['ca', 'value', 'amount']);
                    $agg[$k][$sid][$nom] = ($agg[$k][$sid][$nom] ?? 0) + (float) ($v ?? 0);
                }
            }
        } else {
            // `sold_qty` est une valeur RÉSEAU rendue à l'identique par chaque
            // boutique : maximum et non somme, faute de quoi les ventes
            // seraient multipliées par le nombre de magasins.
            $lignes = (is_array($r) && isset($r['products']) && is_array($r['products'])) ? $r['products'] : analyseListe($r);
            foreach ($lignes as $p) {
                $pid = trim((string) ($p['id_product'] ?? ''));
                if ($pid === '') { continue; }
                $v = (float) (nombreOuNull($p, ['sold_qty', 'sold', 'quantity']) ?? 0);
                // Le REBUT, lui, est bien propre au magasin — mesuré : pour une
                // même référence sur un même mois, 47 / 34 / 5 / 30 selon la
                // boutique, quand `sold_qty` y vaut 5165 partout. C'est donc la
                // seule grandeur qui permette, sous le niveau groupe, de voir
                // si une boutique décroche du réseau.
                $w = nombreOuNull($p, ['waste_qty', 'waste']);
                if ($w !== null && $sidReq !== '0') { $agg[$k][$pid]['w'][$sidReq] = (float) $w; }
                if (!isset($agg[$k][$pid]['v']) || $v > $agg[$k][$pid]['v']) {
                    // `c` porte la catégorie de la référence : les totaux par
                    // catégorie s'en déduisent en sommant les volumes RÉSEAU
                    // déjà dédoublonnés. `grouped_products` ne rend, lui, que
                    // les catégories ayant connu du rebut — neuf sur quatre-
                    // vingt-une : le lire aurait amputé le sélecteur.
                    $agg[$k][$pid]['n'] = (string) ($p['product_name'] ?? $p['name'] ?? $pid);
                    $agg[$k][$pid]['v'] = $v;
                    $agg[$k][$pid]['c'] = trim((string) ($p['category_name'] ?? ''));
                }
            }
        }
    }
    // Mémoriser les plages CLOSES qui viennent d'être lues. La période en cours
    // ne l'est jamais : elle changera encore aujourd'hui.
    foreach ($req as $rk => $_) {
        $k = strstr((string) $rk, '#', true) ?: (string) $rk;
        if (!isset($agg[$k]) || substr($k, -10) >= date('Y-m-d')) { continue; }
        analyseCacheMaj($pref . $k, $agg[$k]);
    }
    return $agg;
}

/** Valeur d'un point : [total, par magasin, l'API a répondu, libellé]. */
function analyseLire(string $type, array $agg, array $cles, string $sel): array
{
    $val = null; $par = []; $rendu = false; $lib = null;
    foreach ($cles as $k) {
        if (!isset($agg[$k])) { continue; }
        $rendu = true;
        if ($type === 'categorie') {
            foreach ((array) $agg[$k] as $sid => $cats) {
                foreach ((array) $cats as $nom => $ca) {
                    if (strcasecmp((string) $nom, $sel) !== 0) { continue; }
                    $val = ($val ?? 0) + (float) $ca;
                    $par[(string) $sid] = round(($par[(string) $sid] ?? 0) + (float) $ca, 2);
                }
            }
        } elseif ($type === 'souscategorie') {
            foreach ((array) $agg[$k] as $p) {
                if (strcasecmp((string) ($p['c'] ?? ''), $sel) !== 0) { continue; }
                $val = ($val ?? 0) + (float) ($p['v'] ?? 0);
                foreach ((array) ($p['w'] ?? []) as $sid => $w) {
                    $par[(string) $sid] = round(($par[(string) $sid] ?? 0) + (float) $w, 2);
                }
            }
        } elseif (isset($agg[$k][$sel])) {
            $val = ($val ?? 0) + (float) ($agg[$k][$sel]['v'] ?? 0);
            $lib = (string) ($agg[$k][$sel]['n'] ?? $sel);
            foreach ((array) ($agg[$k][$sel]['w'] ?? []) as $sid => $w) {
                $par[(string) $sid] = round(($par[(string) $sid] ?? 0) + (float) $w, 2);
            }
        }
    }
    return [$val === null ? null : round($val, 2), $par, $rendu, $lib];
}

/**
 * Agrégat d'une période close, relu depuis la base.
 *
 * Les périodes closes sont immuables : les relire à chaque ouverture d'écran
 * coûtait cinquante secondes pour six mois, et tenter d'aller plus vite en
 * multipliant les connexions simultanées faisait échouer les appels plutôt
 * qu'accélérer. Seule la période en cours reste interrogée en direct.
 */
function analyseCache(string $cle): ?array
{
    try { $v = setting($cle); } catch (PDOException $e) { return null; }
    return is_array($v) ? $v : null;
}

function analyseCacheMaj(string $cle, array $v): void
{
    try {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$cle, json_encode($v, JSON_UNESCAPED_UNICODE)]);
    } catch (PDOException $e) { /* le cache est un confort, pas une dépendance */ }
}

/** Découpe [du, au] en bornes mensuelles — l'unité que l'API sait servir. */
function analyseMois(string $du, string $au): array
{
    $out = []; $c = date('Y-m-01', strtotime($du));
    while ($c <= $au) {
        $fin = min(date('Y-m-t', strtotime($c)), $au);
        $out[] = [max($c, $du), $fin];
        $c = date('Y-m-01', strtotime($c . ' +1 month'));
    }
    return $out;
}

/** Boutiques du réseau, via l'API. La première sert de laissez-passer aux
 *  routes réseau, qui exigent un `shop_id` sans pour autant filtrer dessus. */
function analyseShops(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $ids = [];
    foreach (analyseListe(PanelApi::consultantShops()) as $s) {
        $v = (int) ($s['id'] ?? $s['id_shop'] ?? $s['shop_id'] ?? 0);
        if ($v > 0) { $ids[] = $v; }
    }
    return $ids = $ids ?: [2];
}

function analyseShop(): int { return analyseShops()[0]; }


/**
 * Vocabulaire analysable — alimenté par les routes qui portent les DONNÉES.
 *
 * C'est le cœur du correctif : le sélecteur était rempli avec les catégories du
 * catalogue (81), alors que les ventes sont ventilées par groupe (12). Aucun
 * nom ne se rencontrait, et l'écran rendait une série vide sans la moindre
 * erreur — un silence qui se lit comme « pas de vente ». Une liste d'options
 * dérivée de la source interdit structurellement ce décalage : on ne peut
 * demander que ce que l'API sait rendre.
 */
function ep_produits_analyse_options(): array
{
    $out = ['categories' => [], 'souscategories' => [], 'produits' => [], 'periode' => null,
        'source' => null, 'erreur' => null];
    if (!PanelApi::configured()) { $out['erreur'] = 'compte consultant non configuré'; return $out; }

    // Fenêtre de référence : le dernier mois de caisse réellement encodé, jamais
    // le mois courant — un mois entamé rendrait une liste tronquée aux seules
    // références déjà vendues, et masquerait le reste de l'assortiment.
    $ref = setting('periodeProduits');
    $per = (is_string($ref) && preg_match('/^\d{4}-\d{2}$/', $ref)) ? $ref : date('Y-m', strtotime('-1 month'));
    $du = $per . '-01';
    $au = min(date('Y-m-t', strtotime($du)), date('Y-m-d'));
    $out['periode'] = $per;

    // Les groupes passent par le MÊME agrégat mémorisé que les séries. Les lire
    // par un appel direct les rendait tributaires d'un aller-retour de douze
    // secondes : sous charge il expirait une fois sur deux et le sélecteur
    // revenait vide — sans erreur, donc indiscernable d'un réseau sans vente.
    $cats = [];
    foreach (analyseAgregats('categorie', analysePlages('categorie', $du, $au)) as $a) {
        foreach ((array) $a as $parCat) {
            foreach ((array) $parCat as $nom => $ca) {
                $cats[(string) $nom] = ($cats[(string) $nom] ?? 0) + (float) $ca;
            }
        }
    }
    $out['source'] = '/consultant/shops/category-sales (par mois, réseau)';
    arsort($cats);
    foreach ($cats as $nom => $ca) { $out['categories'][] = ['cle' => $nom, 'nom' => $nom, 'poids' => round($ca, 2)]; }

    // Références ET sous-catégories viennent de la route qui les mesurera :
    // mêmes identifiants, même agrégation réseau, donc aucune sélection ne peut
    // retomber dans le vide. Triées par volume : sur des centaines de lignes,
    // l'ordre alphabétique suppose de connaître le nom exact avant de chercher.
    $prods = []; $sous = [];
    foreach (analyseAgregats('produit', analysePlages('produit', $du, $au)) as $a) {
        foreach ((array) $a as $pid => $p) {
            $prods[] = ['cle' => (string) $pid, 'nom' => (string) $p['n'], 'poids' => (float) $p['v']];
            $c = trim((string) ($p['c'] ?? ''));
            if ($c !== '') { $sous[$c] = ($sous[$c] ?? 0) + (float) $p['v']; }
        }
    }
    usort($prods, fn($a, $b) => $b['poids'] <=> $a['poids']);
    $out['produits'] = $prods;
    arsort($sous);
    foreach ($sous as $nom => $v) { $out['souscategories'][] = ['cle' => $nom, 'nom' => $nom, 'poids' => round($v, 2)]; }
    // Un niveau vide se signale NIVEAU PAR NIVEAU. Ne parler qu'au cas où tout
    // manque laissait passer le cas réel : les groupes absents, le reste rempli,
    // et un sélecteur muet qui ressemble à un réseau sans vente.
    $vides = [];
    foreach (['categories' => 'groupe', 'souscategories' => 'catégorie', 'produits' => 'référence'] as $k => $lib) {
        if (!$out[$k]) { $vides[] = $lib; }
    }
    if ($vides) {
        $out['erreur'] = 'aucun niveau « ' . implode(' », « ', $vides) . ' » rendu sur ' . $per
            . ' — ' . (PanelApi::$lastError ?: 'l\'API n\'a pas répondu pour cette période');
    }
    return $out;
}

/** Liste d'une réponse d'API, quelle que soit son enveloppe. */
function analyseListe($r): array
{
    if (!is_array($r)) { return []; }
    if (array_is_list($r)) { return $r; }
    foreach (['data', 'items', 'shops', 'results'] as $k) {
        if (isset($r[$k]) && is_array($r[$k]) && array_is_list($r[$k])) { return $r[$k]; }
    }
    return [];
}

/** Libellé de mois en français — `strftime` est déprécié et dépend du locale. */
function strftime_fr(int $ts, string $fmt): string
{
    $M = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
    return $M[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/**
 * Suivi de production du réseau.
 *
 * Source : `product_movement`, qui journalise les mouvements de la caisse
 * avec leur nature (PRODUCTION, WASTE, SALE, RETURN, ADJUSTMENT). C'est la
 * seule trace de ce qui a été RÉELLEMENT produit — les fournées « demandées »
 * n'existent nulle part dans la base, et cet écran ne prétend donc pas les
 * connaître : il rend le produit et le jeté, pas un écart contre une consigne
 * qui n'est pas enregistrée.
 */
function ep_prod_suivi(): array
{
    $periode = (string) ($_GET['periode'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
        // Le dernier mois réellement journalisé, pas le mois courant : la
        // caisse peut s'être arrêtée avant, et un écran vide passerait pour
        // « aucune production ».
        $periode = null;
        try {
            $d = Db::rows("SELECT DATE_FORMAT(MAX(created_at), '%Y-%m') p FROM product_movement");
            if ($d && !empty($d[0]['p'])) { $periode = (string) $d[0]['p']; }
        } catch (PDOException $e) { /* table absente */ }
        if ($periode === null) { $periode = date('Y-m'); }
    }
    $from = $periode . '-01 00:00:00';
    $to   = date('Y-m-d 00:00:00', strtotime($from . ' +1 month'));

    $out = ['periode' => $periode, 'du' => substr($from, 0, 10), 'au' => date('Y-m-d', strtotime($to . ' -1 day')),
        'reseau' => ['produit' => 0, 'jete' => 0, 'vendu' => 0, 'taux' => null],
        'magasins' => [], 'produits' => [], 'motifs' => [], 'source' => 'product_movement',
        // Le journal des fournées n'est pas tenu partout : le dire, parce que
        // « zéro fournée » et « fournées non saisies » se ressemblent à
        // l'écran et ne veulent pas du tout dire la même chose.
        'avertissement' => null];

    $agg = static function (string $group) use ($from, $to): array {
        return Db::rows("SELECT $group AS k,
                                SUM(CASE WHEN movement_type = 'PRODUCTION' THEN quantity ELSE 0 END) prod,
                                SUM(CASE WHEN movement_type = 'WASTE'      THEN quantity ELSE 0 END) jete
                           FROM product_movement
                          WHERE created_at >= ? AND created_at < ?
                            AND movement_type IN ('PRODUCTION','WASTE')
                       GROUP BY k", [$from, $to]);
    };

    // Ventes de la période, par magasin et par référence. C'est le SEUL
    // dénominateur honnête d'un taux de perte ici : mesuré sur la base, deux
    // boutiques sur quatre n'enregistrent aucune fournée (Halle : 0 mouvement
    // PRODUCTION pour 21 730 € de ventes en juillet), si bien qu'un taux
    // jeté/(produit+jeté) leur attribuerait 100 % de perte pour la seule
    // raison qu'elles ne remplissent pas ce champ. C'est aussi la définition
    // employée par le scoring des références : deux écrans ne doivent pas
    // calculer la même chose de deux façons.
    $vendShop = []; $vendProd = [];
    try {
        foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(8000) */ t.id_shop, SUM(tp.quantity) q
                             FROM transaction t JOIN transaction_product tp ON tp.id_transaction = t.id
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                         GROUP BY t.id_shop", [$from, $to]) as $v) {
            $vendShop[(int) $v['id_shop']] = (float) $v['q'];
        }
        foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(8000) */ tp.id_product, SUM(tp.quantity) q
                             FROM transaction t JOIN transaction_product tp ON tp.id_transaction = t.id
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                         GROUP BY tp.id_product", [$from, $to]) as $v) {
            $vendProd[(int) $v['id_product']] = (float) $v['q'];
        }
    } catch (PDOException $e) { /* caisse indisponible : taux non calculable */ }

    try {
        $noms = [];
        foreach (Db::rows('SELECT id, name FROM shops') as $s) { $noms[(int) $s['id']] = (string) $s['name']; }
        $sansJournal = [];
        foreach ($agg('id_shop') as $r) {
            $sid = (int) $r['k'];
            $p = (float) $r['prod']; $j = (float) $r['jete'];
            $v = $vendShop[$sid] ?? 0.0;
            $den = $v + $j;
            $nom = $noms[$sid] ?? ('Magasin ' . $sid);
            // Une boutique qui vend sans jamais déclarer de fournée ne produit
            // pas « zéro » : elle ne tient pas le journal.
            $tient = $p > 0 || $v <= 0;
            if (!$tient) { $sansJournal[] = $nom; }
            $out['magasins'][] = ['shopId' => (string) $sid, 'magasin' => $nom,
                'produit' => (int) round($p), 'jete' => (int) round($j), 'vendu' => (int) round($v),
                'taux' => $den > 0 ? round($j / $den, 4) : null,
                'journalTenu' => $tient];
            $out['reseau']['produit'] += (int) round($p);
            $out['reseau']['jete']    += (int) round($j);
            $out['reseau']['vendu']   += (int) round($v);
        }
        usort($out['magasins'], fn($a, $b) => ($b['taux'] ?? -1) <=> ($a['taux'] ?? -1));
        if ($sansJournal) {
            $out['avertissement'] = count($sansJournal) . ' boutique(s) ne déclarent aucune fournée ('
                . implode(', ', $sansJournal) . ') — les volumes produits y sont incomplets,'
                . ' le taux de perte reste calculé sur les ventes';
        }

        $pn = [];
        try {
            foreach (Db::rows('SELECT id, name FROM product') as $p) { $pn[(int) $p['id']] = (string) $p['name']; }
        } catch (PDOException $e) { /* noms indisponibles */ }
        foreach ($agg('id_product') as $r) {
            $pid = (int) $r['k'];
            $p = (float) $r['prod']; $j = (float) $r['jete'];
            $v = $vendProd[$pid] ?? 0.0;
            $den = $v + $j;
            if ($j <= 0 && $p <= 0) { continue; }
            $out['produits'][] = ['produitId' => (string) $pid,
                'nom' => $pn[$pid] ?? ('#' . $pid),
                'produit' => (int) round($p), 'jete' => (int) round($j), 'vendu' => (int) round($v),
                'taux' => $den > 0 ? round($j / $den, 4) : null];
        }
        usort($out['produits'], fn($a, $b) => $b['jete'] <=> $a['jete']);
        $out['produits'] = array_slice($out['produits'], 0, 40);

        foreach (Db::rows("SELECT reason, COUNT(*) n, SUM(quantity) q FROM product_movement
                            WHERE created_at >= ? AND created_at < ? AND movement_type = 'WASTE'
                         GROUP BY reason ORDER BY q DESC LIMIT 12", [$from, $to]) as $m) {
            $out['motifs'][] = ['motif' => (string) ($m['reason'] ?? ''), 'lignes' => (int) $m['n'],
                'quantite' => (int) round((float) $m['q'])];
        }
    } catch (PDOException $e) {
        $out['erreur'] = 'journal des mouvements indisponible';
        return $out;
    }

    $den = $out['reseau']['vendu'] + $out['reseau']['jete'];
    $out['reseau']['taux'] = $den > 0 ? round($out['reseau']['jete'] / $den, 4) : null;
    return $out;
}

/**
 * Catégories produit indexées par id, avec leur groupe.
 * La liaison porte `id_category` / `id_group`, et une catégorie peut relever
 * de PLUSIEURS groupes (les boissons sont aussi du traiteur) : sans
 * regroupement, la jointure dupliquerait la catégorie autant de fois.
 * Rend null si la base partagée n'est pas là.
 */
function catalogueCategories(): ?array
{
    $sql = "SELECT c.id, c.name,
                   GROUP_CONCAT(DISTINCT g.name ORDER BY g.id SEPARATOR ' · ') AS groupe
              FROM product_category c
         LEFT JOIN product_category_group_connection k ON k.id_category = c.id
         LEFT JOIN product_category_group g ON g.id = k.id_group
          GROUP BY c.id, c.name";
    try {
        $rows = Db::rows($sql);
    } catch (PDOException $e) {
        // Le regroupement est un confort ; la catégorie, elle, est nécessaire.
        try { $rows = Db::rows('SELECT id, name, NULL AS groupe FROM product_category'); }
        catch (PDOException $e2) { return null; }
    }
    $cat = [];
    foreach ($rows as $c) {
        $cat[(int) $c['id']] = ['nom' => (string) $c['name'],
            'groupe' => !empty($c['groupe']) ? (string) $c['groupe'] : null];
    }
    return $cat ?: null;
}

/**
 * Coût matière par référence, depuis les recettes du réseau.
 *
 * `product` ne porte aucun coût : il vit dans `recipe_cost`, rattaché à la
 * recette et non au produit. Deux natures de lignes s'y côtoient — le coût
 * de référence du réseau (id_shop = 0, price_type « suggested ») et le coût
 * recalculé par magasin. On préfère la référence réseau ; à défaut, la
 * moyenne des magasins. Les zéros sont écartés : ils signifient « pas encore
 * calculé », pas « gratuit », et les prendre pour argent comptant afficherait
 * une marge de 100 %.
 */
function catalogueCouts(): array
{
    $out = [];
    try {
        // Le rendement divise le coût quand la recette en déclare un. Mesuré
        // sur la base : il vaut 1,00 partout aujourd'hui, la division ne change
        // donc aucun chiffre — elle protège d'une recette future au rendement
        // multiple. Les coûts aberrants constatés (un cannelloni à 734 € de
        // matière pour 7,50 € de vente) ne viennent PAS de là : ce sont des
        // recettes mal chiffrées en amont. Seul le contrôle de vraisemblance
        // ci-dessous les empêche de nourrir le score.
        $rows = Db::rows("SELECT p.id AS pid, r.yield_quantity AS rendement,
                                 AVG(CASE WHEN rc.id_shop = 0 AND rc.calculated_cost_net > 0
                                          THEN rc.calculated_cost_net END) AS reseau,
                                 AVG(CASE WHEN rc.id_shop > 0 AND rc.calculated_cost_net > 0
                                          THEN rc.calculated_cost_net END) AS magasins
                            FROM product p
                            JOIN recipe_cost rc ON rc.id_recipe = p.id_recipe
                       LEFT JOIN product_recipe r ON r.id = p.id_recipe
                           WHERE p.id_recipe IS NOT NULL AND p.is_active = 1
                        GROUP BY p.id, r.yield_quantity");
    } catch (PDOException $e) { return []; }
    foreach ($rows as $r) {
        $res = $r['reseau'] !== null ? (float) $r['reseau'] : null;
        $mag = $r['magasins'] !== null ? (float) $r['magasins'] : null;
        $v = $res ?? $mag;
        if ($v === null || $v <= 0) { continue; }
        $rend = $r['rendement'] !== null ? (float) $r['rendement'] : 1.0;
        if ($rend > 0) { $v /= $rend; }
        $out[(int) $r['pid']] = ['mat' => round($v, 3), 'rendement' => $rend,
            'source' => $res !== null ? 'recette réseau' : 'moyenne magasins'];
    }

    // Les références que recipe_cost ne couvre pas (~289 sur 711) ne restent
    // plus sans coût : le panel les chiffre aussi — chaque ligne de
    // GET /shops/{id}/products/available porte recipe_cost_net, la même
    // nature que calculated_cost_net. Il ne fait que COMBLER les trous : la
    // recette locale garde la main quand elle existe, et la saisie du cockpit
    // prime toujours en aval. Servi ici et non chez chaque appelant : trois
    // écrans lisent ce coût, deux réponses différentes sur la même référence
    // seraient pires que pas de coût du tout.
    //
    // Et le panel se lit EN CACHE (6 h), pas à chaque requête : mesuré en
    // production, l'appel en direct portait /products/scoring à 13 s quand
    // les trente lectures du chargement partent ensemble — au-delà du délai
    // client de 20 s pour certains navigateurs, et l'écran s'affichait vide.
    // Des coûts de recette vieux de quelques heures ne changent aucun
    // arbitrage ; un écran vide, si.
    foreach (coutsPanelEnCache() as $pid => $c) {
        if (!isset($out[(int) $pid])) {
            $out[(int) $pid] = ['mat' => (float) $c, 'rendement' => 1.0, 'source' => 'panel (products/available)'];
        }
    }
    return $out;
}

/**
 * Les coûts matière du panel, servis depuis ceo_app_setting (6 heures).
 *
 * Cache PÉRIMÉ plutôt que vide : si le panel ne répond pas au moment du
 * rafraîchissement, on ressert la dernière lecture réussie — un coût d'hier
 * vaut mieux qu'une marge qui disparaît de l'écran. Un cache vide n'est
 * jamais écrit : il fixerait une panne passagère pour six heures.
 *
 * @return array<int|string, float>
 */
function coutsPanelEnCache(): array
{
    $cache = setting('coutsPanel');
    $frais = is_array($cache) && isset($cache['ts'], $cache['couts'])
        && (time() - (int) $cache['ts']) < 6 * 3600;
    if ($frais) { return (array) $cache['couts']; }

    $panel = PanelApi::coutsMatiere();
    if ($panel !== []) {
        try {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['coutsPanel', json_encode(['ts' => time(), 'couts' => $panel])]);
        } catch (PDOException $e) { /* pas de cache : on servira quand même */ }
        return $panel;
    }
    return is_array($cache) && isset($cache['couts']) ? (array) $cache['couts'] : [];
}

/**
 * Un coût matière est-il exploitable pour calculer une marge ?
 *
 * Le seuil bas est un réglage (`production.coutRatioMin`, 5 % par défaut) et
 * non une constante cachée : c'est un jugement métier, il doit pouvoir se
 * discuter. Un coût nul ou négatif n'est jamais crédible.
 */
function coutVraisemblable(?float $mat, ?float $prix): bool
{
    if ($mat === null || $prix === null || $prix <= 0) { return true; }  // rien à juger
    if ($mat <= 0 || $mat >= $prix) { return false; }
    $p = setting('production', []);
    $min = (is_array($p) && isset($p['coutRatioMin'])) ? (float) $p['coutRatioMin'] : 0.05;
    if ($min <= 0) { return true; }
    return ($mat / $prix) >= $min;
}

/** Prix de vente réellement pratiqué, moyenne réseau (`shop_product`). */
function cataloguePrix(): array
{
    $out = [];
    try {
        $rows = Db::rows('SELECT id_product, AVG(portion_price) prix
                            FROM shop_product WHERE portion_price > 0 GROUP BY id_product');
    } catch (PDOException $e) { return []; }
    foreach ($rows as $r) { $out[(int) $r['id_product']] = round((float) $r['prix'], 2); }
    return $out;
}

/**
 * Lecture du catalogue réel dans la base partagée.
 * Rend null — et non un tableau vide — si les tables ne sont pas là : un vide
 * se confondrait avec « catalogue sans produit » et masquerait la panne.
 */
/**
 * La fiche de production porte-t-elle au moins une valeur ?
 *
 * L'existence de la ligne ne suffit pas : cocher « obligatoire » puis décocher
 * laisse une ligne à zéro derrière, et compter cette ligne comme une fiche
 * remplie gonflait la couverture du catalogue sans qu'une seule donnée ait été
 * saisie. On regarde donc le contenu, pas la présence.
 */
function ficheRemplie(?array $e): bool
{
    if ($e === null) { return false; }
    foreach (['prep', 'cuisson', 'fin', 'bmin', 'four', 'dlv', 'qmin'] as $k) {
        if ((int) ($e[$k] ?? 0) > 0) { return true; }
    }
    if ((int) ($e['bmult'] ?? 1) > 1) { return true; }
    if (($e['mat'] ?? null) !== null || ($e['prix'] ?? null) !== null) { return true; }
    if ((string) ($e['profil'] ?? '') !== '') { return true; }
    return !empty($e['must']);
}

function ep_prod_catalogue_reel(array $enrich, array $parRef, array $plano): ?array
{
    // Catégorie + groupe. La liaison passe par une table dédiée ; si elle
    // manque, on garde la catégorie et on perd seulement le regroupement.
    $cat = catalogueCategories();
    if ($cat === null) { return null; }

    // Gammes saisonnières : plusieurs périodes possibles par référence.
    $per = [];
    try {
        foreach (Db::rows('SELECT k.id_product, p.name
                             FROM product_availability_period_connection k
                             JOIN product_availability_period p ON p.id = k.id_period
                            WHERE p.is_active = 1') as $r) {
            $per[(int) $r['id_product']][] = (string) $r['name'];
        }
    } catch (PDOException $e) { /* sans gamme : le produit reste permanent */ }

    try {
        $prods = Db::rows('SELECT id, name, id_category, id_recipe, is_active,
                                  suggested_sale_price, expected_margin, shelf_life_minutes,
                                  is_prepared_before_sales, single_weight, nutriscore, allergene
                             FROM product WHERE is_active = 1 ORDER BY id_category, name');
    } catch (PDOException $e) { return null; }
    if (!$prods) { return null; }
    $fins = [];
    foreach (Db::rows('SELECT ref, end_on, note FROM ceo_prod_fin') as $fx) {
        $fins[(string) $fx['ref']] = ['end_on' => $fx['end_on'], 'note' => $fx['note']];
    }

    $couts = catalogueCouts();
    $prixR = cataloguePrix();
    $out = [];
    foreach ($prods as $p) {
        $pid = (int) $p['id'];
        // Un identifiant négatif n'est pas une référence : le catalogue du panel
        // en porte un (« 1/4 - Chocolat », id -99, catégorie -1 qui n'existe
        // pas). Trié en tête, il ouvrait le référentiel sur une ligne qu'aucun
        // magasin ne peut tenir. Il est écarté ici et DÉCLARÉ dans les lacunes,
        // pour qu'on sache qu'il a été écarté au lieu de le croire absent.
        if ($pid <= 0) { continue; }
        $c   = $cat[(int) $p['id_category']] ?? null;
        $e   = $enrich[$pid] ?? ($parRef[(string) $pid] ?? null);
        $ref = $e !== null ? (string) $e['ref'] : (string) $pid;
        $pl  = $plano[$ref] ?? null;

        // Prix : la saisie réseau prime, puis le prix réellement pratiqué en
        // boutique (`shop_product`), puis seulement le prix conseillé de la
        // fiche — qui vaut 1,00 partout, donc ne veut rien dire.
        $prix = $e !== null && $e['prix'] !== null ? (float) $e['prix'] : null;
        $prixSrc = $prix !== null ? 'réseau' : null;
        if ($prix === null && isset($prixR[$pid])) { $prix = $prixR[$pid]; $prixSrc = 'boutiques'; }
        if ($prix === null && (float) $p['suggested_sale_price'] > 0) {
            $prix = (float) $p['suggested_sale_price']; $prixSrc = 'fiche';
        }
        // Coût matière : la saisie cockpit prime, sinon la recette du réseau.
        $mat = $e !== null && $e['mat'] !== null ? (float) $e['mat'] : null;
        $matSrc = $mat !== null ? 'saisi' : null;
        if ($mat === null && isset($couts[$pid])) {
            $mat = $couts[$pid]['mat']; $matSrc = $couts[$pid]['source'];
        }
        // Vraisemblance du coût. Au-dessus du prix, la recette est mal
        // chiffrée ; très en dessous, elle est incomplète — un granola à
        // 0,01 € de matière pour 8,95 € donne 99,9 % de marge et hisserait la
        // référence en tête du critère. Les deux erreurs se valent, on écarte
        // les deux. On MONTRE le coût malgré tout : c'est ce qui permet de le
        // corriger à la source.
        $matFiable = coutVraisemblable($mat, $prix);

        $dlv = $e !== null && (int) $e['dlv'] > 0 ? (int) $e['dlv'] : null;
        if ($dlv === null && (int) $p['shelf_life_minutes'] > 0) {
            $dlv = (int) round(((int) $p['shelf_life_minutes']) / 60);
        }

        $fin = $fins[$ref] ?? null;
        $out[] = [
            'ref' => $ref, 'pwaId' => $pid, 'nom' => (string) $p['name'],
            // Fin de gamme annoncée : la date et la note voyagent avec la
            // référence, chaque écran qui l'affiche peut la marquer.
            'finLe' => $fin['end_on'] ?? null, 'finNote' => $fin['note'] ?? null,
            'categorie' => $c['nom'] ?? '', 'groupe' => $c['groupe'] ?? null,
            'categorieId' => (int) $p['id_category'],
            'prep'    => $e ? (int) $e['prep'] : 0,
            'cuisson' => $e ? (int) $e['cuisson'] : 0,
            'fin'     => $e ? (int) $e['fin'] : 0,
            'bmin'    => $e ? (int) $e['bmin'] : 0,
            'bmult'   => $e ? (int) $e['bmult'] : 1,
            'four'    => $e ? (int) $e['four'] : 0,
            'dlv' => $dlv ?? 0, 'mat' => $mat, 'prix' => $prix,
            'matSource' => $matSrc, 'prixSource' => $prixSrc, 'matFiable' => $matFiable,
            'marge'    => ($matFiable && $mat !== null && $prix !== null) ? round($prix - $mat, 3) : null,
            'margePct' => ($matFiable && $mat !== null && $prix > 0) ? round(($prix - $mat) / $prix, 4) : null,
            // Marge NETTE : la commission de marque retirée. Elle vient de la
            // centrale d'achat, qui n'a plus son propre écran catalogue —
            // deux catalogues sur les mêmes 711 références se contredisent tôt
            // ou tard. Le référentiel porte donc les deux marges.
            'commission'  => $matFiable ? caCommission($prix) : null,
            'margeNette'  => $matFiable ? caMargeNette($prix, $mat) : null,
            'margeNettePct' => $matFiable ? caMargeNettePct($prix, $mat) : null,
            'margeAttendue' => $p['expected_margin'] !== null && (float) $p['expected_margin'] > 0
                ? round((float) $p['expected_margin'], 2) : null,
            'must'   => $e ? (bool) $e['must'] : false,
            'qmin'   => $e ? (int) $e['qmin'] : 0,
            'profil' => $e ? (string) $e['profil'] : '',
            'periods' => $per[$pid] ?? [],
            'recetteId' => $p['id_recipe'] !== null ? (int) $p['id_recipe'] : null,
            'prepare'   => (int) $p['is_prepared_before_sales'] === 1,
            'poids'     => (int) $p['single_weight'] ?: null,
            'parametre' => ficheRemplie($e),
            'zone'   => $pl ? $pl['zone'] : null,
            'meuble' => $pl ? $pl['meuble'] : null,
            'niveau' => $pl ? $pl['niveau'] : null,
            'slot'   => $pl && $pl['slot'] !== null ? (int) $pl['slot'] : null,
        ];
    }
    return $out;
}

/**
 * GET /production/categories — catégories produit du réseau.
 *
 * Source : API du panel (/product-categories). Repli sur les tables partagées
 * (product_category ⨝ product_category_group) si l'API n'est pas configurée —
 * les deux existent, autant ne pas dépendre d'une seule.
 *
 * `debug=1` rend les clés brutes : la forme n'est pas documentée ici, et un
 * mapping deviné produirait un référentiel faux sans erreur visible.
 */
function ep_prod_categories(): array
{
    $debug = !empty($_GET['debug']);
    $out = ['source' => null, 'categories' => [], 'erreur' => null];

    if (PanelApi::configured()) {
        $rows = PanelApi::productCategories();
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            if ($debug) { $out['clesBrut'] = array_slice(array_keys($rows[0]), 0, 25); $out['premier'] = $rows[0]; }
            foreach ($rows as $c) {
                $id = null;
                foreach (['id', 'id_category', 'category_id'] as $k) {
                    if (isset($c[$k]) && is_numeric($c[$k])) { $id = (int) $c[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'category_name', 'label', 'title', 'nom'] as $k) {
                    if (!empty($c[$k]) && is_string($c[$k])) { $nom = trim($c[$k]); break; }
                }
                if ($nom === '') { continue; }
                $grp = '';
                foreach (['group_name', 'category_group_name', 'group', 'parent_name'] as $k) {
                    if (!empty($c[$k]) && is_string($c[$k])) { $grp = trim($c[$k]); break; }
                }
                $out['categories'][] = ['id' => $id, 'nom' => $nom, 'groupe' => $grp !== '' ? $grp : null];
            }
            if ($out['categories']) {
                // L'API rend les catégories SANS leur groupe : mesuré en ligne,
                // 81 sur 81 arrivaient orphelines. Le rattachement n'existe que
                // dans la base (product_category_group_connection). On garde
                // donc les intitulés de l'API, qui font foi, et on va chercher
                // le groupe là où il se trouve — plutôt que de livrer un arbre
                // sans branches.
                $sans = 0;
                foreach ($out['categories'] as $c) { if ($c['groupe'] === null) { $sans++; } }
                if ($sans > 0) {
                    $ref = catalogueCategories();
                    if ($ref !== null) {
                        $repris = 0;
                        foreach ($out['categories'] as &$c) {
                            if ($c['groupe'] === null && $c['id'] !== null && !empty($ref[$c['id']]['groupe'])) {
                                $c['groupe'] = $ref[$c['id']]['groupe'];
                                $repris++;
                            }
                        }
                        unset($c);
                        if ($repris > 0) { $out['source'] = 'api + groupes atelierby_db'; }
                    }
                }
                return $out;
            }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    // Repli base partagée. `catalogueCategories()` porte la jointure correcte
    // (product_category_group_connection : id_category / id_group) et regroupe
    // les catégories rattachées à plusieurs groupes, qui sinon apparaîtraient
    // en double.
    if (!$out['categories']) {
        $cat = catalogueCategories();
        if ($cat !== null) {
            foreach ($cat as $id => $c) {
                $out['categories'][] = ['id' => $id, 'nom' => $c['nom'], 'groupe' => $c['groupe']];
            }
            usort($out['categories'], function ($a, $b) {
                return [$a['groupe'] === null, (string) $a['groupe'], $a['id']]
                   <=> [$b['groupe'] === null, (string) $b['groupe'], $b['id']];
            });
            $out['source'] = 'atelierby_db';
        }
    }
    return $out;
}

/**
 * Gammes saisonnières du réseau, avec leurs intitulés traduits.
 *
 * Les gammes datent l'offre (printanière, estivale, Saint-Nicolas…) et 441
 * références en portent au moins une. La base tient les dates et le
 * rattachement ; les traductions n'existent que dans l'API — la table d'alias
 * de la base est vide, et le réseau est bilingue.
 */
function ep_prod_periodes(): array
{
    $out = ['source' => null, 'chemin' => null, 'periodes' => [], 'erreur' => null];

    // Combien de références par gamme : une gamme sans référence encombre un
    // filtre sans rien filtrer.
    $n = [];
    try {
        foreach (Db::rows('SELECT id_period, COUNT(DISTINCT id_product) n
                             FROM product_availability_period_connection GROUP BY id_period') as $r) {
            $n[(int) $r['id_period']] = (int) $r['n'];
        }
    } catch (PDOException $e) { /* rattachement indisponible */ }

    try {
        foreach (Db::rows('SELECT id, name, description, start_date, end_date,
                                  is_recurring, is_active
                             FROM product_availability_period ORDER BY from_md, id') as $p) {
            $id = (int) $p['id'];
            $out['periodes'][] = [
                'id' => $id, 'nom' => (string) $p['name'],
                'description' => $p['description'] !== null ? (string) $p['description'] : null,
                'debut' => $p['start_date'], 'fin' => $p['end_date'],
                'recurrente' => (int) $p['is_recurring'] === 1,
                'active' => (int) $p['is_active'] === 1,
                'references' => $n[$id] ?? 0,
                'alias' => [],
            ];
        }
        if ($out['periodes']) { $out['source'] = 'atelierby_db'; }
    } catch (PDOException $e) {
        $out['erreur'] = $e->getMessage();
    }

    if (!PanelApi::configured()) { return $out; }

    // Les gammes elles-mêmes, si la base n'a rien pu rendre.
    if (!$out['periodes']) {
        foreach (PanelApi::availabilityPeriods() as $p) {
            $id = null;
            foreach (['id', 'id_period', 'period_id'] as $k) {
                if (isset($p[$k]) && is_numeric($p[$k])) { $id = (int) $p[$k]; break; }
            }
            $nom = '';
            foreach (['name', 'period_name', 'label', 'title', 'nom'] as $k) {
                if (!empty($p[$k]) && is_string($p[$k])) { $nom = trim($p[$k]); break; }
            }
            if ($nom === '') { continue; }
            $out['periodes'][] = ['id' => $id, 'nom' => $nom, 'description' => null,
                'debut' => $p['start_date'] ?? null, 'fin' => $p['end_date'] ?? null,
                'recurrente' => !empty($p['is_recurring']), 'active' => !isset($p['is_active']) || !empty($p['is_active']),
                'references' => $id !== null ? ($n[$id] ?? 0) : 0, 'alias' => []];
        }
        if ($out['periodes']) { $out['source'] = 'api'; $out['chemin'] = PanelApi::$lastPath; }
    }

    // Traductions. Clés incertaines : on reconnaît la langue et l'intitulé
    // parmi les écritures usuelles plutôt que d'en imposer une.
    $alias = PanelApi::periodNameAliases();
    if ($alias) {
        // Les clés ne sont pas documentées : `?debug=1` rend la forme réelle
        // d'une ligne. Sans cela, un alias non reconnu se solde par une gamme
        // sans traduction et par AUCUNE erreur — donc par rien à corriger.
        if (!empty($_GET['debug'])) {
            $out['aliasBrut'] = array_slice($alias, 0, 3);
            $out['aliasCles'] = array_slice(array_keys($alias[0]), 0, 25);
            $out['aliasNb']   = count($alias);
        }
        // Forme réelle constatée en ligne : fk_id / base_value / alias_value /
        // effective_value / lang_code. `alias_value` porte la traduction quand
        // elle est saisie ; `effective_value` porte ce qu'il faut afficher et
        // retombe sur l'intitulé de base sinon.
        $par = [];      // traductions réellement saisies
        $eff = [];      // intitulé à afficher
        $rattaches = 0;
        foreach ($alias as $a) {
            $id = null;
            foreach (['fk_id', 'id_period', 'period_id', 'id_product_availability_period', 'id'] as $k) {
                if (isset($a[$k]) && is_numeric($a[$k])) { $id = (int) $a[$k]; break; }
            }
            if ($id === null) { continue; }
            $rattaches++;
            $lang = '';
            foreach (['lang_code', 'lang', 'language', 'locale', 'language_code', 'code'] as $k) {
                if (!empty($a[$k]) && is_string($a[$k])) { $lang = strtolower(trim($a[$k])); break; }
            }
            foreach (['effective_value', 'base_value'] as $k) {
                if (!empty($a[$k]) && is_string($a[$k])) { $eff[$id] = trim($a[$k]); break; }
            }
            $val = '';
            foreach (['alias_value', 'translation', 'alias', 'value', 'text'] as $k) {
                if (!empty($a[$k]) && is_string($a[$k])) { $val = trim($a[$k]); break; }
            }
            if ($val === '') { continue; }            // ligne présente, traduction non saisie
            $par[$id][$lang !== '' ? $lang : 'defaut'] = $val;
        }
        $traduites = 0;
        foreach ($out['periodes'] as &$p) {
            if ($p['id'] === null) { continue; }
            if (isset($par[$p['id']])) { $p['alias'] = $par[$p['id']]; $traduites++; }
            if (isset($eff[$p['id']])) { $p['nomAffiche'] = $eff[$p['id']]; }
        }
        unset($p);
        $out['aliasSource'] = PanelApi::$lastPath;
        $out['aliasRecus'] = count($alias);
        $out['aliasTraduits'] = $traduites;
        // Deux situations très différentes, qu'il ne faut pas confondre : des
        // alias qui ne se rattachent à rien (rapprochement cassé, à corriger
        // ici) et des alias bien rattachés mais vides (traductions jamais
        // saisies, à compléter dans le panel).
        if ($rattaches === 0) {
            $out['aliasErreur'] = count($alias) . ' alias reçus, aucun rattaché à une gamme'
                . ' — clés inattendues (voir ?debug=1)';
        } elseif ($traduites === 0) {
            $out['aliasInfo'] = count($alias) . ' alias rattachés mais aucune traduction saisie'
                . ' — les gammes s\'affichent dans leur seule langue de base';
        }
    } elseif (PanelApi::$lastError !== null) {
        $out['aliasErreur'] = PanelApi::$lastError;
    }
    return $out;
}

/**
 * Fabrique la fonction qui met en forme UNE référence, quelle que soit la
 * branche par laquelle on y arrive — catégorie, gamme, assortiment.
 *
 * Les prix, coûts et paramètres de production sont chargés une seule fois et
 * partagés par toutes les lignes. Deux écrans qui répondraient différemment
 * sur la même référence seraient pires que pas d'écran du tout : c'est
 * pourquoi le contrôle de vraisemblance vit ici, et nulle part ailleurs.
 *
 * @return callable(int,string):array
 */
function produitLigne(): callable
{
    $couts = catalogueCouts();
    $prixR = cataloguePrix();
    $enrich = [];
    try {
        foreach (Db::rows('SELECT pwa_id, mat, prix, must FROM ceo_prod_product WHERE pwa_id IS NOT NULL') as $r) {
            $enrich[(int) $r['pwa_id']] = $r;
        }
    } catch (PDOException $e) { /* référentiel de production absent */ }

    return function (int $pid, string $nom) use ($couts, $prixR, $enrich): array {
        $e = $enrich[$pid] ?? null;
        $prix = $e !== null && $e['prix'] !== null ? (float) $e['prix'] : ($prixR[$pid] ?? null);
        $mat  = $e !== null && $e['mat'] !== null ? (float) $e['mat'] : ($couts[$pid]['mat'] ?? null);
        $ok   = coutVraisemblable($mat, $prix);
        return ['id' => (string) $pid, 'nom' => $nom, 'prix' => $prix, 'mat' => $mat,
            'matFiable' => $ok,
            'margePct' => ($ok && $mat !== null && $prix > 0) ? round(($prix - $mat) / $prix, 4) : null,
            'must' => $e !== null ? (bool) $e['must'] : false];
    };
}

/**
 * Références d'une gamme saisonnière.
 * Même mise en forme que l'ouverture d'une catégorie : c'est la même
 * référence, vue par une autre branche de l'arbre.
 */
function ep_prod_periode_produits(): array
{
    $pid = (int) ($_GET['id'] ?? 0);
    if ($pid <= 0) { http_response_code(400); return ['error' => 'gamme requise']; }

    $out = ['periodeId' => $pid, 'gamme' => null, 'source' => null, 'chemin' => null,
        'produits' => [], 'erreur' => null];
    try {
        $g = Db::rows('SELECT name FROM product_availability_period WHERE id = ?', [$pid]);
        if ($g) { $out['gamme'] = (string) $g[0]['name']; }
    } catch (PDOException $e) { /* intitulé indisponible */ }

    $ligne = produitLigne();

    if (PanelApi::configured()) {
        $rows = PanelApi::periodProducts($pid);
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            foreach ($rows as $p) {
                $id = 0;
                foreach (['id', 'id_product', 'product_id', 'fk_id'] as $k) {
                    if (isset($p[$k]) && is_numeric($p[$k])) { $id = (int) $p[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'product_name', 'base_value', 'effective_value', 'label', 'title', 'nom'] as $k) {
                    if (!empty($p[$k]) && is_string($p[$k])) { $nom = trim($p[$k]); break; }
                }
                if ($id <= 0 || $nom === '') { continue; }
                $out['produits'][] = $ligne($id, $nom);
            }
            if ($out['produits']) { return $out; }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    try {
        foreach (Db::rows('SELECT p.id, p.name
                             FROM product_availability_period_connection k
                             JOIN product p ON p.id = k.id_product
                            WHERE k.id_period = ? AND p.is_active = 1
                         ORDER BY p.name', [$pid]) as $p) {
            $out['produits'][] = $ligne((int) $p['id'], (string) $p['name']);
        }
        if ($out['produits']) { $out['source'] = 'atelierby_db'; }
    } catch (PDOException $e) {
        $out['erreur'] = $out['erreur'] ?? $e->getMessage();
    }
    return $out;
}

/**
 * Références d'une catégorie, pour ouvrir une branche de l'arbre produit.
 *
 * L'API donne la liste faisant foi ; le coût matière, la marge et les
 * paramètres de production viennent de chez nous. Repli sur la base partagée,
 * qui porte le même rattachement (`product.id_category`).
 */
function ep_prod_categorie_produits(): array
{
    $cid = (int) ($_GET['id'] ?? 0);
    if ($cid <= 0) { http_response_code(400); return ['error' => 'catégorie requise']; }

    $cats = catalogueCategories();
    $out = ['categorieId' => $cid, 'categorie' => $cats[$cid]['nom'] ?? null,
        'groupe' => $cats[$cid]['groupe'] ?? null,
        'source' => null, 'chemin' => null, 'produits' => [], 'erreur' => null];

    $ligne = produitLigne();

    if (PanelApi::configured()) {
        $rows = PanelApi::categoryProducts($cid);
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            foreach ($rows as $p) {
                $pid = 0;
                foreach (['id', 'id_product', 'product_id'] as $k) {
                    if (isset($p[$k]) && is_numeric($p[$k])) { $pid = (int) $p[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'product_name', 'label', 'title', 'nom'] as $k) {
                    if (!empty($p[$k]) && is_string($p[$k])) { $nom = trim($p[$k]); break; }
                }
                if ($pid <= 0 || $nom === '') { continue; }
                $out['produits'][] = $ligne($pid, $nom);
            }
            if ($out['produits']) { return $out; }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    try {
        foreach (Db::rows('SELECT id, name FROM product WHERE id_category = ? AND is_active = 1 ORDER BY name',
            [$cid]) as $p) {
            $out['produits'][] = $ligne((int) $p['id'], (string) $p['name']);
        }
        if ($out['produits']) { $out['source'] = 'atelierby_db'; }
    } catch (PDOException $e) {
        $out['erreur'] = $out['erreur'] ?? $e->getMessage();
    }
    return $out;
}

/**
 * Groupes de catégories du réseau (/product-category-groups).
 * C'est le premier niveau de l'arbre produit : Boulangerie, Viennoiserie,
 * Pâtisserie… Les catégories s'y rattachent par une table de liaison, et une
 * catégorie peut relever de plusieurs groupes.
 */
function ep_prod_groupes(): array
{
    $debug = !empty($_GET['debug']);
    $out = ['source' => null, 'groupes' => [], 'erreur' => null];

    if (PanelApi::configured()) {
        $rows = PanelApi::productCategoryGroups();
        if ($rows) {
            $out['source'] = 'api';
            $out['chemin'] = PanelApi::$lastPath;
            if ($debug) { $out['clesBrut'] = array_slice(array_keys($rows[0]), 0, 25); $out['premier'] = $rows[0]; }
            foreach ($rows as $g) {
                $id = null;
                foreach (['id', 'id_group', 'group_id', 'id_product_category_group'] as $k) {
                    if (isset($g[$k]) && is_numeric($g[$k])) { $id = (int) $g[$k]; break; }
                }
                $nom = '';
                foreach (['name', 'group_name', 'category_group_name', 'label', 'title', 'nom'] as $k) {
                    if (!empty($g[$k]) && is_string($g[$k])) { $nom = trim($g[$k]); break; }
                }
                if ($nom === '') { continue; }
                $out['groupes'][] = ['id' => $id, 'nom' => $nom];
            }
            if ($out['groupes']) { return $out; }
        }
        $out['erreur'] = PanelApi::$lastError;
    }

    // Repli base partagée, avec le nombre de catégories rattachées : un groupe
    // vide n'est pas une erreur, mais il ne mérite pas d'entrée dans un filtre.
    try {
        foreach (Db::rows('SELECT g.id, g.name, COUNT(k.id_category) n
                             FROM product_category_group g
                        LEFT JOIN product_category_group_connection k ON k.id_group = g.id
                         GROUP BY g.id, g.name ORDER BY g.name') as $g) {
            $out['groupes'][] = ['id' => (int) $g['id'], 'nom' => (string) $g['name'],
                'categories' => (int) $g['n']];
        }
        if ($out['groupes']) { $out['source'] = 'atelierby_db'; }
    } catch (PDOException $e) {
        $out['erreur'] = $out['erreur'] ?? $e->getMessage();
    }
    return $out;
}

/** GET /production/params — réglages du moteur de production. */
function ep_prod_params(): array
{
    $p = setting('production', []);
    return is_array($p) ? $p : [];
}

function ep_pwa_task_detail(): array
{
    $shopId = (int) ($_GET['shop'] ?? 0);
    $taskId = (int) ($_GET['task'] ?? 0);
    $date   = (string) ($_GET['date'] ?? '');
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shop, task et date (YYYY-MM-DD) sont requis'];
    }

    // Ce que la base partagée sait déjà de l'avis (toujours disponible).
    $avis = null;
    try {
        $r = Db::row('SELECT * FROM mac_task_review WHERE id_shop = ? AND id_task = ? AND review_date = ?',
            [$shopId, $taskId, $date]);
        if ($r !== null) {
            $avis = [
                'note' => $r['rating'] !== null ? (int) $r['rating'] : null,
                'accepte' => $r['is_accepted'] !== null ? (bool) (int) $r['is_accepted'] : null,
                'comment' => $r['comment'], 'consultant' => $r['consultant_name'],
                'checklistId' => $r['id_checklist'] !== null ? (int) $r['id_checklist'] : null,
                'completionId' => $r['completion_id'] !== null ? (int) $r['completion_id'] : null,
                'valide' => !empty($r['owner_validated_at']), 'valideePar' => $r['owner_name'],
            ];
        }
    } catch (PDOException $e) { /* table absente : avis inconnu */ }

    $out = ['shopId' => (string) $shopId, 'taskId' => (string) $taskId, 'date' => $date,
        'tache' => null, 'checklist' => null, 'photo' => null, 'obligatoire' => null,
        // QUAND la photo a été prise et PAR QUI : sans ces deux-là, un écart
        // se discute sans savoir de quel service ni de quelle personne il
        // vient — et l'heure dit souvent à elle seule ce qui s'est passé.
        'faitLe' => null, 'faitPar' => null,
        'photoRequise' => null, 'statut' => null, 'completionId' => $avis['completionId'] ?? null,
        'checklistId' => $avis['checklistId'] ?? null, 'avis' => $avis,
        // Référence : la photo de la fiche technique du produit contrôlé, pour
        // juger par COMPARAISON. Rapprochée par identifiant seul (jamais le nom).
        'produitId' => null, 'produit' => null, 'photoRef' => null,
        // Repères posés sur la photo. Lus AVANT l'API : ils sont locaux, ils
        // doivent revenir même si le panel ne répond pas.
        'reperes' => annotationLire($shopId, $taskId, $date),
        'api' => ['configure' => PanelApi::configured(), 'erreur' => null]];

    if (!PanelApi::configured()) {
        $out['api']['erreur'] = 'identifiants API du panel non configurés (Paramètres)';
        return $out;
    }

    // 1) Nom / obligation / photo requise : la liste des tâches du jour.
    foreach (PanelApi::shopTasks($shopId, $date) as $t) {
        if ((int) ($t['task_id'] ?? $t['id'] ?? 0) === $taskId) {
            $out['tache']        = trim((string) ($t['task_name'] ?? $t['name'] ?? '')) ?: null;
            $out['checklist']    = $t['checklist_name'] ?? null;
            $out['obligatoire']  = isset($t['is_mandatory']) ? (bool) $t['is_mandatory'] : null;
            $out['photoRequise'] = isset($t['requires_photo']) ? (bool) $t['requires_photo'] : null;
            $out['statut']       = $t['status'] ?? null;
            $out['faitLe']       = ($t['completed_at'] ?? null) !== null ? substr((string) $t['completed_at'], 0, 16) : null;
            $out['faitPar']      = trim((string) ($t['completed_by'] ?? '')) ?: null;
            foreach (['product_id', 'id_product', 'productId'] as $pk) {
                if (!empty($t[$pk]) && is_numeric($t[$pk])) { $out['produitId'] = (int) $t[$pk]; break; }
            }
            break;
        }
    }

    // 2) Pièce jointe + completion : c'est l'AVANCEMENT qui les porte.
    $attId = 0;
    $checklists = $out['checklistId'] !== null
        ? [['id' => $out['checklistId']]]
        : PanelApi::shopChecklists($shopId, $date);
    foreach ($checklists as $cl) {
        $cid = (int) ($cl['id'] ?? $cl['checklist_id'] ?? 0);
        if ($cid <= 0) { continue; }
        foreach (PanelApi::checklistProgress($shopId, $cid, $date) as $p) {
            if ((int) ($p['task_id'] ?? $p['id'] ?? 0) !== $taskId) { continue; }
            $attId = (int) ($p['attachment_id'] ?? 0);
            $out['checklistId']  = $cid;
            $out['completionId'] = $p['completion_id'] !== null ? (int) $p['completion_id'] : $out['completionId'];
            $out['statut']       = $p['status'] ?? $out['statut'];
            if ($out['tache'] === null) {
                $out['tache'] = trim((string) ($p['task_name'] ?? $p['name'] ?? '')) ?: null;
            }
            if ($out['produitId'] === null) {
                foreach (['product_id', 'id_product', 'productId'] as $pk) {
                    if (!empty($p[$pk]) && is_numeric($p[$pk])) { $out['produitId'] = (int) $p[$pk]; break; }
                }
            }
            break 2;
        }
    }
    if ($attId > 0) { $out['photo'] = PanelApi::attachmentUrl($attId); }
    if ($out['produitId'] !== null) {
        $ref = PanelApi::productPhoto($out['produitId'], $shopId);
        if ($ref !== null) { $out['produit'] = $ref['nom']; $out['photoRef'] = $ref['url']; }
    }
    $out['api']['erreur'] = PanelApi::$lastError;
    return $out;
}

/**
 * GET /fonds — le fonds marketing et les redevances.
 *
 * La donnée n'est PAS recopiée : elle est lue en direct sur le module
 * marketing, déployé sur le même serveur. Dupliquer un grand livre donnerait
 * deux soldes pour le même fonds, et c'est celui qui a tort qu'on regarderait.
 *
 * Le relais se fait côté serveur parce que le module vit sous un autre chemin :
 * un appel depuis le navigateur y serait une requête d'origine différente, que
 * rien n'autorise aujourd'hui.
 */
/**
 * Un appel au module marketing, quelle que soit la méthode.
 *
 * Le fonds est tenu par le module ; le cockpit ne recopie pas son grand livre,
 * il l'ADRESSE. Lectures et écritures passent donc par ici — un seul endroit
 * qui sait construire l'URL, poser l'en-tête et rendre le corps.
 *
 * Rend `['code' => int, 'corps' => mixed, 'erreur' => ?string]`. Un refus du
 * module est rendu TEL QUEL : c'est lui qui valide, pas nous, et réécrire son
 * message ferait deux vocabulaires pour la même règle.
 */
function marketingAppel(string $methode, string $chemin, ?array $corps = null): array
{
    $base = (string) (setting('marketingApi') ?: '/marketing/api/v1/marketing');
    // Relais local : on reconstruit l'URL absolue depuis l'hôte courant, sans
    // quoi un chemin relatif ne veut rien dire pour curl.
    $hote = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://';
    $hote .= $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $url = preg_match('#^https?://#', $base) ? $base . $chemin : $hote . $base . $chemin;

    $entetes = ['Accept: application/json'];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => strtoupper($methode),
    ];
    if ($corps !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($corps, JSON_UNESCAPED_UNICODE);
        $entetes[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $entetes;
    curl_setopt_array($ch, $opts);
    $rep = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($rep === false) { return ['code' => 0, 'corps' => null, 'erreur' => $err !== '' ? $err : 'module injoignable']; }
    $j = json_decode((string) $rep, true);
    if (!is_array($j)) {
        return ['code' => $code, 'corps' => null,
            'erreur' => $code >= 400 ? 'HTTP ' . $code : 'réponse illisible'];
    }
    // Le module enveloppe ses lectures dans `data` ; certaines rendent la liste
    // nue. On accepte les deux plutôt que d'imposer une forme.
    $donnees = array_key_exists('data', $j) ? $j['data'] : $j;
    $msg = null;
    if ($code >= 400) {
        $msg = (string) ($j['description'] ?? $j['message'] ?? ('HTTP ' . $code));
    }
    return ['code' => $code, 'corps' => $donnees, 'erreur' => $msg, 'brut' => $j];
}

/**
 * Les redevances d'un mois : magasin par magasin, SORTE par sorte.
 *
 * La grille des taux vit sur la FICHE boutique du panel (royalty_*_percentage,
 * en FRACTION : 0.0100 = 1 %), le chiffre d'affaires sur l'API de ventes —
 * borné au jour même quand le mois demandé est en cours. Rend null quand le
 * compte consultant n'est pas configuré ou que les fiches ne se lisent pas :
 * absent, pas inventé. Servi à l'écran (ep_fonds) ET à l'écriture au fonds
 * (fondsRoyaltiesEcrire) : un seul calcul, pas deux jeux de règles.
 */
function fondsRoyaltiesCalcul(string $mois): ?array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mois) || !PanelApi::configured()) { return null; }
    $du = $mois . '-01';
    $fin = date('Y-m-t', strtotime($du));
    $au = min($fin, date('Y-m-d'));
    if ($du > $au) { return null; }
    $caPar = [];
    foreach (analyseListe(PanelApi::shopsSalesKpisEntre($du, $au) ?? []) as $x) {
        $id = 0;
        foreach (['shop_id', 'id_shop', 'id'] as $c) {
            if (isset($x[$c]) && is_numeric($x[$c])) { $id = (int) $x[$c]; break; }
        }
        if ($id > 0) { $caPar[$id] = nombreOuNull($x, ['ca', 'turnover', 'revenue']); }
    }
    $fiches = PanelApi::consultantShops() ?? [];
    if ($fiches === []) { return null; }
    // La liste ne porte pas les taux : ils vivent sur la FICHE complète
    // (/shops/{id} — royalties_enabled, royalty_*_percentage). Une lecture
    // parallèle par boutique, fusionnée dans la ligne.
    $chemins = [];
    foreach ($fiches as $sh) {
        $id = (int) ($sh['id'] ?? 0);
        if ($id > 0) { $chemins[$id] = '/shops/' . $id; }
    }
    $details = PanelApi::getParallele($chemins);
    foreach ($fiches as $i => $sh) {
        $id = (int) ($sh['id'] ?? 0);
        $d = $details[$id] ?? null;
        if (is_array($d)) {
            if (isset($d['shop']) && is_array($d['shop'])) { $d = $d['shop']; }
            $fiches[$i] = $d + $sh;
        }
    }
    $shops = [];
    foreach ($fiches as $sh) {
        $id = (int) ($sh['id'] ?? 0);
        if ($id <= 0) { continue; }
        $ca = $caPar[$id] ?? null;
        $actif = (int) ($sh['royalties_enabled'] ?? 0) === 1;
        $nom = '';
        foreach (['representative_name', 'name'] as $c) {
            if (!empty($sh[$c]) && is_string($sh[$c])) { $nom = trim((string) $sh[$c]); break; }
        }
        $sortes = [];
        // Trois sortes, pas quatre : `royalties_percentage` est le champ
        // global/hérité de la fiche boutique, PAS un type de redevance. Le
        // compter comme une sorte « Générale » inventait une redevance qui
        // n'existe pas — et l'aurait ajoutée par-dessus les trois vraies.
        foreach ([['royalty_marketing_percentage', 'MARKETING', 'Marketing'],
                  ['royalty_brand_percentage', 'MARQUE', 'Marque'],
                  ['royalty_assistance_percentage', 'ASSISTANCE', 'Assistance']] as [$cle, $code, $label]) {
            $t = isset($sh[$cle]) ? (float) $sh[$cle] : 0.0;
            if ($t <= 0) { continue; }
            $sortes[] = ['cle' => $cle, 'code' => $code, 'label' => $label, 'taux' => $t,
                'du' => ($actif && $ca !== null) ? round((float) $ca * $t, 2) : null];
        }
        $shops[] = ['shop_id' => $id, 'shop_name' => $nom !== '' ? $nom : ('Magasin ' . $id),
            'city' => (string) ($sh['city'] ?? ''), 'ca' => $ca, 'enabled' => $actif,
            'billing_frequency' => $sh['royalty_billing_frequency'] ?? null, 'sortes' => $sortes];
    }
    return ['month' => $mois, 'du' => $du, 'au' => $au, 'fin' => $fin, 'shops' => $shops];
}

function ep_fonds(): array
{
    // Lecture DIRECTE des tables mar_* : le module marketing autonome
    // disparaît, son API avec lui. Les tables restent — c'est la même base
    // partagée — et le grand livre survit tel quel. Le relais HTTP d'avant
    // vivait dans marketingAppel(), conservé le temps de la bascule.
    $out = ['base' => 'tables mar_* (base partagée)', 'ledger' => null, 'leviers' => [],
        'royalties' => null, 'recurrences' => [], 'magasins' => [], 'campagnes' => [],
        'fournisseurs' => [], 'erreurs' => [], 'manque' => [], 'source' => null];
    try {
        $mvts = Db::rows('SELECT m.*, s.name AS shop_name, c.name AS campaign_name,
                                 l.label AS lever_label, l.color_hex AS lever_color_hex
                          FROM mar_fund_movement m
                          LEFT JOIN mar_shop s ON s.id = m.shop_id
                          LEFT JOIN mar_campaign c ON c.id = m.campaign_id
                          LEFT JOIN mar_lever l ON l.id = m.lever_id
                          ORDER BY m.movement_date, m.id');
    } catch (PDOException $e) {
        $out['erreurs'][] = 'tables mar_fund_* absentes : le module marketing n’a jamais été installé sur cette base';
        return $out;
    }

    $out['fournisseurs'] = array_map(fn ($f) => ['id' => (string) $f['id'], 'nom' => (string) $f['name']],
        Db::rows('SELECT id, name FROM ceo_supplier ORDER BY name'));

    // Le grand livre, période par période (mois civils), avec les soldes.
    $periodes = [];
    $solde = 0.0;
    foreach ($mvts as $m) {
        $cle = substr((string) $m['movement_date'], 0, 7) . '-01';
        if (!isset($periodes[$cle])) {
            $periodes[$cle] = ['period_key' => $cle, 'entries' => [], 'exits' => [],
                'entries_total' => 0.0, 'exits_total' => 0.0,
                'opening_balance' => $solde, 'closing_balance' => $solde];
        }
        $ligne = [
            'id' => (int) $m['id'], 'movement_date' => $m['movement_date'],
            'direction' => $m['direction'], 'label' => $m['label'],
            'amount' => (float) $m['amount'], 'source' => $m['source'],
            'shop_id' => $m['shop_id'] !== null ? (int) $m['shop_id'] : null,
            'shop_name' => $m['shop_name'], 'campaign_id' => $m['campaign_id'] !== null ? (int) $m['campaign_id'] : null,
            'campaign_name' => $m['campaign_name'],
            'lever_id' => $m['lever_id'] !== null ? (int) $m['lever_id'] : null,
            'lever_label' => $m['lever_label'], 'lever_color_hex' => $m['lever_color_hex'],
            'supplier_name' => $m['supplier_name'], 'document_ref' => $m['document_ref'],
            'is_investment' => (int) ($m['is_investment'] ?? 0) === 1,
            'recurrence_id' => ($m['recurrence_id'] ?? null) !== null ? (int) $m['recurrence_id'] : null,
        ];
        if ($m['direction'] === 'OUT') {
            $periodes[$cle]['exits'][] = $ligne;
            $periodes[$cle]['exits_total'] += (float) $m['amount'];
            $solde -= (float) $m['amount'];
        } else {
            $periodes[$cle]['entries'][] = $ligne;
            $periodes[$cle]['entries_total'] += (float) $m['amount'];
            $solde += (float) $m['amount'];
        }
        $periodes[$cle]['closing_balance'] = $solde;
    }
    $out['ledger'] = ['granularity' => 'month', 'periods' => array_values($periodes),
        'closing_balance' => $solde];

    // Les leviers, avec la dépense qui leur est imputée. Le ROI du module
    // reposait sur des objectifs de campagne qui ne sont pas repris : absent,
    // pas inventé.
    $out['leviers'] = array_map(fn ($l) => [
        'lever_id' => (int) $l['id'], 'lever_code' => $l['code'], 'lever_label' => $l['label'],
        'color_hex' => $l['color_hex'], 'spent_amount' => (float) $l['spent'],
        'roi_value' => null,
    ], Db::rows("SELECT l.*, COALESCE(SUM(CASE WHEN m.direction = 'OUT' THEN m.amount END), 0) AS spent
                 FROM mar_lever l
                 LEFT JOIN mar_fund_movement m ON m.lever_id = l.id
                 WHERE l.is_active = 1
                 GROUP BY l.id ORDER BY l.sort_order, l.id"));

    try {
        $out['recurrences'] = array_map(fn ($r) => [
            'id' => (int) $r['id'], 'direction' => $r['direction'], 'frequency' => $r['frequency'],
            'label' => $r['label'], 'amount' => (float) $r['amount'],
            'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'],
            'shop_name' => $r['shop_name'],
        ], Db::rows('SELECT r.*, s.name AS shop_name FROM mar_fund_recurrence r
                     LEFT JOIN mar_shop s ON s.id = r.shop_id ORDER BY r.starts_on DESC, r.id DESC'));
    } catch (PDOException $e) { /* table absente sur une vieille base */ }

    $out['magasins'] = array_map(fn ($s2) => ['id' => (int) $s2['id'], 'name' => (string) $s2['name'],
        'city' => $s2['city'] ?? ''], Db::rows('SELECT id, name, city FROM mar_shop ORDER BY name'));
    $out['campagnes'] = array_map(fn ($c2) => ['id' => (int) $c2['id'], 'name' => (string) $c2['name']],
        Db::rows('SELECT id, name FROM mar_campaign ORDER BY starts_on DESC, id DESC'));

    // Les redevances : le calcul partagé fondsRoyaltiesCalcul() — grille des
    // taux depuis la FICHE BOUTIQUE du panel (royalty_*_percentage), chiffre
    // d'affaires du mois choisi depuis l'API de ventes (borné au jour même
    // quand ce mois est en cours). Les écritures ROYALTY déjà passées au
    // fonds pour ce mois sont rappelées en face du dû théorique. Factures et
    // règlements émis relèvent du realm ADMIN (compte « admin ERP »).
    $royFait = false;
    // ?mois=AAAA-MM : la période s'arrête à l'écran, l'API recalcule CA, taux
    // et dû pour ce mois-là. Un mois invalide ou futur retombe sur le courant
    // — un CA qui n'existe pas encore ne se facture pas.
    $moisCle = (string) ($_GET['mois'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $moisCle) || $moisCle > date('Y-m')) { $moisCle = date('Y-m'); }
    $calc = fondsRoyaltiesCalcul($moisCle);
    if ($calc !== null) {
        // Écritures ROYALTY du mois déjà au fonds, par magasin.
        $ecrit = [];
        foreach ($mvts as $m) {
            if (($m['source'] ?? '') === 'ROYALTY' && $m['direction'] === 'IN' && $m['shop_id'] !== null
                && (str_starts_with((string) $m['movement_date'], $moisCle)
                    || str_starts_with((string) ($m['period_from'] ?? ''), $moisCle))) {
                $ecrit[(int) $m['shop_id']][] = ['amount' => (float) $m['amount'], 'label' => $m['label']];
            }
        }
        $shops = [];
        foreach ($calc['shops'] as $s) {
            $rates = []; $dues = []; $duTotal = 0.0; $aDu = false; $duMkt = null;
            foreach ($s['sortes'] as $k) {
                $rates[] = ['code' => $k['cle'], 'label' => $k['label'], 'rate_pct' => round($k['taux'] * 100, 2)];
                if ($k['du'] !== null) {
                    $dues[] = ['code' => $k['code'], 'label' => $k['label'],
                        'rate_pct' => round($k['taux'] * 100, 2), 'amount' => $k['du'],
                        // Seule la sorte Marketing part au fonds : l'écran le
                        // dit ligne à ligne, sinon le « Dû » total laisse
                        // croire que tout alimente la caisse commune.
                        'au_fonds' => $k['code'] === 'MARKETING'];
                    $duTotal += $k['du']; $aDu = true;
                    if ($k['code'] === 'MARKETING') { $duMkt = $k['du']; }
                }
            }
            $shops[] = [
                'shop_id' => $s['shop_id'], 'shop_name' => $s['shop_name'], 'city' => $s['city'],
                'revenue_amount' => $s['ca'], 'rates' => $rates,
                'royalties_enabled' => $s['enabled'],
                'billing_frequency' => $s['billing_frequency'],
                // Le dû du mois TOUTES sortes confondues ; le détail sorte par
                // sorte suit dans `dues`. Au fonds ne part que la sorte
                // Marketing — `due_marketing` la donne seule.
                'due_theorique' => $aDu ? round($duTotal, 2) : null,
                'due_marketing' => $duMkt !== null ? round($duMkt, 2) : null,
                'dues' => $dues,
                'movements' => $ecrit[$s['shop_id']] ?? [],
            ];
        }
        $roy = ['month' => $moisCle, 'shops' => $shops,
            'source' => 'API panel — fiche boutique (taux) et ventes du mois (CA, jour même)',
            'erp' => ['available' => true]];
        if (ErpApi::disponible()) {
            // Réponses enveloppées ({invoices: […]}, {settlements: […]}).
            $fac = ErpApi::get('/admin/royalties/invoices');
            $reg = ErpApi::get('/admin/royalties/settlements');
            if (is_array($fac)) { $roy['factures'] = array_slice((array) ($fac['invoices'] ?? (analyseListe($fac) ?: [])), 0, 24); }
            if (is_array($reg)) { $roy['reglements'] = array_slice((array) ($reg['settlements'] ?? (analyseListe($reg) ?: [])), 0, 24); }
        } else {
            $roy['facturesNote'] = 'Factures et règlements émis : renseignez le compte admin ERP (Mon compte) pour les lire.';
        }
        $out['royalties'] = $roy;
        $royFait = true;
    }
    if (!$royFait) {
        $out['manque'][] = [
            'champ' => 'Redevances par magasin',
            'quoi' => 'la grille des taux, les chiffres d’affaires du mois et l’écriture des redevances au fonds',
            'source' => 'La grille et le CA se lisent sur l’API du panel : configurez le compte consultant '
                . '(Mon compte) pour les afficher ici. ' . (PanelApi::$lastError ? 'Dernière erreur : ' . PanelApi::$lastError : ''),
        ];
    }
    $out['source'] = 'tables mar_* (module marketing repris)';
    return $out;
}

/**
 * La grille d'un produit dans un emplacement : combien de colonnes, combien de
 * rangées, pour un nombre par slot donné.
 *
 * La règle du comptoir : une ligne va jusqu'à SIX produits, et les rangées se
 * déduisent par division arrondie vers le bas. Prise telle quelle, elle laisse
 * des restes — neuf croissants sur une ligne de six n'en posent que six. On
 * propose donc d'office la LIGNE JUSTE : celle qui divise exactement, et parmi
 * celles-là celle qui donne la case la plus proche du carré dans les dimensions
 * réelles de l'emplacement — quatre produits dans un 30 × 30 se posent 2 × 2,
 * les mêmes quatre dans un 60 × 15 se posent 4 × 1.
 *
 * Une ligne imposée à la main est RESPECTÉE, reste compris : c'est l'écran qui
 * l'écrit, pas le calcul qui le cache.
 */
function planoGrille(int $n, ?int $largeurMm = null, ?int $hauteurMm = null, ?int $colsVoulues = null): array
{
    $n = max(0, min(400, $n));
    if ($n === 0) { return ['parSlot' => 0, 'cols' => 0, 'rangs' => 0, 'poses' => 0, 'reste' => 0, 'juste' => true]; }
    $lar = ($largeurMm !== null && $largeurMm > 0) ? (float) $largeurMm : 1.0;
    $hau = ($hauteurMm !== null && $hauteurMm > 0) ? (float) $hauteurMm : 1.0;

    if ($colsVoulues !== null && $colsVoulues > 0) {
        $c = min(6, min($n, $colsVoulues));
        $r = max(1, intdiv($n, $c));
        return ['parSlot' => $n, 'cols' => $c, 'rangs' => $r, 'poses' => $c * $r,
            'reste' => max(0, $n - $c * $r), 'juste' => $c * $r === $n];
    }
    // La ligne juste : elle existe toujours (une colonne divise tout).
    $best = null; $bestScore = null;
    for ($c = 1; $c <= 6 && $c <= $n; $c++) {
        if ($n % $c !== 0) { continue; }
        $r = intdiv($n, $c);
        $l = $lar / $c; $h = $hau / $r;
        $score = $l > 0 && $h > 0 ? max($l / $h, $h / $l) : 1e9;
        // À égalité, la ligne la plus large : on remplit la vitrine de face
        // avant de la remplir en profondeur.
        $mieux = $bestScore === null || $score < $bestScore - 1e-9
            || (abs($score - $bestScore) <= 1e-9 && $c > (int) $best[0]);
        if ($mieux) { $best = [$c, $r]; $bestScore = $score; }
    }
    [$c, $r] = $best ?? [1, $n];
    return ['parSlot' => $n, 'cols' => $c, 'rangs' => $r, 'poses' => $c * $r, 'reste' => 0, 'juste' => true];
}

/** Les deux listes de choix du comptoir : formats d'emplacement, contenants. */
function planoReferentielsTables(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $formats = []; $contenants = [];
    try {
        foreach (Db::rows('SELECT * FROM pla_format ORDER BY rang, id') as $f) {
            $formats[] = ['id' => (int) $f['id'], 'nom' => (string) $f['nom'],
                'largeurMm' => $f['largeur_mm'] !== null ? (int) $f['largeur_mm'] : null,
                'hauteurMm' => $f['hauteur_mm'] !== null ? (int) $f['hauteur_mm'] : null];
        }
        foreach (Db::rows('SELECT * FROM pla_contenant ORDER BY rang, id') as $c) {
            $contenants[] = ['id' => (int) $c['id'], 'nom' => (string) $c['nom']];
        }
    } catch (PDOException $e) { /* tables absentes : listes vides, jamais inventées */ }
    $cache = ['formats' => $formats, 'contenants' => $contenants];
    return $cache;
}

/**
 * GET /planogramme — la structure du comptoir, avec son occupation.
 *
 * Un seul appel rend l'arbre complet : zones → meubles → niveaux →
 * emplacements, chaque emplacement portant son occupant ou `null`. C'est ce qui
 * permet à l'écran de proposer les places libres sans rien calculer, et de
 * dessiner le comptoir tel qu'il est.
 *
 * La structure vit dans le cockpit : l'API du panel n'expose rien de tout cela
 * (mesuré). Ce qui en dépend vraiment — la photo de présentation et la
 * diffusion de la consigne en boutique — est annoncé comme manquant, jamais
 * remplacé par une invention.
 */
function ep_planogramme(): array
{
    $ref = setting('planogramme', []);
    $out = ['zones' => [], 'slots' => [], 'placements' => [], 'notes' => [],
        'totaux' => ['slots' => 0, 'libres' => 0, 'places' => 0, 'refs' => 0],
        // Les choix proposés à la déclaration d'un meuble. Ce sont des listes
        // de réglage, pas du code : un réseau qui travaille en surgelé ajoute
        // sa température sans livraison.
        'referentiels' => [
            'types' => is_array($ref['types'] ?? null) ? $ref['types'] : [],
            'temperatures' => is_array($ref['temperatures'] ?? null) ? $ref['temperatures'] : [],
            'presentations' => is_array($ref['presentations'] ?? null) ? $ref['presentations'] : [],
            // Les moments de la journée : le comptoir du matin n'est pas celui
            // de midi, et un meuble peut n'être monté qu'à l'un des deux.
            'periodes' => is_array($ref['periodes'] ?? null) ? $ref['periodes'] : [],
            'slotDefaut' => is_array($ref['slotDefaut'] ?? null) ? $ref['slotDefaut']
                : ['longueur' => 300, 'largeur' => 300, 'hauteur' => 250],
            // Formats d'emplacement et contenants : deux listes éditables
            // depuis l'écran, d'où la table plutôt que le réglage figé.
            'formats' => planoReferentielsTables()['formats'],
            'contenants' => planoReferentielsTables()['contenants'],
        ],
        'manque' => planoManque()];

    // Placements, indexés par emplacement — et par référence pour l'écran.
    $parSlot = []; $placements = [];
    foreach (Db::rows('SELECT * FROM pla_placement') as $p) {
        $sid = $p['slot_id'] !== null ? (int) $p['slot_id'] : null;
        $l = ['ref' => (string) $p['ref'], 'slotId' => $sid,
            'fronts' => (int) ($p['fronts'] ?? 1), 'ordre' => (int) ($p['ordre'] ?? 1),
            // Une grille absente reste absente : un placement d'avant la
            // notion ne vaut pas « un produit par emplacement ».
            'parSlot' => ($p['par_slot'] ?? null) !== null ? (int) $p['par_slot'] : null,
            'cols' => ($p['grille_cols'] ?? null) !== null ? (int) $p['grille_cols'] : null,
            'rangs' => ($p['grille_rangs'] ?? null) !== null ? (int) $p['grille_rangs'] : null,
            // Vide = toute la journée — même lecture que pour un meuble.
            'periodes' => array_values(array_filter(explode(',', (string) ($p['periodes'] ?? '')))),
            'zone' => $p['zone'], 'meuble' => $p['meuble'], 'niveau' => $p['niveau'],
            'position' => $p['slot'] !== null ? (int) $p['slot'] : null];
        $placements[] = $l;
        if ($sid !== null) { $parSlot[$sid][] = $l; }
    }
    $out['placements'] = $placements;

    // Noms des références, pour afficher l'occupant sans second appel.
    $noms = [];
    try {
        foreach (Db::rows('SELECT ref, nom FROM ceo_prod_product') as $r) {
            $noms[(string) $r['ref']] = (string) $r['nom'];
        }
    } catch (PDOException $e) { /* sans nom : la référence seule */ }
    if (count($noms) < count($placements)) {
        // Le nom vient du catalogue réel quand la fiche cockpit n'existe pas.
        foreach (ep_prod_catalogue() as $c) { $noms[(string) $c['ref']] = (string) $c['nom']; }
    }

    $zones = Db::rows('SELECT * FROM pla_zone ORDER BY rang, id');
    $meubles = Db::rows('SELECT * FROM pla_meuble ORDER BY rang, id');
    $niveaux = Db::rows('SELECT * FROM pla_niveau ORDER BY rang, id');
    $slots = Db::rows('SELECT * FROM pla_slot ORDER BY niveau_id, position');

    $parNiveau = [];
    foreach ($slots as $s) { $parNiveau[(int) $s['niveau_id']][] = $s; }
    $parMeuble = [];
    foreach ($niveaux as $n) { $parMeuble[(int) $n['meuble_id']][] = $n; }
    $parZone = [];
    foreach ($meubles as $m) { $parZone[(int) $m['zone_id']][] = $m; }

    foreach ($zones as $z) {
        $zid = (int) $z['id'];
        $zl = ['id' => $zid, 'nom' => (string) $z['nom'], 'rang' => (int) $z['rang'], 'meubles' => []];
        foreach ($parZone[$zid] ?? [] as $m) {
            $mid = (int) $m['id'];
            // `periodes` vide = toute la journée. C'est ce que portent les
            // meubles déclarés avant l'ajout de la notion : les compter comme
            // « aucun moment » les ferait disparaître du plan.
            $per = array_values(array_filter(explode(',', (string) ($m['periodes'] ?? ''))));
            $ml = ['id' => $mid, 'nom' => (string) $m['nom'], 'rang' => (int) $m['rang'],
                'type' => (string) ($m['type'] ?? ''), 'temperature' => (string) ($m['temperature'] ?? ''),
                'presentation' => (string) ($m['presentation'] ?? ''),
                'periodes' => $per, 'toutLeJour' => !$per, 'niveaux' => []];
            foreach ($parMeuble[$mid] ?? [] as $n) {
                $nid = (int) $n['id'];
                $nl = ['id' => $nid, 'nom' => (string) $n['nom'], 'rang' => (int) $n['rang'], 'slots' => []];
                foreach ($parNiveau[$nid] ?? [] as $s) {
                    $sid = (int) $s['id'];
                    $occ = $parSlot[$sid] ?? [];
                    $ligne = [
                        'id' => $sid, 'position' => (int) $s['position'],
                        'largeurMm' => $s['largeur_mm'] !== null ? (int) $s['largeur_mm'] : null,
                        'longueurMm' => ($s['longueur_mm'] ?? null) !== null ? (int) $s['longueur_mm'] : null,
                        'hauteurMm' => ($s['hauteur_mm'] ?? null) !== null ? (int) $s['hauteur_mm'] : null,
                        'capacite' => $s['capacite'] !== null ? (int) $s['capacite'] : null,
                        'format' => (string) ($s['format'] ?? ''),
                        'contenant' => (string) ($s['contenant'] ?? ''),
                        'zoneId' => $zid, 'zone' => (string) $z['nom'],
                        'meubleId' => $mid, 'meuble' => (string) $m['nom'],
                        'meubleType' => (string) ($m['type'] ?? ''),
                        'meubleTemp' => (string) ($m['temperature'] ?? ''),
                        'meublePres' => (string) ($m['presentation'] ?? ''),
                        'meublePeriodes' => $per,
                        'niveauId' => $nid, 'niveau' => (string) $n['nom'],
                        'occupants' => array_map(fn ($o) => [
                            'ref' => $o['ref'], 'nom' => $noms[$o['ref']] ?? $o['ref'],
                            'fronts' => $o['fronts'], 'ordre' => $o['ordre'],
                            'parSlot' => $o['parSlot'], 'cols' => $o['cols'], 'rangs' => $o['rangs'],
                            'periodes' => $o['periodes']], $occ),
                    ];
                    $nl['slots'][] = $ligne;
                    $out['slots'][] = $ligne;
                    $out['totaux']['slots']++;
                    if (!$occ) { $out['totaux']['libres']++; }
                }
                $ml['niveaux'][] = $nl;
            }
            $zl['meubles'][] = $ml;
        }
        $out['zones'][] = $zl;
    }

    $out['totaux']['places'] = count(array_filter($placements, fn ($p) => $p['slotId'] !== null));
    $out['totaux']['refs'] = count($placements);

    foreach (Db::rows('SELECT * FROM pla_note') as $n) {
        $out['notes'][(string) $n['cible'] . ':' . (string) $n['cible_id']] = [
            'cible' => $n['cible'], 'cibleId' => (string) $n['cible_id'],
            'texte' => (string) ($n['texte'] ?? ''), 'epinglee' => (bool) (int) $n['epinglee'],
            'gravite' => (int) $n['gravite'], 'du' => $n['du'], 'au' => $n['au'],
            'photo' => $n['photo'] ?? null,
            'auteur' => $n['auteur'], 'majLe' => $n['maj_le']];
    }
    return $out;
}

/**
 * GET /planogramme/photos — le visuel que le PANEL a pour chaque référence
 * placée, par la MÊME chaîne que le contrôle qualité : produits disponibles
 * d'une boutique → id_recipe → /recipes/{id} → shop_photo_path (sinon
 * main_photo_path, sinon photo_1..3), résolu contre photoBase.
 *
 * Le résultat est MÉMORISÉ sept jours (table ceo_plano_photo), absence
 * comprise : deux appels panel par référence, à chaque ouverture d'écran,
 * feraient payer le comptoir entier pour rien. `?rafraichir=1` force la
 * relecture quand une photo vient d'être posée côté panel.
 *
 * La photo annexée dans le cockpit reste prioritaire à l'écran : celle-ci
 * COMPLÈTE, elle ne remplace pas.
 */
/**
 * Une URL de photo signée est-elle périmée ? Le panel sert ses visuels par
 * des liens S3 signés UNE HEURE (mesuré : X-Amz-Expires=3600). Les mémoriser
 * sept jours servirait des cadres morts — un lien signé se re-demande donc dès
 * qu'il expire, avec une minute de marge ; seule l'ABSENCE de visuel garde la
 * mémoire longue, c'est elle qui coûte deux appels pour rien.
 */
function planoPhotoPerimee(?string $url): bool
{
    if ($url === null || $url === '') { return false; }
    if (!preg_match('/[?&]X-Amz-Date=(\d{8}T\d{6}Z)/', $url, $md)) { return false; }
    $duree = preg_match('/[?&]X-Amz-Expires=(\d+)/', $url, $me) ? (int) $me[1] : 3600;
    $depuis = strtotime(substr($md[1], 0, 8) . 'T' . substr($md[1], 9, 6) . 'Z');
    return $depuis !== false && time() > $depuis + $duree - 60;
}

function ep_plano_photos(): array
{
    $rafraichir = !empty($_GET['rafraichir']);
    $refs = [];
    try {
        foreach (Db::rows('SELECT ref FROM pla_placement WHERE slot_id IS NOT NULL') as $r) {
            $refs[] = (string) $r['ref'];
        }
    } catch (PDOException $e) { return ['photos' => [], 'source' => 'aucun placement']; }
    $refs = array_slice(array_values(array_unique($refs)), 0, 120);
    if (!$refs) { return ['photos' => [], 'source' => 'aucun placement']; }

    // La mémoire d'abord : ce qui a moins de sept jours ne se redemande pas.
    $enMemoire = [];
    try {
        $in = implode(',', array_fill(0, count($refs), '?'));
        foreach (Db::rows('SELECT * FROM ceo_plano_photo WHERE ref IN (' . $in . ')', $refs) as $c) {
            $age = time() - (strtotime((string) $c['maj_le']) ?: 0);
            if (!$rafraichir && $age < 7 * 86400 && !planoPhotoPerimee($c['url'] ?? null)) {
                $enMemoire[(string) $c['ref']] = $c;
            }
        }
    } catch (PDOException $e) { /* table absente : tout se relit */ }

    // Les boutiques qui peuvent porter la correspondance produit → recette.
    // N'importe laquelle fait l'affaire quand la référence y est vendue ; on
    // en essaie trois avant de conclure à l'absence.
    $boutiques = [];
    try {
        foreach (Db::rows('SELECT id FROM ceo_shop') as $b2) {
            if (is_numeric($b2['id'])) { $boutiques[] = (int) $b2['id']; }
        }
    } catch (PDOException $e) { /* pas de boutiques locales */ }
    $boutiques = array_slice($boutiques, 0, 3);

    $photos = []; $lus = 0;
    foreach ($refs as $ref) {
        if (isset($enMemoire[$ref])) {
            $photos[$ref] = ['nom' => (string) $enMemoire[$ref]['nom'],
                'url' => $enMemoire[$ref]['url'] !== null ? (string) $enMemoire[$ref]['url'] : null];
            continue;
        }
        $trouve = null;
        if (PanelApi::configured() && is_numeric($ref)) {
            foreach ($boutiques ?: [0] as $bid) {
                $r = PanelApi::productPhoto((int) $ref, $bid);
                if ($r !== null && $r['url'] !== null) { $trouve = $r; break; }
                if ($r !== null && $trouve === null) { $trouve = $r; }
            }
            $lus++;
        }
        $photos[$ref] = ['nom' => (string) ($trouve['nom'] ?? ''), 'url' => $trouve['url'] ?? null];
        try {
            Db::exec('INSERT INTO ceo_plano_photo (ref, nom, url, maj_le) VALUES (?,?,?,?)'
                . ' ON DUPLICATE KEY UPDATE nom = VALUES(nom), url = VALUES(url), maj_le = VALUES(maj_le)',
                [$ref, mb_substr((string) ($trouve['nom'] ?? ''), 0, 190), $trouve['url'] ?? null,
                 date('Y-m-d H:i:s')]);
        } catch (PDOException $e) { /* sans mémoire : la réponse part quand même */ }
    }
    return ['photos' => $photos,
        'source' => 'panel — recette de chaque référence ; lien signé relu à l’expiration, absence mémorisée 7 jours'
            . ($lus ? ' (' . $lus . ' relue(s) à l’instant)' : ''),
        'api' => ['erreur' => PanelApi::$lastError]];
}

/**
 * Ce qui manque au planogramme, et qui ne peut PAS venir du cockpit.
 *
 * La structure, les placements et les consignes se saisissent ici. Restent deux
 * dépendances réelles au panel : le visuel du produit, et le fait que la
 * consigne parvienne à la boutique. Les nommer évite de laisser croire qu'un
 * planogramme rempli est un planogramme diffusé.
 */
function planoManque(): array
{
    return [
        // La photo n'est plus une lacune : elle se lit sur la RECETTE de la
        // référence (la même chaîne que le contrôle qualité), et le cockpit
        // peut toujours en annexer une qui prime. Ce qui manque encore, c'est
        // le sens inverse : déposer un visuel côté panel depuis le cockpit.
        lacune('Dépôt du visuel côté panel',
            'remplacer la photo de recette depuis le cockpit — aujourd\'hui elle se lit, elle ne s\'écrit pas',
            'API panel — aucune route de dépôt de visuel produit. '
            . 'À obtenir : POST /consultant/products/{id}/photos avec kind: presentation'),
        lacune('Diffusion de la consigne en boutique',
            'afficher les notes épinglées sur la tâche de comptoir, côté application terrain',
            'API panel — aucune route ne porte de consigne de présentation vers la boutique. '
            . 'Sans elle, la consigne reste lisible du seul côté cockpit'),
    ];
}

/**
 * GET /production/produit/fiche — la fiche de vente d'UNE référence.
 *
 * Lue produit par produit, et en `SELECT *` : les colonnes utiles de la caisse
 * (nutriscore, allergènes, conservation, réchauffe, positionnement…) existent
 * mais leur présence varie d'une installation à l'autre. Les nommer une par une
 * dans le SELECT ferait échouer toute la fiche sur une colonne absente ; on
 * prend la ligne entière et on ne rend que ce qu'on y trouve.
 */
function ep_prod_produit_fiche(): array
{
    $ref = trim((string) ($_GET['ref'] ?? ''));
    if ($ref === '') { http_response_code(400); return ['error' => 'référence requise']; }

    $cat = null;
    foreach (ep_prod_catalogue() as $c) {
        if ((string) $c['ref'] === $ref) { $cat = $c; break; }
    }
    if ($cat === null) { http_response_code(404); return ['error' => 'référence inconnue']; }

    $out = ['ref' => $ref, 'catalogue' => $cat, 'technique' => [], 'source' => null,
        'note' => null, 'manque' => planoManque()];

    // Fiche technique : la ligne produit de la caisse, telle quelle.
    $pid = $cat['pwaId'] ?? null;
    if ($pid !== null) {
        try {
            $r = Db::row('SELECT * FROM product WHERE id = ?', [(int) $pid]);
            if ($r !== null) {
                $out['source'] = 'caisse';
                // Libellé lisible → valeur, en ne gardant que le renseigné : un
                // champ vide affiché ferait passer une absence pour un zéro.
                $champs = [
                    'nutriscore' => 'Nutriscore',
                    'allergene' => 'Allergènes',
                    'is_vegetarian' => 'Végétarien',
                    'storage_name' => 'Conservation',
                    'storage_description' => 'Consigne de conservation',
                    'storage_temperature' => 'Température de conservation',
                    'shelf_life_category' => 'Catégorie de DLV',
                    'reheating_time_minutes' => 'Réchauffe (minutes)',
                    'reheating_temperature_celsius' => 'Réchauffe (°C)',
                    'preparation_lead_time_hours' => 'Délai de préparation (h)',
                    'positioning_name' => 'Positionnement',
                    'positioning_description' => 'Description du positionnement',
                    'sector_name' => 'Secteur',
                    'quantity_per_label' => 'Quantité par étiquette',
                    'label_size' => 'Format d\'étiquette',
                    'is_divisible' => 'Divisible',
                    'is_piece_based' => 'Vendu à la pièce',
                ];
                // Un zéro de réchauffe veut dire « pas de réchauffe », pas
                // « réchauffer zéro minute », et un format « none » n'est pas un
                // format. Les afficher remplirait la fiche de bruit et rendrait
                // les vrais renseignements plus durs à trouver.
                $zeroAbsent = ['reheating_time_minutes', 'reheating_temperature_celsius',
                    'preparation_lead_time_hours', 'storage_temperature'];
                $motsVides = ['none', 'n/a', 'na', 'null', '-', 'aucun', 'aucune'];
                foreach ($champs as $col => $lib) {
                    if (!array_key_exists($col, $r)) { continue; }
                    $v = $r[$col];
                    if ($v === null || $v === '') { continue; }
                    if (in_array($col, $zeroAbsent, true) && (float) $v === 0.0) { continue; }
                    if (in_array(mb_strtolower(trim((string) $v)), $motsVides, true)) { continue; }
                    if (in_array($col, ['is_vegetarian', 'is_divisible', 'is_piece_based'], true)) {
                        $v = ((int) $v === 1) ? 'oui' : 'non';
                    }
                    $out['technique'][] = ['champ' => $lib, 'valeur' => (string) $v];
                }
            }
        } catch (PDOException $e) { /* table de caisse absente : fiche réduite */ }
    }

    $n = Db::row('SELECT * FROM pla_note WHERE cible = ? AND cible_id = ?', ['ref', $ref]);
    if ($n !== null) {
        $out['note'] = ['texte' => (string) ($n['texte'] ?? ''), 'epinglee' => (bool) (int) $n['epinglee'],
            'gravite' => (int) $n['gravite'], 'du' => $n['du'], 'au' => $n['au'],
            'photo' => $n['photo'] ?? null,
            'auteur' => $n['auteur'], 'majLe' => $n['maj_le']];
    }
    return $out;
}

/**
 * Repères d'une photo de contrôle : lecture.
 *
 * Rend toujours une forme exploitable — liste vide et `maj` nul quand rien n'a
 * été posé — pour que l'écran n'ait pas à distinguer « pas de repère » de
 * « table absente ».
 */
function annotationLire(int $shopId, int $taskId, string $date): array
{
    $out = ['liste' => [], 'maj' => null, 'auteur' => null];
    try {
        $r = Db::row('SELECT reperes, auteur, maj_le FROM ceo_task_annotation'
            . ' WHERE id_shop = ? AND id_task = ? AND annot_date = ?', [$shopId, $taskId, $date]);
    } catch (PDOException $e) { return $out; }
    if ($r === null) { return $out; }
    $j = json_decode((string) $r['reperes'], true);
    $out['liste']  = annotationNormalise(is_array($j) ? $j : []);
    $out['maj']    = $r['maj_le'];
    $out['auteur'] = $r['auteur'] !== '' ? $r['auteur'] : null;
    return $out;
}

/**
 * Normalise et BORNE une liste de repères, à l'écriture comme à la lecture.
 *
 * Un repère vient du navigateur : ses coordonnées, sa taille et son texte sont
 * ramenés dans des limites tenables avant d'être gardés ou rendus. Sans cela un
 * cadre à x = 40 sortirait de la photo sans qu'aucun écran puisse le rattraper,
 * et un texte sans limite ferait de cette table un dépotoir.
 *
 * La numérotation est RECALCULÉE ici, dans l'ordre de la liste : elle ne peut
 * donc pas se trouer ni doubler, quoi que le navigateur envoie.
 */
function annotationNormalise(array $liste): array
{
    $borne = static fn ($v) => max(0.0, min(1.0, round((float) $v, 4)));
    // La gravité d'un repère est celle du barème DÉJÀ partagé par l'écran de
    // validation (réglage `signalement`) : « non conforme mineur / majeur /
    // critique » y sont nommés, avec leur couleur. Un second barème pour les
    // repères se serait mis à diverger du premier au premier changement.
    $echelle = [];
    $sig = setting('signalement', []);
    foreach ((is_array($sig) && is_array($sig['niveaux'] ?? null)) ? $sig['niveaux'] : [] as $nv) {
        if (isset($nv['n'])) { $echelle[(int) $nv['n']] = true; }
    }
    $defaut = isset($echelle[3]) ? 3 : (int) (array_key_first($echelle) ?? 3);

    $out = [];
    foreach ($liste as $r) {
        if (!is_array($r)) { continue; }
        // La TAILLE est bornée d'abord, l'origine ensuite : un cadre d'au moins
        // 1,5 % de la photo (en dessous, le ✕ le recouvre et le repère
        // n'indique plus rien) et jamais un bord au-delà de l'image. L'inverse
        // — origine puis taille — laisse un x = 1 pousser le cadre hors cadre.
        $l = max(0.015, min(1.0, $borne($r['l'] ?? 0.1)));
        $h = max(0.015, min(1.0, $borne($r['h'] ?? 0.1)));
        $x = min($borne($r['x'] ?? 0), round(1 - $l, 4));
        $y = min($borne($r['y'] ?? 0), round(1 - $h, 4));
        $txt = trim((string) ($r['txt'] ?? ''));
        if (function_exists('mb_substr')) { $txt = mb_substr($txt, 0, 400); }
        $niv = (int) ($r['niveau'] ?? $defaut);
        if (!isset($echelle[$niv])) { $niv = $defaut; }
        $out[] = ['n' => count($out) + 1, 'x' => $x, 'y' => $y, 'l' => $l, 'h' => $h,
            'niveau' => $niv, 'txt' => $txt];
        if (count($out) >= 40) { break; }   // au-delà, la photo n'est plus lisible
    }
    return $out;
}

/**
 * Ce qu'il faudrait pour que la photo annotée PARTE au franchisé.
 *
 * L'annotation est complète côté cockpit ; ce qui manque est le canal retour.
 * L'écran le dit lui-même plutôt que de laisser croire que le magasin a reçu
 * quelque chose.
 */
function annotationLacune(): array
{
    return lacune('Envoi des repères au franchisé',
        'joindre la photo annotée à l’avis de tâche, côté panel',
        'API panel — /consultant/shops/{id}/task-reviews n’accepte que note, conformité et commentaire ; '
        . 'aucune route de dépôt de pièce jointe n’est exposée. À obtenir : POST d’une pièce jointe '
        . '(image ou calque) rattachable à un task-review, ou un champ de commentaire portant les repères');
}

function ep_perf(): array
{
    $annees = array_map('intval', explode(',', $_GET['annees'] ?? '2025,2026'));
    $in = implode(',', array_fill(0, count($annees), '?'));
    // Vrai P&L mensuel du panel (table partagée `mac_shop_monthly_pnl`, la même
    // que le ValuationService du panel). Le snapshot porte ca / marge nette /
    // labour / overhead ; il ne porte NI « material » (food, cf. ticket T5a du
    // panel) NI tickets/panier — laissés à null. Repli sur ceo_shop_month_perf.
    try {
        $key = fn ($s, $y, $m) => $s . '-' . $y . '-' . $m;
        $cells = [];
        // 1) P&L mensuel (mac_shop_monthly_pnl) : CA, marge nette, labour, overhead.
        foreach (Db::rows("SELECT id_shop, year, month, ca, net_margin_pct, net_result, labour, overhead
                          FROM mac_shop_monthly_pnl WHERE year IN ($in)", $annees) as $r) {
            $ca  = $r['ca'] !== null ? (float) $r['ca'] : null;
            $pos = $ca !== null && $ca > 0;
            $cells[$key($r['id_shop'], $r['year'], $r['month'])] = [
                'storeId'       => (string) $r['id_shop'], 'annee' => (int) $r['year'], 'mois' => (int) $r['month'],
                'ca'            => $ca, 'caBudget' => null, 'caTheorique' => null,
                'margeNette'    => $r['net_result'] !== null ? (float) $r['net_result'] : null,
                'margePct'      => $r['net_margin_pct'] !== null ? round((float) $r['net_margin_pct'] / 100, 4) : null,
                'tickets'       => null, 'panierMoyen' => null, 'foodCostPct' => null,
                'labourCostPct' => ($pos && $r['labour'] !== null) ? round((float) $r['labour'] / $ca * 100, 1) : null,
                'overheadPct'   => ($pos && $r['overhead'] !== null) ? round((float) $r['overhead'] / $ca * 100, 1) : null,
                'valorisation'  => null,
            ];
        }
        // 2) Ventes caisse (`transaction`) : tickets + panier moyen RÉELS, et CA de
        //    repli pour les mois sans P&L. Borné à l'exercice courant (perf récente)
        //    et plafonné côté MySQL ; en cas de lenteur/absence on garde le P&L seul.
        try {
            $yMax = max($annees);
            $from = sprintf('%04d-01-01 00:00:00', $yMax);
            $to   = sprintf('%04d-01-01 00:00:00', $yMax + 1);
            foreach (Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */
                                      id_shop, MONTH(insert_timestamp) m,
                                      COUNT(DISTINCT ticket_key) tickets,
                                      SUM(total_gross_amount_after_discount) ca
                               FROM transaction
                               WHERE insert_timestamp >= ? AND insert_timestamp < ?
                               GROUP BY id_shop, m", [$from, $to]) as $r) {
                $tickets = (int) $r['tickets'];
                $caPos   = $r['ca'] !== null ? (float) $r['ca'] : null;
                $panier  = ($tickets > 0 && $caPos !== null) ? round($caPos / $tickets, 2) : null;
                $k = $key($r['id_shop'], $yMax, (int) $r['m']);
                if (isset($cells[$k])) {
                    $cells[$k]['tickets'] = $tickets;
                    $cells[$k]['panierMoyen'] = $panier;
                    if ($cells[$k]['ca'] === null) { $cells[$k]['ca'] = $caPos; }
                } else {
                    $cells[$k] = [
                        'storeId' => (string) $r['id_shop'], 'annee' => (int) $yMax, 'mois' => (int) $r['m'],
                        'ca' => $caPos, 'caBudget' => null, 'caTheorique' => null, 'margeNette' => null, 'margePct' => null,
                        'tickets' => $tickets, 'panierMoyen' => $panier, 'foodCostPct' => null,
                        'labourCostPct' => null, 'overheadPct' => null, 'valorisation' => null,
                    ];
                }
            }
        } catch (PDOException $eTx) { /* transaction lente/absente : P&L seul */ }

        // 2ante) Les MOIS DEPUIS JUILLET 2026 se corrigent aux ENDPOINTS : la
        //        caisse locale meurt au 14/07 (tickets de juillet à moitié) et
        //        le P&L partagé garde un début d'août figé — l'endpoint des
        //        KPIs donne CA, tickets et panier exacts, gravés une fois le
        //        mois clos. L'historique d'avant reste aux sources d'époque.
        if (PanelApi::configured() && function_exists('pvKpisMois') && in_array(2026, $annees, true)) {
            try { $idsFix = array_map(fn ($r2) => (int) $r2['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
            catch (PDOException $eF) { $idsFix = []; }
            for ($mFix = '2026-07'; $mFix <= date('Y-m'); $mFix = date('Y-m', strtotime($mFix . '-01 +1 month'))) {
                $numF = (int) substr($mFix, 5, 2);
                foreach ($idsFix as $idF) {
                    $kF = pvKpisMois($idF, $mFix);
                    if ($kF === null) { continue; }
                    $k2 = $key($idF, 2026, $numF);
                    if (!isset($cells[$k2])) {
                        $cells[$k2] = ['storeId' => (string) $idF, 'annee' => 2026, 'mois' => $numF,
                            'ca' => null, 'caBudget' => null, 'caTheorique' => null, 'margeNette' => null, 'margePct' => null,
                            'tickets' => null, 'panierMoyen' => null, 'foodCostPct' => null,
                            'labourCostPct' => null, 'overheadPct' => null, 'valorisation' => null];
                    }
                    $cells[$k2]['ca'] = round($kF['ca'], 2);
                    $cells[$k2]['tickets'] = $kF['tickets'];
                    $cells[$k2]['panierMoyen'] = $kF['panier'] !== null ? round($kF['panier'], 2)
                        : ($kF['tickets'] > 0 ? round($kF['ca'] / $kF['tickets'], 2) : null);
                    // Une marge calculée sur un CA partiel ment : elle s'efface
                    // dès que le CA du P&L diverge nettement du CA réel.
                    if ($cells[$k2]['margePct'] !== null && $cells[$k2]['margePct'] > 0.5) {
                        $cells[$k2]['margePct'] = null; $cells[$k2]['margeNette'] = null;
                    }
                }
            }
        }

        // 2bis) Le MOIS COURANT vient de l'API du panel : la caisse en base
        //       s'arrête à sa dernière journée encodée, l'API sert le jour
        //       même. Seule la cellule du mois en cours est rafraîchie —
        //       l'historique reste celui du P&L mensuel et de la caisse.
        if (in_array((int) date('Y'), $annees, true) && PanelApi::configured()) {
            foreach (analyseListe(PanelApi::shopsSalesKpisEntre(date('Y-m-01'), date('Y-m-d')) ?? []) as $x) {
                $id = 0;
                foreach (['shop_id', 'id_shop', 'id'] as $c2) {
                    if (isset($x[$c2]) && is_numeric($x[$c2])) { $id = (int) $x[$c2]; break; }
                }
                if ($id <= 0) { continue; }
                $caF = nombreOuNull($x, ['ca', 'turnover', 'revenue']);
                $tkF = nombreOuNull($x, ['tickets', 'receipts', 'transactions']);
                $paF = nombreOuNull($x, ['avg_basket', 'basket_avg', 'panier']);
                $k = $key($id, (int) date('Y'), (int) date('n'));
                if (!isset($cells[$k])) {
                    $cells[$k] = [
                        'storeId' => (string) $id, 'annee' => (int) date('Y'), 'mois' => (int) date('n'),
                        'ca' => null, 'caBudget' => null, 'caTheorique' => null, 'margeNette' => null, 'margePct' => null,
                        'tickets' => null, 'panierMoyen' => null, 'foodCostPct' => null,
                        'labourCostPct' => null, 'overheadPct' => null, 'valorisation' => null,
                    ];
                }
                if ($caF !== null) { $cells[$k]['ca'] = round($caF, 2); }
                if ($tkF !== null) { $cells[$k]['tickets'] = (int) $tkF; }
                if ($paF !== null) { $cells[$k]['panierMoyen'] = round($paF, 2); }
            }
        }

        // 2ter) FOOD COST par magasin et par mois — la heatmap de marge du
        //       panel, seule source : le P&L mensuel ne porte pas le poste
        //       matière (ticket T5a). La route plafonne sa fenêtre à 31 jours
        //       (mesuré : 422 au-delà) : UN appel par boutique ET par mois,
        //       avec les bornes `from`/`to` — les seules qu'elle honore.
        //       Un mois CLOS ne change plus : son ratio est mis en cache
        //       (ceo_app_setting), seul le mois courant se recalcule.
        if (PanelApi::configured()) {
            try {
                $shopIds = array_map(fn ($r2) => (int) $r2['id'], Db::rows('SELECT id FROM shops WHERE active = 1'));
            } catch (PDOException $eS) { $shopIds = []; }
            $yF = max($annees);
            $cache = setting('foodCostApi', []);
            if (!is_array($cache)) { $cache = []; }
            $moisFin = $yF < (int) date('Y') ? 12 : (int) date('n');
            $chemins = [];
            foreach ($shopIds as $sid) {
                for ($mm = 1; $mm <= $moisFin; $mm++) {
                    $cle = $sid . '-' . $yF . '-' . $mm;
                    $clos = !($yF === (int) date('Y') && $mm === (int) date('n'));
                    if ($clos && array_key_exists($cle, $cache)) { continue; }
                    $du = sprintf('%04d-%02d-01', $yF, $mm);
                    $au = $clos ? date('Y-m-t', strtotime($du)) : date('Y-m-d');
                    $chemins[$cle] = '/consultant/shops/' . $sid . '/margin-heatmap?'
                        . http_build_query(['from' => $du, 'to' => $au]);
                }
            }
            $change = false;
            foreach (PanelApi::getParallele($chemins) as $cle => $hm) {
                $tot = is_array($hm) ? ($hm['totals'] ?? null) : null;
                $ca2 = is_array($tot) ? (float) ($tot['ca'] ?? 0) : 0.0;
                $mg2 = is_array($tot) ? (float) ($tot['margin_value'] ?? 0) : 0.0;
                $cache[$cle] = $ca2 > 0 ? round((($ca2 - $mg2) / $ca2) * 100, 1) : null;
                $change = true;
            }
            if ($change) {
                try {
                    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                        ['foodCostApi', json_encode($cache)]);
                } catch (PDOException $eFc) { /* cache facultatif */ }
            }
            foreach ($cache as $cle => $pct) {
                if ($pct === null) { continue; }
                [$sid2, $y2, $m2] = array_map('intval', explode('-', (string) $cle));
                $k = $key($sid2, $y2, $m2);
                if (isset($cells[$k])) { $cells[$k]['foodCostPct'] = (float) $pct; }
            }
        }

        // 3) Le BUDGET encodé (`ceo_shop_month_perf`) — table du cockpit, la
        //    seule qui le porte. Ni `mac_shop_monthly_pnl` ni `transaction` ne
        //    connaissent le budget : sans cette passe, l'encodage était écrit
        //    en base et jamais relu, et tous les écrans qui comparent au
        //    budget (suivi budget, heatmap, objectifs de CA) affichaient un
        //    objectif vide sans la moindre erreur.
        //
        //    Un mois budgété SANS réel doit exister aussi : « budget 80 k,
        //    rien encaissé » est une information, pas une ligne à masquer.
        try {
            foreach (Db::rows("SELECT shop_id, year, month, revenue_budget, ca_theorique
                               FROM ceo_shop_month_perf
                               WHERE year IN ($in) AND (revenue_budget IS NOT NULL OR ca_theorique IS NOT NULL)", $annees) as $r) {
                $k = $key($r['shop_id'], $r['year'], $r['month']);
                $bud = $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : null;
                $theo = $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : null;
                if (isset($cells[$k])) {
                    $cells[$k]['caBudget'] = $bud;
                    $cells[$k]['caTheorique'] = $theo;
                } else {
                    $cells[$k] = [
                        'storeId' => (string) $r['shop_id'], 'annee' => (int) $r['year'], 'mois' => (int) $r['month'],
                        'ca' => null, 'caBudget' => $bud, 'caTheorique' => $theo,
                        'margeNette' => null, 'margePct' => null, 'tickets' => null, 'panierMoyen' => null,
                        'foodCostPct' => null, 'labourCostPct' => null, 'overheadPct' => null, 'valorisation' => null,
                    ];
                }
            }
        } catch (PDOException $eBud) { /* table du cockpit absente : réel seul */ }

        return array_values($cells);
    } catch (PDOException $e) {
        return array_map(fn ($r) => [
            'storeId' => $r['shop_id'], 'annee' => (int) $r['year'], 'mois' => (int) $r['month'],
            'ca' => $r['revenue'] !== null ? (float) $r['revenue'] : null,
            'caBudget' => $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : null,
            'caTheorique' => $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : null,
            'margeNette' => $r['net_margin'] !== null ? (float) $r['net_margin'] : null,
            'margePct' => ($r['net_margin'] !== null && $r['revenue'] > 0) ? round($r['net_margin'] / $r['revenue'], 4) : null,
            'tickets' => $r['tickets'] !== null ? (int) $r['tickets'] : null,
            'panierMoyen' => $r['basket_avg'] !== null ? (float) $r['basket_avg'] : null,
            'foodCostPct' => $r['food_pct'] !== null ? (float) $r['food_pct'] : null,
            'labourCostPct' => $r['labour_pct'] !== null ? (float) $r['labour_pct'] : null,
            'overheadPct' => $r['overhead_pct'] !== null ? (float) $r['overhead_pct'] : null,
            'valorisation' => $r['valuation'] !== null ? (float) $r['valuation'] : null,
        ], Db::rows("SELECT * FROM ceo_shop_month_perf WHERE year IN ($in) ORDER BY shop_id, year, month", $annees));
    }
}

/**
 * GET /stores/etp?annees=2025,2026 — ETP réel par boutique et par mois.
 *
 * L'écran Marge & coûts déduisait l'effectif du chiffre d'affaires
 * (max(3, ca/14200)) : un ETP inventé, qui déclenchait pourtant une alerte de
 * dimensionnement d'équipe. On le calcule ici depuis le planning réel :
 * somme des heures planifiées du mois ÷ 168 = 1 ETP (règle du réseau).
 *
 * Un seul appel par boutique. Seules des heures agrégées sont conservées — le
 * flux porte des données personnelles (nom, téléphone) qui n'ont pas à entrer
 * dans le cockpit.
 */
function ep_stores_etp(): array
{
    $annees = array_map('intval', explode(',', $_GET['annees'] ?? date('Y')));
    $out = [];
    if (!PanelApi::configured()) { return $out; }
    try {
        $shops = Db::rows('SELECT id FROM shops WHERE active = 1');
    } catch (PDOException $e) { return $out; }

    $HEURES_ETP = (float) setting('heuresEtpMois', 168);
    foreach ($shops as $sh) {
        $sid = (int) $sh['id'];
        $parMois = [];
        foreach (PanelApi::shopSchedule($sid) as $c) {
            $d = (string) ($c['work_date'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})/', $d, $m)) { continue; }
            $an = (int) $m[1];
            if (!in_array($an, $annees, true)) { continue; }
            $deb = strtotime('1970-01-01 ' . (string) ($c['start_hour'] ?? ''));
            $fin = strtotime('1970-01-01 ' . (string) ($c['end_hour'] ?? ''));
            if ($deb === false || $fin === false) { continue; }
            // Un créneau qui finit avant de commencer passe minuit : sans ça,
            // une nuit compterait des heures négatives.
            if ($fin <= $deb) { $fin += 86400; }
            $h = ($fin - $deb) / 3600;
            if ($h <= 0 || $h > 24) { continue; }
            $k = $an . '-' . (int) $m[2];
            $parMois[$k] = ($parMois[$k] ?? 0) + $h;
        }
        foreach ($parMois as $k => $h) {
            [$an, $mo] = array_map('intval', explode('-', $k));
            $out[] = ['storeId' => (string) $sid, 'annee' => $an, 'mois' => $mo,
                'heures' => round($h, 1), 'etp' => $HEURES_ETP > 0 ? round($h / $HEURES_ETP, 2) : null];
        }
    }
    return $out;
}

function ep_budgets(): array
{
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    $slugByTag = levierSlugByTag();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_shop_budget WHERE fiscal_year = ?', [$exercice]) as $b) {
        $sid = $b['shop_id'];
        // Mois encodés = mois dont le BUDGET est saisi. On comptait les mois
        // ayant un `revenue` dans ceo_shop_month_perf — or le réel vient
        // désormais du panel (mac_shop_monthly_pnl / transaction) et cette
        // colonne reste vide : le compteur affichait « 0 / 12 » même après une
        // saisie complète, laissant croire que l'encodage n'avait rien gardé.
        $enc = Db::row('SELECT COUNT(*) n, MAX(encoded_at) last FROM ceo_shop_month_perf
                        WHERE shop_id = ? AND year = ? AND revenue_budget IS NOT NULL AND revenue_budget > 0',
            [$sid, $exercice]);
        // Les charges viennent du modèle RÉSEAU : les taux sont communs à tous
        // les magasins, seul le chiffre d'affaires qui les traduit en euros
        // change. Les lignes par magasin ne sont plus lues — elles ne sont plus
        // écrites non plus.
        $modele = setting('budgetCharges');
        // La courbe de référence du RÉSEAU : elle sert de repli à un magasin
        // dont l'étude n'est pas encore encodée, et s'édite au même endroit.
        $saisReseau = setting('saisonnaliteReseau', []);
        $saisReseau = is_array($saisReseau) && count($saisReseau) === 12 ? array_map('floatval', $saisReseau) : [];
        $charges = [];
        foreach ((array) ($modele['categories'] ?? []) as $cat) {
            foreach ((array) ($cat['lignes'] ?? []) as $l) {
                $charges[] = [
                    'id' => (string) ($l['id'] ?? ''),
                    'poste' => (string) ($l['poste'] ?? ''), 'levier' => '',
                    'categorie' => (string) ($cat['nom'] ?? ''),
                    'description' => (string) ($l['description'] ?? ''),
                    'gestion' => (string) ($l['gestion'] ?? ''),
                    'pcmn' => (string) ($l['pcmn'] ?? ''),
                    'pctBudget' => (float) ($l['pct'] ?? 0),
                    'pctTheorique' => (float) ($l['pctTheo'] ?? ($l['pct'] ?? 0)),
                    'champReel' => null,
                ];
            }
        }
        $ancien = array_map(fn ($l) => [
            'poste' => $l['label'],
            'levier' => $l['levid'] !== null ? ($slugByTag[(int) $l['levid']] ?? '') : '',
            // Catégorie, description, mode de gestion et compte au plan
            // comptable : une charge se pilote et se comptabilise, le seul
            // pourcentage ne suffisait ni à l'un ni à l'autre.
            'categorie' => (string) ($l['categorie'] ?? ''),
            'description' => (string) ($l['description'] ?? ''),
            'gestion' => (string) ($l['gestion'] ?? ''),
            'pcmn' => (string) ($l['pcmn'] ?? ''),
            'pctBudget' => (float) $l['pct_budget'],
            'pctTheorique' => $l['pct_theorique'] !== null ? (float) $l['pct_theorique'] : null,
            'champReel' => $l['real_field'],
        ], Db::rows('SELECT * FROM ceo_shop_budget_line WHERE shop_id = ? AND fiscal_year = ? ORDER BY sort_order', [$sid, $exercice]));
        // Repli : tant que le modèle réseau est vide, ce qu'un magasin avait
        // encodé reste lisible plutôt que de disparaître de l'écran.
        if ($charges === []) { $charges = $ancien; }
        $out[] = [
            'storeId' => $sid, 'exercice' => $exercice,
            'moisEncodes' => (int) $enc['n'], 'moisTotal' => (int) $b['months_total'],
            'dernierEncodage' => $enc['last'] ? substr($enc['last'], 0, 10) : null,
            'panierEngagement' => $b['basket_target'] !== null ? (float) $b['basket_target'] : null,
            'caTheoriqueAn' => $b['ca_theorique_an'] !== null ? (float) $b['ca_theorique_an'] : null,
            'etudeMarche' => [
                'date' => $b['etude_date'], 'source' => $b['etude_source'],
                'potentielMenages' => $b['etude_potentiel_menages'] !== null ? (int) $b['etude_potentiel_menages'] : null,
                'potentielMaturite' => $b['etude_potentiel_maturite'] !== null ? (float) $b['etude_potentiel_maturite'] : null,
                'anneeExploitation' => $b['annee_exploitation'] !== null ? (int) $b['annee_exploitation'] : null,
                'monteeEnRegime' => $b['montee_regime'] !== null ? json_decode($b['montee_regime'], true) : null,
                'saisonnalite' => $b['saisonnalite'] !== null ? json_decode($b['saisonnalite'], true) : null,
                'annexe' => $b['etude_annexe'] !== null ? json_decode($b['etude_annexe'], true) : null,
            ],
            'charges' => $charges,
            // La courbe du réseau voyage avec chaque magasin : l'écran s'en
            // sert comme repli et l'édite au même endroit.
            'saisonnaliteReseau' => $saisReseau,
            // Ce qui a été RÉELLEMENT encodé, mois par mois et poste par poste.
            // Le modèle dit ce qui est attendu ; ceci dit ce qui est sorti.
            'chargesMois' => chargesEncodees($sid, $exercice),
        ];
    }
    // Le modèle de charges du réseau, joint à la lecture : un magasin qui n'a
    // rien encodé doit pouvoir le reprendre d'un geste, sans que l'écran ait à
    // le connaître par cœur.
    if ($out !== []) {
        $mod = setting('budgetCharges');
        $out[0]['modele'] = is_array($mod) && isset($mod['categories']) ? $mod['categories'] : [];
        // Les étapes intermédiaires voyagent avec le modèle : sans elles,
        // l'écran ne saurait pas où poser « marge brute ».
        $out[0]['paliers'] = is_array($mod) && isset($mod['paliers']) ? $mod['paliers'] : [];
    }
    return $out;
}

/**
 * Les charges encodées d'un magasin, par mois et par poste.
 *
 * Rendu en table indexée `mois => poste => montant` : l'écran interroge un
 * couple, il ne parcourt pas une liste de cent lignes pour trouver la case
 * qu'il affiche.
 */
function chargesEncodees(string $shopId, int $exercice): array
{
    $out = [];
    foreach (Db::rows('SELECT month, poste_id, amount FROM ceo_shop_charge_month
                       WHERE shop_id = ? AND fiscal_year = ?', [$shopId, $exercice]) as $r) {
        $m = (string) (int) $r['month'];
        if (!isset($out[$m])) { $out[$m] = []; }
        $out[$m][(string) $r['poste_id']] = (float) $r['amount'];
    }
    return $out;
}

function ep_targets(): array
{
    $ca = []; $expansion = [];
    foreach (Db::rows('SELECT * FROM ceo_network_target') as $t) {
        $h = $t['horizon'];
        $ca[$h] = ['an' => (int) $t['target_year'], 'cible' => (float) $t['revenue_target']];
        if ($t['note'] !== null) { $ca[$h]['note'] = $t['note']; }
        $expansion[$h] = ['an' => (int) $t['target_year'], 'cible' => (int) $t['openings_target'], 'reel' => (int) $t['openings_real']];
    }
    return ['ca' => $ca, 'expansion' => $expansion, 'caMoyenOuverture' => (float) setting('caMoyenOuverture', 0)];
}

/**
 * GET /referentiels/roles — rôles disponibles, lus dans `atelierby_db`.
 *
 * Le rôle affiché sur un compte ne doit pas être un texte libre : il vient du
 * référentiel du panel (`position`), pour que « Consultant réseau » désigne la
 * même chose des deux côtés. Le schéma de `position` variant, la colonne du
 * libellé est détectée comme le fait le panel plutôt que supposée.
 *
 * Repli, dans l'ordre : `position` → rôles réellement portés par les comptes
 * actifs (user_membership) → liste vide (l'écran laisse alors le champ libre).
 */
function ep_roles(): array
{
    // 1) Référentiel des positions du panel.
    try {
        $cols = array_map(fn ($r) => (string) $r['COLUMN_NAME'],
            Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'position'"));
        if ($cols !== []) {
            $lower = array_map('strtolower', $cols);
            $nameCol = null;
            foreach (['name', 'label', 'title', 'nom', 'libelle'] as $cand) {
                $i = array_search($cand, $lower, true);
                if ($i !== false) { $nameCol = $cols[$i]; break; }
            }
            if ($nameCol === null) {
                foreach ($cols as $c) { if (stripos($c, 'name') !== false) { $nameCol = $c; break; } }
            }
            if ($nameCol !== null) {
                $where = '';
                // Beaucoup de schémas portent un drapeau d'activité : on ne
                // propose pas un rôle désactivé.
                foreach (['active', 'is_active', 'enabled'] as $a) {
                    $i = array_search($a, $lower, true);
                    if ($i !== false) { $where = ' WHERE `' . $cols[$i] . '` = 1'; break; }
                }
                $out = [];
                foreach (Db::rows("SELECT DISTINCT `$nameCol` AS nom FROM `position`$where ORDER BY `$nameCol` LIMIT 200") as $r) {
                    $n = trim((string) $r['nom']);
                    if ($n !== '') { $out[] = $n; }
                }
                if ($out !== []) { return ['source' => 'position', 'roles' => $out]; }
            }
        }
    } catch (PDOException $e) { /* table absente : on tente le repli */ }

    // 2) À défaut, les rôles réellement portés par les comptes actifs.
    try {
        $out = [];
        foreach (Db::rows("SELECT DISTINCT app, scope_type FROM user_membership WHERE is_active = 1") as $r) {
            $app = trim((string) ($r['app'] ?? ''));
            if ($app === '') { continue; }
            $lib = ucfirst(strtolower($app)) . ((string) ($r['scope_type'] ?? '') === 'SHOP' ? ' boutique' : ' réseau');
            if (!in_array($lib, $out, true)) { $out[] = $lib; }
        }
        sort($out);
        if ($out !== []) { return ['source' => 'user_membership', 'roles' => $out]; }
    } catch (PDOException $e) { /* rien */ }

    return ['source' => null, 'roles' => []];
}

function ep_consultants(): array
{
    // Vrais consultants du panel : user_membership(app='CONSULTANT') ⨝ user_profile.
    // Repli sur ceo_consultant pour une installation autonome.
    try {
        $rows = Db::rows(
            "SELECT m.id, m.scope_type, m.scope_id,
                    COALESCE(NULLIF(TRIM(p.display_name), ''),
                             NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))), ''),
                             CONCAT('Consultant #', m.id)) AS nom,
                    NULLIF(TRIM(p.email), '') AS email
               FROM user_membership m
               LEFT JOIN user_profile p ON p.auth_user_id = m.auth_user_id
              WHERE m.app = 'CONSULTANT' AND m.is_active = 1
              ORDER BY nom");
        return array_map(fn ($c) => [
            'id'     => 'u' . $c['id'],
            'nom'    => $c['nom'],
            'role'   => $c['scope_type'] === 'SHOP' ? 'Consultant boutique' : 'Consultant réseau',
            'email'  => $c['email'],
            'tjm'    => null,        // TJM/charge : données RH propres au cockpit, non présentes côté panel
            'charge' => null,
            'visites' => [],         // les visites (mac_consultant_visit) sont branchées séparément si présentes
        ], $rows);
    } catch (PDOException $e) {
        $out = [];
        foreach (Db::rows('SELECT * FROM ceo_consultant ORDER BY id') as $c) {
            $visites = array_map(fn ($v) => ['date' => $v['visited_on'], 'store' => $v['store_label'], 'objet' => $v['subject']],
                Db::rows('SELECT * FROM ceo_consultant_visit WHERE consultant_id = ? ORDER BY visited_on DESC', [$c['id']]));
            $out[] = ['id' => $c['id'], 'nom' => $c['name'], 'role' => $c['role'], 'email' => $c['email'],
                'tjm' => $c['daily_rate'] !== null ? (float) $c['daily_rate'] : null,
                'charge' => $c['workload'] !== null ? (int) $c['workload'] : null, 'visites' => $visites];
        }
        return $out;
    }
}

function ep_suppliers(): array
{
    return array_map(fn ($s) => ['id' => $s['id'], 'nom' => $s['name'], 'perim' => $s['perimeter'], 'email' => $s['email']],
        Db::rows('SELECT * FROM ceo_supplier ORDER BY id'));
}

function ep_projects(): array
{
    $slugByTag = levierSlugByTag();
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_project ORDER BY id') as $p) {
        $id = $p['id'];
        $leviers = array_map(fn ($r) => $slugByTag[(int) $r['levid']] ?? '', Db::rows('SELECT levid FROM ceo_project_levid WHERE project_id = ?', [$id]));
        $jalons = array_map(fn ($j) => ['nom' => $j['name'], 'cible' => $j['target_on'], 'reel' => $j['done_on']],
            Db::rows('SELECT * FROM ceo_project_milestone WHERE project_id = ? ORDER BY sort_order, id', [$id]));
        $couts = array_map(fn ($c) => ['poste' => $c['label'], 'prevu' => (float) $c['planned'], 'reel' => (float) $c['actual']],
            Db::rows('SELECT * FROM ceo_project_cost WHERE project_id = ? ORDER BY id', [$id]));
        $taches = array_map(fn ($t) => [
            'id' => $t['id'], 'nom' => $t['name'],
            'owner' => ['t' => $t['owner_kind'], 'id' => $t['owner_id']],
            'magasin' => $t['shop_id'], 'due' => $t['due_on'], 'done' => $t['done_on'],
            'relance' => $t['reminded_on'], 'desc' => $t['description'],
            'valideeLe' => $t['validated_at'] ?? null,
            'renduePar' => $t['delivered_by'] ?? null,
            'noteRemise' => $t['delivery_note'] ?? null,
            'budget' => $t['budget'] !== null ? (float) $t['budget'] : null,
            // `note` nulle sur une tâche rendue = elle attend une validation :
            // c'est ce qui alimente le groupe « À valider » de l'écran.
            'note' => $t['note'] !== null ? (int) $t['note'] : null,
            'valideePar' => $t['validated_by'],
            'signalement' => tacheSignalement($t['id']),
            // La tâche de contrôle d'origine, quand la tâche est née d'une note
            // posée sur une photo : de quoi retrouver le cliché et ses repères.
            'panelNote' => isset($t['panel_note']) && $t['panel_note'] !== null ? (int) $t['panel_note'] : null,
            'source' => (($t['src_task'] ?? null) && ($t['src_date'] ?? null))
                ? ['shopId' => (string) ($t['src_shop'] ?? ''), 'taskId' => (string) $t['src_task'],
                   'date' => (string) $t['src_date']]
                : null,
        ], Db::rows('SELECT * FROM ceo_project_task WHERE project_id = ? ORDER BY id', [$id]));
        $out[] = [
            'id' => $id, 'nom' => $p['name'], 'famille' => $p['famille'], 'statut' => $p['status'], 'prio' => $p['priority'],
            'debut' => $p['starts_on'], 'fin' => $p['ends_on'],
            'axes' => $p['axes_json'] ? json_decode($p['axes_json'], true) : [$p['axe']],
            'leviers' => $leviers,
            'budget' => $p['budget'] !== null ? (float) $p['budget'] : null,
            'valeurEst' => $p['value_est'] !== null ? (float) $p['value_est'] : null,
            'valeurReal' => $p['value_real'] !== null ? (float) $p['value_real'] : null,
            'valeurTxt' => $p['value_txt'],
            'kpis' => $p['kpis_json'] ? json_decode($p['kpis_json'], true) : [],
            // Les hypothèses économiques du projet, telles qu'elles ont été
            // posées à sa création : marge visée, prix, volumes, royalties.
            'economie' => ($p['economie_json'] ?? null) ? json_decode($p['economie_json'], true) : null,
            'jalons' => $jalons, 'taches' => $taches, 'couts' => $couts,
        ];
    }
    return $out;
}

/**
 * Le dernier signalement d'une tâche, ouvert ou clos.
 *
 * Une tâche validée sous le seuil garde son signalement au suivi : l'écran
 * affiche « 1 ouvert depuis 4 jours » sur l'intervenant, ce qui n'a de sens
 * que si la ligne survit à la clôture de la tâche.
 */
function tacheSignalement(string $taskId): ?array
{
    $r = Db::row('SELECT * FROM ceo_task_issue WHERE task_id = ? ORDER BY id DESC LIMIT 1', [$taskId]);
    if ($r === null) {
        return null;
    }
    return [
        'id'      => (int) $r['id'],
        'note'    => (int) $r['note'],
        'famille' => $r['famille'],
        'type'    => $r['type'],
        'comment' => $r['comment'],
        'copie'   => $r['recipients'] !== null && $r['recipients'] !== '' ? explode(',', $r['recipients']) : [],
        'statut'  => $r['status'],
        'ouvert'  => $r['closed_at'] === null,
        'creeLe'  => $r['created_at'],
        'creePar' => $r['created_by'],
    ];
}

/**
 * Suivi des tâches — les chiffres d'une période, et les signalements à traiter.
 *
 * Calculé en SQL plutôt qu'au client : l'écran doit pouvoir répondre « combien
 * de tâches validées cette semaine » sans charger tous les projets, et le même
 * calcul sert au rapport hebdomadaire et au rapport mensuel.
 *
 * `periode` vaut `semaine` ou `mois`. La borne est la date du jour côté base —
 * pas celle du navigateur, qui peut être n'importe où.
 */
function ep_taches_suivi(string $periode = 'mois'): array
{
    $jours = $periode === 'semaine' ? 7 : 30;
    $depuis = date('Y-m-d', strtotime('-' . $jours . ' days'));

    // Les tâches validées sur la période, bornées sur la date de VALIDATION.
    // Borner sur `done_on` — la livraison — situerait une validation d'
    // aujourd'hui dans le mois où la tâche a été rendue : une tâche livrée en
    // mars et jugée en août n'apparaîtrait dans aucun suivi utile.
    // COALESCE pour les validations d'avant l'existence de la colonne.
    $val = Db::rows(
        'SELECT t.id, t.name, t.note, t.done_on, t.validated_at, t.owner_kind, t.owner_id, t.shop_id,'
        . ' p.name AS projet FROM ceo_project_task t'
        . ' JOIN ceo_project p ON p.id = t.project_id'
        . ' WHERE t.note IS NOT NULL AND COALESCE(t.validated_at, t.done_on) >= ?'
        . ' ORDER BY COALESCE(t.validated_at, t.done_on) DESC', [$depuis]);

    $notes = array_map(static fn ($r) => (int) $r['note'], $val);
    $repartition = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    foreach ($notes as $n) { $repartition[$n] = ($repartition[$n] ?? 0) + 1; }

    // Les signalements : ceux de la période, plus TOUS ceux qui restent
    // ouverts. Un signalement de trois semaines qui traîne doit apparaître
    // dans le suivi de la semaine — c'est même le premier à devoir sauter aux
    // yeux, alors qu'une borne de date l'aurait masqué.
    $sig = Db::rows(
        'SELECT i.*, t.name AS tache, t.owner_kind, t.owner_id, p.name AS projet'
        . ' FROM ceo_task_issue i'
        . ' JOIN ceo_project_task t ON t.id = i.task_id'
        . ' JOIN ceo_project p ON p.id = t.project_id'
        . ' WHERE i.closed_at IS NULL OR i.created_at >= ?'
        . ' ORDER BY (i.closed_at IS NULL) DESC, i.note ASC, i.created_at ASC', [$depuis]);

    $signalements = array_map(static fn ($r) => [
        'id'       => (int) $r['id'],
        'tacheId'  => $r['task_id'],
        'tache'    => $r['tache'],
        'projet'   => $r['projet'],
        'owner'    => ['t' => $r['owner_kind'], 'id' => $r['owner_id']],
        'note'     => (int) $r['note'],
        'famille'  => $r['famille'],
        'type'     => $r['type'],
        'comment'  => $r['comment'],
        'statut'   => $r['status'],
        'ouvert'   => $r['closed_at'] === null,
        'creeLe'   => $r['created_at'],
        'creePar'  => $r['created_by'],
        'vuLe'     => $r['seen_at'],
        'closLe'   => $r['closed_at'],
        'closPar'  => $r['closed_by'],
    ], $sig);

    // Par intervenant : ce qui permet de dire à qui parler, pas seulement
    // combien de lignes il reste.
    $par = [];
    foreach ($val as $r) {
        $k = $r['owner_kind'] . ':' . $r['owner_id'];
        $par[$k] ??= ['owner' => ['t' => $r['owner_kind'], 'id' => $r['owner_id']], 'validees' => 0, 'somme' => 0, 'sousSeuil' => 0, 'ouverts' => 0];
        $par[$k]['validees']++;
        $par[$k]['somme'] += (int) $r['note'];
    }
    foreach ($signalements as $g) {
        $k = $g['owner']['t'] . ':' . $g['owner']['id'];
        $par[$k] ??= ['owner' => $g['owner'], 'validees' => 0, 'somme' => 0, 'sousSeuil' => 0, 'ouverts' => 0];
        if ($g['ouvert']) { $par[$k]['ouverts']++; }
    }
    $parIntervenant = array_values(array_map(static function (array $x): array {
        $x['moyenne'] = $x['validees'] > 0 ? round($x['somme'] / $x['validees'], 2) : null;
        unset($x['somme'], $x['sousSeuil']);
        return $x;
    }, $par));

    $ouverts = count(array_filter($signalements, static fn ($g) => $g['ouvert']));
    return [
        'periode'    => $periode,
        'depuis'     => $depuis,
        'validees'   => count($val),
        'moyenne'    => $notes !== [] ? round(array_sum($notes) / count($notes), 2) : null,
        'repartition' => $repartition,
        'ouverts'    => $ouverts,
        'traites'    => count($signalements) - $ouverts,
        'signalements' => $signalements,
        'parIntervenant' => $parIntervenant,
        'taches'     => array_map(static fn ($r) => [
            'id' => $r['id'], 'nom' => $r['name'], 'note' => (int) $r['note'],
            'le' => $r['validated_at'] ?? $r['done_on'], 'projet' => $r['projet'],
            'owner' => ['t' => $r['owner_kind'], 'id' => $r['owner_id']],
        ], $val),
    ];
}

function ep_crm(): array
{
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_project_crm') as $r) {
        $out[$r['project_id']] = ['gain' => $r['gain'], 'apport' => $r['apport'], 'objectif' => $r['objectif'],
            'attendu' => $r['attendu'] !== null ? (float) $r['attendu'] : null,
            'realise' => $r['realise'] !== null ? (float) $r['realise'] : null];
    }
    return $out;
}

/**
 * Les personnes destinataires des rapports.
 *
 * Source de vérité : les comptes du panel (`user_membership` ⨝ `user_profile`),
 * comme pour les consultants. `ceo_person` n'est qu'un repli d'installation
 * autonome — sur une base réelle il est vide, et l'écran Reporting n'offrait
 * alors AUCUN destinataire sélectionnable, sans le moindre message.
 *
 * On rend TOUS les comptes actifs, adresse ou non. Filtrer sur l'e-mail
 * paraissait raisonnable — « un destinataire sans adresse n'en est pas un » —
 * mais sur la vraie base cela faisait disparaître trois consultants sur cinq
 * de la liste, sans rien dire. On ne peut pas corriger une fiche qu'on ne voit
 * plus : l'écran les montre, et signale l'adresse manquante.
 */
function ep_people(): array
{
    try {
        $rows = Db::rows(
            "SELECT m.id, m.app, m.scope_type,
                    COALESCE(NULLIF(TRIM(p.display_name), ''),
                             NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))), ''),
                             CONCAT('Compte #', m.id)) AS nom,
                    NULLIF(TRIM(p.email), '') AS email
               FROM user_membership m
               LEFT JOIN user_profile p ON p.auth_user_id = m.auth_user_id
              WHERE m.is_active = 1 AND p.auth_user_id IS NOT NULL
              GROUP BY m.id, m.app, m.scope_type, nom, email
              ORDER BY nom");
        if ($rows !== []) {
            return array_map(static fn ($r) => [
                'id'    => 'u' . $r['id'],
                'nom'   => $r['nom'],
                'role'  => $r['app'] === 'CONSULTANT'
                    ? ($r['scope_type'] === 'SHOP' ? 'Consultant boutique' : 'Consultant réseau')
                    : ucfirst(strtolower((string) $r['app'])),
                'email' => $r['email'],
                // Un rapport ne part pas sans adresse : l'écran doit pouvoir le
                // dire, plutôt que de proposer un destinataire injoignable.
                'joignable' => $r['email'] !== null && $r['email'] !== '',
            ], $rows);
        }
    } catch (PDOException $e) { /* tables du panel absentes : repli local */ }

    return array_map(fn ($p) => ['id' => $p['id'], 'nom' => $p['name'], 'role' => $p['role'], 'email' => $p['email']],
        Db::rows('SELECT * FROM ceo_person ORDER BY id'));
}

function ep_reporting(): array
{
    $reports = array_map(fn ($r) => [
        'id' => $r['id'], 'nom' => $r['name'], 'type' => $r['type'], 'desc' => $r['description'],
        'freq' => $r['frequency'], 'postes' => json_decode($r['postes_json'], true),
        'destId' => $r['dest_id'], 'ccId' => $r['cc_id'] ?? '',
        'dernier' => $r['last_run'], 'actif' => (bool) $r['active'],
    ], Db::rows('SELECT * FROM ceo_report_schedule ORDER BY id'));
    $alertRules = array_map(fn ($a) => ['id' => $a['id'], 'nom' => $a['name'], 'canal' => $a['channel'], 'actif' => (bool) $a['active']],
        Db::rows('SELECT * FROM ceo_alert_rule ORDER BY id'));
    return ['reports' => $reports, 'alertRules' => $alertRules];
}

function ep_journal(): array
{
    return array_map(fn ($l) => [
        'ts' => substr($l['happened_at'], 0, 16), 'qui' => $l['actor'], 'type' => $l['kind'],
        'projet' => $l['project'] ?? '—', 'msg' => $l['message'],
    ], Db::rows('SELECT * FROM ceo_journal_entry ORDER BY happened_at DESC, id DESC LIMIT 500'));
}

/**
 * GET /journal/mails — les e-mails partis du cockpit, les deux sources réunies.
 *
 * Deux machines envoient : les RAPPORTS (ceo_rapport_run, statut « envoye » ou
 * « erreur », avec la liste des destinataires) et les commandes de la CENTRALE
 * D'ACHAT (ceo_app_setting.caMailJournal, tenu par caMailJournal()). Les lire
 * séparément obligeait à ouvrir deux écrans pour répondre à « ce mail est-il
 * parti ? » ; ils sont donc fusionnés ici, du plus récent au plus ancien.
 *
 * Une source absente n'est pas une panne : une installation sans centrale
 * d'achat n'a pas de journal d'achats, et le tableau montre l'autre.
 */
function ep_journal_mails(): array
{
    $out = [];

    try {
        foreach (Db::rows("SELECT r.genere_le, r.statut, r.envoye_a, r.resume, p.nom
                             FROM ceo_rapport_run r
                             LEFT JOIN ceo_rapport p ON p.id = r.rapport_id
                            WHERE r.statut IN ('envoye', 'erreur')
                         ORDER BY r.genere_le DESC, r.id DESC LIMIT 120") as $r) {
            $dests = json_decode((string) ($r['envoye_a'] ?? '[]'), true);
            $out[] = [
                'ts' => substr((string) $r['genere_le'], 0, 16),
                'source' => 'Rapport',
                'objet' => (string) ($r['nom'] ?? 'Rapport supprimé'),
                'dest' => is_array($dests) ? implode(', ', $dests) : '',
                'ok' => $r['statut'] === 'envoye',
                'detail' => (string) ($r['resume'] ?? ''),
            ];
        }
    } catch (PDOException $e) { /* rapports jamais installés : l'autre source suffit */ }

    $achats = setting('caMailJournal');
    foreach (is_array($achats) ? $achats : [] as $a) {
        if (!is_array($a)) { continue; }
        // « recu » n'est pas un envoi : c'est la commande repérée, la trace qui
        // explique l'e-mail de la ligne suivante. Elle reste, en le disant.
        $type = (string) ($a['type'] ?? '');
        $out[] = [
            'ts' => substr((string) ($a['quand'] ?? ''), 0, 16),
            'source' => 'Achat',
            'objet' => $type === 'recu' ? 'Commande reçue' : 'Commande fournisseur',
            'dest' => (string) ($a['destinataire'] ?? ''),
            'ok' => $type !== 'echec',
            'detail' => (string) ($a['detail'] ?? ''),
        ];
    }

    usort($out, fn ($a, $b) => strcmp((string) $b['ts'], (string) $a['ts']));
    return array_slice($out, 0, 200);
}

function ep_products(): array
{
    $periode = $_GET['periode'] ?? '2026-07';
    if (!preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) { $m = [null, '2026', '07']; }
    $annee = (int) $m[1]; $mois = (int) $m[2];

    // Vraies ventes par produit sur le mois (lignes de caisse `transaction_product`
    // ⨝ `transaction` pour la période et le magasin). Mêmes bornes que la perf.
    // coutUnit = null : le COÛT matière n'est PAS dans la base partagée (il vient
    // de l'API amont, cf. panel) → marge non calculable ici. Requête bornée à un
    // mois, plafonnée MySQL et encapsulée. Repli sur ceo_product si les tables POS
    // sont absentes (installation autonome).
    try {
        $venteMois = static function (string $from, string $to): array {
            return Db::rows("SELECT /*+ MAX_EXECUTION_TIME(6000) */
                                    tp.id_product,
                                    MAX(tp.product_name) nom,
                                    SUM(tp.quantity) volume,
                                    SUM(tp.total_gross_value_after_discount) ca,
                                    COUNT(DISTINCT t.id_shop) magasins
                             FROM transaction t
                             JOIN transaction_product tp ON tp.id_transaction = t.id
                             WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                             GROUP BY tp.id_product
                             ORDER BY volume DESC
                             LIMIT 200", [$from, $to]);
        };
        $from = sprintf('%04d-%02d-01 00:00:00', $annee, $mois);
        $to   = date('Y-m-01 00:00:00', strtotime("$from +1 month"));
        $rows = $venteMois($from, $to);
        // Période demandée sans vente (mois courant partiel, ou installation
        // fraîche) : replier sur le dernier mois de caisse réellement encodé.
        if (!$rows) {
            $last = Db::row("SELECT /*+ MAX_EXECUTION_TIME(4000) */
                                    DATE_FORMAT(MAX(insert_timestamp), '%Y-%m-01 00:00:00') d FROM transaction");
            if ($last !== null && $last['d'] !== null) {
                $from = $last['d'];
                $to   = date('Y-m-01 00:00:00', strtotime("$from +1 month"));
                $rows = $venteMois($from, $to);
            }
        }

        // Mémoriser la période réellement servie : la modale de détail doit
        // interroger la même fenêtre que le tableau.
        try {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['periodeProduits', json_encode(substr($from, 0, 7))]);
        } catch (PDOException $eP) { /* sans importance */ }

        // PERTES par référence — API du panel (/shops/{id}/products/waste), la
        // seule source : la base partagée ne connaît que les ventes. On agrège
        // les quantités jetées et vendues sur TOUTES les boutiques pour la même
        // période que le volume, puis on en tire un taux réseau. Le volet
        // rapporte aussi la catégorie réelle et le motif principal de rebut.
        $perteVol = []; $catApi = []; $motif = [];
        if (PanelApi::configured()) {
            $dFrom = substr($from, 0, 10);
            $dTo   = date('Y-m-d', strtotime($to . ' -1 day'));
            try {
                $shopIds = array_map(fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1'));
            } catch (PDOException $eS) { $shopIds = []; }
            foreach ($shopIds as $sid) {
                foreach (PanelApi::shopWaste($sid, $dFrom, $dTo) as $w) {
                    $pid = (int) ($w['id_product'] ?? 0);
                    if ($pid <= 0) { continue; }
                    // `waste_qty` est bien propre au magasin (il diffère d'un
                    // magasin à l'autre) et s'additionne. `sold_qty`, lui, est
                    // rendu IDENTIQUE pour tous les magasins : c'est une valeur
                    // réseau. L'additionner multipliait les ventes par le nombre
                    // de magasins et écrasait le taux. On l'ignore : le volume
                    // vendu vient de notre propre agrégat de caisse.
                    $perteVol[$pid] = ($perteVol[$pid] ?? 0) + (float) ($w['waste_qty'] ?? 0);
                    $cn = trim((string) ($w['category_name'] ?? ''));
                    if ($cn !== '' && !isset($catApi[$pid])) { $catApi[$pid] = $cn; }
                    $tr = trim((string) ($w['top_reason'] ?? ''));
                    if ($tr !== '' && !isset($motif[$pid])) { $motif[$pid] = $tr; }
                }
            }
        }

        // COÛT MATIÈRE — sans lui, « marge nette » (30 % du score) reste nulle
        // pour tout le monde et le classement se joue sur le seul volume.
        // Trois sources, dans cet ordre : les recettes du réseau
        // (product_recipe ⨝ recipe_cost, ~422 références sur 711), le panel
        // pour les références sans recette (recipe_cost_net de
        // /shops/{id}/products/available, dans catalogueCouts), puis la
        // saisie du cockpit qui prime — elle corrige au cas par cas.
        // Le rapprochement se fait par identifiant de caisse UNIQUEMENT,
        // jamais par l'intitulé : deux références peuvent porter des noms
        // voisins et une marge fausse ne se voit pas.
        $cout = [];
        foreach (catalogueCouts() as $pid => $c) { $cout[$pid] = $c['mat']; }
        try {
            foreach (Db::rows('SELECT pwa_id, mat FROM ceo_prod_product WHERE pwa_id IS NOT NULL AND mat IS NOT NULL AND actif = 1') as $c) {
                $cout[(int) $c['pwa_id']] = (float) $c['mat'];
            }
        } catch (PDOException $eC) { /* référentiel absent : recettes seules */ }

        // Catégorie : le vrai catalogue (product ⨝ product_category), indexé
        // par identifiant de caisse. La table miroir sig_products porte des
        // identifiants d'un autre format (« pwp1000001 ») : la recherche par
        // id numérique n'y trouvait jamais rien, et la catégorie retombait
        // silencieusement sur « Non catégorisé » dès que l'API était muette.
        $cat = [];
        $refCat = catalogueCategories();
        if ($refCat !== null) {
            try {
                foreach (Db::rows('SELECT id, id_category FROM product WHERE is_active = 1') as $c) {
                    $k = (int) $c['id_category'];
                    if (isset($refCat[$k])) { $cat[(int) $c['id']] = $refCat[$k]['nom']; }
                }
            } catch (PDOException $eCat) { /* catalogue absent : catégorie vide */ }
        }

        return array_map(function ($r) use ($cat, $catApi, $perteVol, $motif, $cout) {
            $vol  = (float) $r['volume'];
            $prix = $vol > 0 ? round((float) $r['ca'] / $vol, 2) : null;
            $pid  = (int) $r['id_product'];
            // Taux de perte = jeté / (vendu + jeté) sur la période, en part
            // (0..1). Dénominateur = ce qui a été PRODUIT et proposé, sinon un
            // produit très jeté mais peu vendu afficherait un taux > 100 %.
            $tp = null;
            if (isset($perteVol[$pid])) {
                $den = $vol + $perteVol[$pid];      // vendu (notre agrégat) + jeté
                if ($den > 0) { $tp = round($perteVol[$pid] / $den, 4); }
            }
            return [
                'id'        => (string) $pid,
                'nom'       => ($r['nom'] !== null && $r['nom'] !== '') ? $r['nom'] : ('#' . $pid),
                // Catégorie : celle de l'API (fiable) avant le référentiel local.
                'categorie' => $catApi[$pid] ?? $cat[$pid] ?? 'Non catégorisé',
                'volume'    => (int) round($vol),
                'prix'      => $prix,
                // « Marge nette » pèse 30 % du score. Un coût mal chiffré —
                // au-dessus du prix comme quasi nul — y ferait plus de dégâts
                // que son absence, qui est déjà gérée (critère neutralisé).
                'coutUnit'  => (isset($cout[$pid]) && coutVraisemblable($cout[$pid], $prix))
                    ? $cout[$pid] : null,
                'tendVol'   => 1,
                'magasins'  => (int) $r['magasins'],
                'tauxPerte' => $tp,
                'jete'      => isset($perteVol[$pid]) ? (int) round($perteVol[$pid]) : null,
                'motifPerte' => $motif[$pid] ?? null,
            ];
        }, $rows);
    } catch (PDOException $e) {
        $rows = Db::rows(
            'SELECT p.id, p.nom, p.categorie, s.volume, s.nb_magasins, s.prix_moyen, s.cout_unitaire,
                    n1.volume AS volume_n1
               FROM ceo_product p
               JOIN ceo_product_month_sales s  ON s.product_id = p.id AND s.annee = ? AND s.mois = ?
          LEFT JOIN ceo_product_month_sales n1 ON n1.product_id = p.id AND n1.annee = ? AND n1.mois = ?
              WHERE p.actif = 1 ORDER BY p.id', [$annee, $mois, $annee - 1, $mois]);
        return array_map(fn ($r) => [
            'id' => $r['id'], 'nom' => $r['nom'], 'categorie' => $r['categorie'],
            'volume' => (int) $r['volume'], 'prix' => (float) $r['prix_moyen'], 'coutUnit' => (float) $r['cout_unitaire'],
            'tendVol' => $r['volume_n1'] ? round($r['volume'] / $r['volume_n1'], 4) : 1,
            'magasins' => (int) $r['nb_magasins'],
        ], $rows);
    }
}

/**
 * Sonde : existe-t-il une source de ventes PAR MAGASIN sous le niveau groupe ?
 *
 * Diagnostic en lecture seule. Il répond à deux questions qu'on ne peut pas
 * trancher en raisonnant : `sold_qty` varie-t-il d'un magasin à l'autre (donc
 * est-il propre au magasin, ou réseau ?), et les routes câblées mais jamais
 * exploitées — margin-heatmap, pnl — portent-elles une ventilation par
 * catégorie ? Tant qu'on ne l'a pas mesuré, toute réponse est une supposition.
 */
function ep_produits_analyse_sonde(): array
{
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    $ref = setting('periodeProduits');
    $per = (is_string($ref) && preg_match('/^\d{4}-\d{2}$/', $ref)) ? $ref : date('Y-m', strtotime('-1 month'));
    $du = $per . '-01'; $au = date('Y-m-t', strtotime($du));
    $pid = trim((string) ($_GET['produit'] ?? '1610004'));
    $out = ['periode' => $per, 'du' => $du, 'au' => $au, 'produit' => $pid,
        'soldParMagasin' => [], 'routes' => []];

    // 1. La même référence, magasin par magasin. Si `sold_qty` est identique
    //    partout, c'est une valeur réseau et le détail par magasin n'existe pas.
    $req = [];
    foreach (analyseShops() as $sid) {
        $req[$sid] = '/shops/' . $sid . '/products/waste?' . http_build_query(
            ['from' => $du, 'date_from' => $du, 'to' => $au, 'date_to' => $au]);
    }
    foreach (PanelApi::getParallele($req) as $sid => $r) {
        $lignes = (is_array($r) && isset($r['products']) && is_array($r['products'])) ? $r['products'] : analyseListe($r);
        foreach ($lignes as $p) {
            if (trim((string) ($p['id_product'] ?? '')) !== $pid) { continue; }
            $out['soldParMagasin'][(string) $sid] = [
                'sold_qty' => $p['sold_qty'] ?? null, 'waste_qty' => $p['waste_qty'] ?? null,
                'net_turnover' => $p['net_turnover'] ?? null,
                'total_sold_quantity' => $p['total_sold_quantity'] ?? null];
            break;
        }
    }
    $vus = array_values(array_unique(array_map(fn($x) => (string) $x['sold_qty'], $out['soldParMagasin'])));
    $out['verdict'] = count($out['soldParMagasin']) < 2 ? 'indéterminé (moins de deux magasins)'
        : (count($vus) === 1 ? 'sold_qty IDENTIQUE partout → valeur RÉSEAU, pas de détail par magasin'
                             : 'sold_qty DIFFÈRE selon le magasin → valeur PROPRE au magasin');

    // 2. Les routes jamais exploitées portent-elles une ventilation utilisable ?
    $sid = analyseShop();
    $apercu = static function ($v) {
        if (!is_array($v)) { return ['type' => gettype($v)]; }
        if (array_is_list($v)) {
            return ['liste' => count($v),
                'clesPremier' => ($v && is_array($v[0])) ? array_keys($v[0]) : null,
                'premier' => $v[0] ?? null];
        }
        return ['cles' => array_keys($v)];
    };
    foreach ([
        'margin-heatmap' => '/consultant/shops/' . $sid . '/margin-heatmap?' . http_build_query(['date_from' => $du, 'date_to' => $au]),
        'pnl'            => '/consultant/shops/' . $sid . '/pnl?' . http_build_query(['date_from' => $du, 'date_to' => $au]),
        'category-sales' => '/consultant/shops/category-sales?' . http_build_query(['shop_id' => $sid, 'date_from' => $du, 'date_to' => $au]),
    ] as $nom => $chemin) {
        $r = PanelApi::brut($chemin);
        $e = ['chemin' => $chemin, 'erreur' => PanelApi::$lastError];
        if (is_array($r)) {
            $e['cles'] = array_keys($r);
            foreach (['categories', 'items', 'rows', 'data', 'heatmap', 'shops', 'lines'] as $k) {
                if (isset($r[$k])) { $e[$k] = $apercu($r[$k]); }
            }
            if (array_is_list($r)) { $e['racine'] = $apercu($r); }
        }
        $out['routes'][$nom] = $e;
    }

    // 3. Le catalogue du panel porte-t-il une IMAGE de présentation ?
    //    La question décide de ce qu'un écran peut promettre : une fiche de
    //    vente sans visuel n'est pas une fiche de vente. On compte, on ne
    //    suppose pas — et on nomme les clés trouvées, pour que le rapprochement
    //    ne soit pas deviné.
    $prods = PanelApi::brut('/products');
    $liste = is_array($prods) ? (array_is_list($prods) ? $prods : ($prods['products'] ?? $prods['data'] ?? [])) : [];
    $clesImg = ['url', 'image_url', 'photo_url', 'picture', 'image', 'thumbnail', 'photo',
        'attachment_id', 'id_attachment', 'image_id', 'media', 'images'];
    $avec = []; $n = 0;
    foreach ($liste as $p) {
        if (!is_array($p)) { continue; }
        $n++;
        foreach ($clesImg as $k) {
            if (!empty($p[$k])) { $avec[$k] = ($avec[$k] ?? 0) + 1; }
        }
    }
    $out['catalogueImage'] = [
        'chemin' => '/products', 'erreur' => PanelApi::$lastError,
        'produits' => $n,
        'clesPremier' => ($liste && is_array($liste[0] ?? null)) ? array_keys($liste[0]) : null,
        'clesImageRemplies' => $avec,
        'verdict' => $n === 0 ? 'catalogue injoignable'
            : ($avec ? 'une image existe : ' . implode(', ', array_keys($avec))
                     : 'AUCUNE image de présentation sur les ' . $n . ' produits'),
    ];
    return $out;
}

/* ==========================================================================
   CENTRALE D'ACHAT
   --------------------------------------------------------------------------
   Le module couvre dix écrans. Quatre reposent sur des données que le cockpit
   possède réellement — le catalogue et son coût matière, les ventes du panel,
   les magasins, les fournisseurs. Les six autres attendent des sources qui
   n'existent nulle part : commandes fournisseurs, commandes franchisés,
   factures, stock, campagnes marketing, assortiments.

   Ces six-là ne sont PAS remplis de données fabriquées. Un écran qui invente
   ses chiffres ne se distingue pas d'un écran qui marche, et se découvre le
   jour où quelqu'un s'en sert pour décider. Chacun annonce donc la source
   qu'il attend, nommée, pour que le branchement soit une tâche et non une
   enquête.
   ========================================================================== */

/** Réglages du moteur (commission, TVA, objectifs de négociation). */
function caParams(): array
{
    // Mémorisé : le catalogue applique la commission à 711 lignes, et setting()
    // fait un SELECT à chaque appel — sept cents requêtes pour un seul écran.
    static $p = null;
    if ($p !== null) { return $p; }
    try { $v = setting('centrale'); } catch (PDOException $e) { $v = null; }
    return $p = is_array($v) ? $v : ['commissionMarquePct' => 4.0, 'margeCentraleCiblePct' => 12.0,
        'tvaDefautPct' => 6.0, 'objectifBaissePrixPct' => 3.0, 'objectifHausseVolPct' => 10.0];
}

/**
 * Marge d'une référence, à l'identique du handoff :
 *   commission = prix_vente × commission_marque_pct/100
 *   marge_nette = (prix_vente − prix_achat) − commission
 *   taux = marge_nette / prix_vente
 */
function caMarge(?float $vente, ?float $achat, float $commPct): array
{
    if ($vente === null || $achat === null || $vente <= 0) {
        return ['ca' => $vente, 'commission' => null, 'brute' => null, 'nette' => null, 'taux' => null];
    }
    $comm = round($vente * $commPct / 100, 2);
    $brute = round($vente - $achat, 2);
    $nette = round($brute - $comm, 2);
    return ['ca' => round($vente, 2), 'commission' => $comm, 'brute' => $brute,
        'nette' => $nette, 'taux' => round($nette / $vente, 4)];
}

/** Bloc « source absente », de forme constante pour que l'écran sache l'afficher. */
function caAttente(string $quoi, string $source): array
{
    return ['etat' => 'attente', 'lignes' => [],
        'motif' => 'en attente d\'API — ' . $quoi . ' : ' . $source];
}

/** Commission de marque sur le prix de vente (taux des réglages centrale). */
function caCommission(?float $vente): ?float
{
    if ($vente === null || $vente <= 0) { return null; }
    return round($vente * (float) (caParams()['commissionMarquePct'] ?? 4.0) / 100, 3);
}

/** Marge après commission de marque — la marge que la centrale pilote. */
function caMargeNette(?float $vente, ?float $achat): ?float
{
    if ($vente === null || $achat === null || $vente <= 0) { return null; }
    return round($vente - $achat - (float) caCommission($vente), 3);
}

function caMargeNettePct(?float $vente, ?float $achat): ?float
{
    $n = caMargeNette($vente, $achat);
    return ($n === null || $vente === null || $vente <= 0) ? null : round($n / $vente, 4);
}

/**
 * Conservé pour la route /centrale/catalogue, sans entrée au rail : le
 * catalogue vit au Référentiel produit, qui porte désormais la marge nette.
 * Deux écrans sur les mêmes 711 références finissaient par se contredire.
 */
function ep_ca_catalogue(): array
{
    $p = caParams();
    $out = ['etat' => 'ok', 'params' => $p, 'lignes' => [], 'source' => '/production/catalogue'];
    $comm = (float) ($p['commissionMarquePct'] ?? 4.0);
    $fourn = [];
    try {
        foreach (Db::rows('SELECT id, name FROM ceo_supplier ORDER BY id') as $f) { $fourn[] = $f['name']; }
    } catch (PDOException $e) { /* référentiel fournisseurs absent */ }

    foreach (ep_prod_catalogue() as $l) {
        $m = caMarge($l['prix'], $l['mat'], $comm);
        $out['lignes'][] = [
            'ref' => $l['ref'], 'nom' => $l['nom'], 'categorie' => $l['categorie'],
            'groupe' => $l['groupe'] ?? '', 'fournisseur' => null,
            'prixAchat' => $l['mat'], 'prixVente' => $l['prix'],
            'commission' => $m['commission'], 'margeBrute' => $m['brute'],
            'margeNette' => $m['nette'], 'tauxMarge' => $m['taux'],
            'tvaPct' => (float) ($p['tvaDefautPct'] ?? 6.0),
            'fiable' => $l['matFiable'] ?? true];
    }
    $out['fournisseurs'] = $fourn;
    // Le fournisseur n'est rattaché à aucune référence : la table le porte pour
    // le réseau, pas pour l'article. Le dire vaut mieux qu'une colonne vide.
    $out['avertissement'] = $fourn
        ? 'Le rattachement référence → fournisseur n\'existe pas encore : la colonne reste vide tant qu\'une API ne le porte pas.'
        : 'Aucun fournisseur au référentiel.';
    return $out;
}

/** Cockpit du module : les indicateurs réseau que le panel sait rendre. */
function ep_ca_cockpit(): array
{
    $per = (string) ($_GET['periode'] ?? '30j');
    $jours = ['7j' => 7, '30j' => 30, 'trimestre' => 90, 'annee' => 365][$per] ?? 30;
    $au = date('Y-m-d');
    $du = date('Y-m-d', strtotime("-$jours day"));
    $out = ['periode' => $per, 'du' => $du, 'au' => $au, 'etat' => 'attente',
        'kpis' => [], 'motif' => null, 'source' => null];
    if (!PanelApi::configured()) { $out['motif'] = 'compte consultant non configuré'; return $out; }

    $r = PanelApi::shopsSalesKpisEntre($du, $au);
    $out['source'] = PanelApi::$lastPath;
    $liste = analyseListe($r);
    if (!$liste) { $out['motif'] = PanelApi::$lastError ?: 'l\'API n\'a rien rendu sur la période'; return $out; }

    $ca = 0.0; $tickets = 0.0; $n = 0;
    foreach ($liste as $sh) {
        $v = nombreOuNull($sh, ['ca', 'turnover', 'revenue']);
        if ($v !== null) { $ca += $v; $n++; }
        $t = nombreOuNull($sh, ['tickets', 'transactions', 'nb_tickets']);
        if ($t !== null) { $tickets += $t; }
    }
    $out['etat'] = 'ok';
    $out['kpis'] = [
        ['cle' => 'ca', 'libelle' => 'Chiffre d\'affaires réseau', 'valeur' => round($ca, 2), 'unite' => '€'],
        ['cle' => 'magasins', 'libelle' => 'Magasins mesurés', 'valeur' => $n, 'unite' => ''],
        ['cle' => 'tickets', 'libelle' => 'Tickets', 'valeur' => $tickets ?: null, 'unite' => ''],
        ['cle' => 'panier', 'libelle' => 'Ticket moyen', 'valeur' => $tickets > 0 ? round($ca / $tickets, 2) : null, 'unite' => '€'],
    ];
    // Les achats sont l'objet même de la centrale : tant qu'aucune API ne les
    // porte, l'annoncer plutôt que de laisser croire à un cockpit complet.
    $out['manquants'] = ['Volume d\'achat et marge centrale — aucune API de commandes fournisseurs',
        'Litiges et réception — aucune API de facturation fournisseurs'];
    return $out;
}

/** Ventes par magasin sur la période, base de la négociation. */
function ep_ca_ventes(): array
{
    $per = (string) ($_GET['periode'] ?? '30j');
    $jours = ['7j' => 7, '30j' => 30, 'trimestre' => 90, 'annee' => 365][$per] ?? 30;
    $au = date('Y-m-d');
    $du = date('Y-m-d', strtotime("-$jours day"));
    $out = ['periode' => $per, 'du' => $du, 'au' => $au, 'etat' => 'attente',
        'lignes' => [], 'motif' => null, 'source' => null];
    if (!PanelApi::configured()) { $out['motif'] = 'compte consultant non configuré'; return $out; }

    $noms = analyseNoms();
    $r = PanelApi::shopsSalesKpisEntre($du, $au);
    $out['source'] = PanelApi::$lastPath;
    foreach (analyseListe($r) as $sh) {
        $id = analyseShopId($sh);
        if ($id === '0') { continue; }
        $out['lignes'][] = ['id' => $id, 'magasin' => $noms[$id] ?? ('Magasin ' . $id),
            'ca' => nombreOuNull($sh, ['ca', 'turnover', 'revenue']),
            'tickets' => nombreOuNull($sh, ['tickets', 'transactions', 'nb_tickets'])];
    }
    if ($out['lignes']) { $out['etat'] = 'ok'; }
    else { $out['motif'] = PanelApi::$lastError ?: 'l\'API n\'a rien rendu sur la période'; }
    return $out;
}

/**
 * GET /centrale/fournisseurs/annee?annee=AAAA — un tableau par an : chaque
 * fournisseur, mois par mois, et son total.
 *
 * D'OÙ VIENNENT LES CHIFFRES, et pourquoi ce ne sont pas des factures : le
 * panel ne porte AUCUN montant sur les commandes (mesuré : GET
 * /deliveries/{id} sans montant, aucune route de liste). Ce qui porte une
 * valeur, ce sont les RÉQUISITIONS matière — la demande du magasin, chiffrée
 * en estimation. C'est donc de l'achat ESTIMÉ, jamais du facturé, et l'écran
 * doit le dire à côté du chiffre.
 *
 * L'ATTRIBUTION est stricte : une réquisition qui ne nomme qu'un fournisseur
 * lui revient entière ; une réquisition qui en nomme plusieurs ne se ventile
 * pas — la partager au prorata inventerait un chiffre. Elle va dans une ligne
 * « à ventiler », et le total de la colonne reste juste.
 */
function ep_ca_fournisseurs_annee(): array
{
    $an = (int) ($_GET['annee'] ?? date('Y'));
    if ($an < 2020 || $an > (int) date('Y') + 1) { $an = (int) date('Y'); }

    $d = ep_ca_commandes();
    if (($d['etat'] ?? '') !== 'ok') {
        return ['etat' => 'attente', 'annee' => $an, 'motif' => (string) ($d['source'] ?? 'commandes indisponibles')];
    }

    $vide = array_fill(1, 12, 0.0);
    $par = []; $aVentiler = $vide; $aVentilerN = 0;
    // Par MAGASIN, le chiffre est exact : la réquisition dit de quel magasin
    // elle vient, et ce qu'elle vaut. C'est la lecture qui tient debout quand
    // celle par fournisseur ne le peut pas.
    $parMag = [];
    $annees = [];
    $nReq = 0; $nSeul = 0;
    foreach ((array) ($d['lignes'] ?? []) as $l) {
        $debut = (string) ($l['debut'] ?? '');
        if (strlen($debut) < 7) { continue; }
        $anL = (int) substr($debut, 0, 4);
        $annees[$anL] = true;
        if ($anL !== $an) { continue; }
        $m = (int) substr($debut, 5, 2);
        if ($m < 1 || $m > 12) { continue; }
        $v = (float) ($l['valeur'] ?? 0);
        $fours = array_values(array_filter(array_map('trim', (array) ($l['fournisseurs'] ?? []))));
        $nReq++;
        $mag = (string) ($l['magasin'] ?? '—');
        if (!isset($parMag[$mag])) { $parMag[$mag] = ['mois' => $vide, 'n' => 0]; }
        $parMag[$mag]['mois'][$m] += $v;
        $parMag[$mag]['n']++;
        if (count($fours) === 1) {
            $nom = $fours[0];
            if (!isset($par[$nom])) { $par[$nom] = ['mois' => $vide, 'n' => 0]; }
            $par[$nom]['mois'][$m] += $v;
            $par[$nom]['n']++;
            $nSeul++;
        } else {
            $aVentiler[$m] += $v;
            $aVentilerN++;
        }
    }

    $lignes = [];
    foreach ($par as $nom => $x) {
        $total = array_sum($x['mois']);
        $lignes[] = ['fournisseur' => $nom, 'n' => $x['n'],
            'mois' => array_map(fn ($v) => round($v, 2), array_values($x['mois'])),
            'total' => round($total, 2)];
    }
    usort($lignes, fn ($a, $b) => $b['total'] <=> $a['total']);

    // Le total de chaque mois, ligne « à ventiler » comprise : c'est lui qui
    // doit correspondre à ce que le réseau a demandé, sans quoi le tableau
    // laisserait croire qu'une part a disparu.
    $totMois = $vide;
    foreach ($lignes as $l2) {
        foreach ($l2['mois'] as $i => $v) { $totMois[$i + 1] += $v; }
    }
    foreach ($aVentiler as $m => $v) { $totMois[$m] += $v; }

    $lignesMag = [];
    foreach ($parMag as $nom => $x) {
        $lignesMag[] = ['magasin' => $nom, 'n' => $x['n'],
            'mois' => array_map(fn ($v) => round($v, 2), array_values($x['mois'])),
            'total' => round(array_sum($x['mois']), 2)];
    }
    usort($lignesMag, fn ($a, $b) => $b['total'] <=> $a['total']);

    krsort($annees);
    return [
        'etat' => 'ok', 'annee' => $an,
        'annees' => array_values(array_map('intval', array_keys($annees))),
        'lignes' => $lignes,
        'aVentiler' => ['n' => $aVentilerN, 'mois' => array_map(fn ($v) => round($v, 2), array_values($aVentiler)),
            'total' => round(array_sum($aVentiler), 2)],
        'totaux' => ['mois' => array_map(fn ($v) => round($v, 2), array_values($totMois)),
            'total' => round(array_sum($totMois), 2)],
        'parMagasin' => $lignesMag,
        'nRequisitions' => $nReq, 'nAttribuees' => $nSeul,
        'source' => 'valeur ESTIMÉE des réquisitions matière (le panel ne porte aucun montant sur les commandes livrées)',
        // MESURÉ : l'ERP ne retient PAS le fournisseur d'une réquisition — le
        // champ `suppliers` est vide sur toutes, réalisées comprises. Les noms
        // affichés ailleurs viennent des fournisseurs du magasin, pas de la
        // commande. Il n'y a donc rien à ventiler par fournisseur, et il faut
        // le dire au lieu d'afficher un tableau vide qu'on croirait en panne.
        'ventilable' => $nSeul > 0,
        'manque' => $nSeul === 0 && $nReq > 0
            ? 'L’ERP n’enregistre pas le fournisseur d’une réquisition (champ `suppliers` vide sur toutes) : '
              . 'aucun montant ne peut lui être attribué. Le tableau par magasin, lui, est exact.'
            : ($aVentilerN > 0
                ? $aVentilerN . ' réquisition(s) nomment plusieurs fournisseurs : leur montant n’est pas ventilable, il figure à part'
                : ''),
    ];
}

/** Fournisseurs et réglages du moteur (RFA en lecture seule, cf. handoff). */
function ep_ca_reglages(): array
{
    $out = ['params' => caParams(), 'fournisseurs' => [], 'etat' => 'ok'];
    try {
        foreach (Db::rows('SELECT id, name, perimeter, email FROM ceo_supplier ORDER BY id') as $f) {
            $out['fournisseurs'][] = ['id' => (string) $f['id'], 'nom' => $f['name'],
                'perimetre' => $f['perimeter'], 'email' => $f['email'],
                // Le taux de RFA n'est porté par aucune source : la colonne
                // existe au handoff, la donnée non. Un zéro se lirait comme
                // « pas de remise » là où l'on ne sait simplement pas.
                'rfaPct' => null];
        }
    } catch (PDOException $e) { $out['etat'] = 'attente'; $out['motif'] = 'référentiel fournisseurs indisponible'; }
    $out['manquants'] = ['RFA fournisseurs — aucune API ne porte le taux',
        'Redevance centrale — idem'];
    return $out;
}

/** Demandes de prix enregistrées (table possédée par le BO). */
function ep_ca_demandes(): array
{
    try {
        $r = Db::rows('SELECT id, fournisseur, base, du, au, campagne, total_qte, total_cible, statut, cree_le
                       FROM ceo_ca_demande ORDER BY id DESC LIMIT 200');
    } catch (PDOException $e) { return ['etat' => 'attente', 'lignes' => [], 'motif' => 'table indisponible']; }
    return ['etat' => 'ok', 'lignes' => array_map(fn ($d) => [
        'id' => (string) $d['id'], 'fournisseur' => $d['fournisseur'], 'base' => $d['base'],
        'du' => $d['du'], 'au' => $d['au'], 'campagne' => $d['campagne'],
        'qte' => (int) $d['total_qte'], 'cible' => (float) $d['total_cible'],
        'statut' => $d['statut'], 'creeLe' => $d['cree_le']], $r)];
}

/**
 * Écrans sans source : la TABLE est rendue quand même, avec ses vraies
 * colonnes, et chacune dit le champ qu'elle attend et d'où il doit venir.
 *
 * Un bandeau « en attente d'API » en tête d'écran n'apprend rien à qui devra
 * brancher : il faut ouvrir le handoff pour savoir quoi chercher. En portant
 * le manque DANS le tableau, colonne par colonne, l'écran devient lui-même la
 * spécification du branchement — et l'on voit du même coup si une colonne est
 * déjà servie par une source existante.
 */
/**
 * GET /centrale/stock — l'inventaire matière RÉEL de chaque magasin.
 *
 * Source : /shops/{id}/material-inventory (API panel), un appel par boutique
 * en parallèle. L'alerte est un calcul local : stock courant sous le minimum
 * journalier. L'écran n'affiche que ce qui existe à l'inventaire — une
 * référence jamais comptée n'est pas « à zéro », elle est absente.
 */
function ep_ca_stock(): array
{
    if (!PanelApi::configured()) {
        return ['etat' => 'attente', 'titre' => 'Stock',
            'source' => 'API panel — compte consultant non configuré (Mon compte)', 'colonnes' => [], 'lignes' => []];
    }
    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id');
    } catch (PDOException $e) { $shops = []; }
    $noms = []; $chemins = [];
    foreach ($shops as $s) {
        $noms[(int) $s['id']] = (string) $s['name'];
        $chemins[(int) $s['id']] = '/shops/' . (int) $s['id'] . '/material-inventory';
    }
    $lignes = [];
    foreach (PanelApi::getParallele($chemins) as $sid => $inv) {
        foreach (analyseListe(is_array($inv) ? $inv : []) as $m) {
            if ((int) ($m['exist_in_inventory'] ?? 0) !== 1) { continue; }
            $stock = (float) ($m['current_quantity'] ?? 0);
            $mini  = (float) ($m['minimum_quantity_per_day'] ?? 0);
            $lignes[] = [
                'magasin' => $noms[$sid] ?? ('Magasin ' . $sid),
                'ref' => trim((string) ($m['material_name'] ?? '')),
                'categorie' => (string) ($m['category_name'] ?? ''),
                'stock' => $stock, 'mini' => $mini,
                'unite' => (string) ($m['unit_name'] ?? ''),
                'modif' => substr((string) ($m['last_modified'] ?? ''), 0, 10),
                // En alerte : stock NÉGATIF (écart de caisse ou comptage) ou
                // sous le minimum journalier — les deux appellent un geste.
                'alerte' => $stock < 0 || ($mini > 0 && $stock < $mini),
            ];
        }
    }
    usort($lignes, fn ($a, $b) => [$b['alerte'], $a['magasin'], $a['categorie'], $a['ref']]
        <=> [$a['alerte'], $b['magasin'], $b['categorie'], $b['ref']]);
    $tronque = max(0, count($lignes) - 600);
    return ['etat' => 'ok', 'titre' => 'Stock',
        'source' => 'API panel — inventaire matière par magasin (/shops/{id}/material-inventory)',
        'lignes' => array_slice($lignes, 0, 600), 'tronque' => $tronque,
        'manquants' => [lacune('Rotation & délai de réappro',
            'la rotation attendue et le délai de réapprovisionnement par référence',
            'API panel — l’inventaire ne porte que le stock courant et le minimum journalier')]];
}

/**
 * GET /centrale/commandes — les réquisitions matière des magasins.
 *
 * Source : /shops/{id}/material-requisitions (API panel) : période, prévision
 * de ventes, statut et valeur estimée. C'est le vrai flux de commande des
 * franchisés vers la centrale — PENDING attend une conversion en commande.
 */
function ep_ca_commandes(): array
{
    if (!PanelApi::configured()) {
        return ['etat' => 'attente', 'titre' => 'Commandes franchisés',
            'source' => 'API panel — compte consultant non configuré (Mon compte)', 'colonnes' => [], 'lignes' => []];
    }
    try {
        $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id');
    } catch (PDOException $e) { $shops = []; }
    $noms = []; $chemins = [];
    foreach ($shops as $s) {
        $noms[(int) $s['id']] = (string) $s['name'];
        $chemins[(int) $s['id']] = '/shops/' . (int) $s['id'] . '/material-requisitions';
    }
    // Le fournisseur d'une réquisition n'est enregistré NULLE PART par l'ERP
    // (mesuré : `suppliers` vide sur toutes, réalisées comprises, réalm admin
    // inclus). On le DÉRIVE pour les réquisitions EN ATTENTE : les besoins
    // actuels du magasin (/list) croisés avec le mapping matière → fournisseur
    // (catalog-mappings). Une réquisition réalisée garde « — » : son contenu
    // n'a pas été retenu par l'ERP, inventer un nom serait pire.
    $fournParMatiere = [];
    $nomsFourn = []; $mailsFourn = [];
    foreach (analyseListe(PanelApi::get('/material-suppliers') ?? []) as $f) {
        $fid = (int) ($f['id'] ?? 0);
        if ($fid > 0) {
            $nomsFourn[$fid] = (string) ($f['name'] ?? ('Fournisseur ' . $fid));
            $mailsFourn[$fid] = trim((string) ($f['email'] ?? ''));
        }
    }
    // Les relances déjà envoyées : la ligne le dit, pour ne pas relancer deux fois.
    $relances = setting('caRelances');
    if (!is_array($relances)) { $relances = []; }
    $chMap = [];
    foreach ($nomsFourn as $fid => $n2) { $chMap[$fid] = '/material-suppliers/' . $fid . '/catalog-mappings'; }
    foreach (PanelApi::getParallele($chMap) as $fid => $maps) {
        foreach (analyseListe(is_array($maps) ? $maps : []) as $m2) {
            $mid = (int) ($m2['material_id'] ?? 0);
            if ($mid > 0) { $fournParMatiere[$mid] = $nomsFourn[$fid]; }
        }
    }
    // UNE COMMANDE = UN FOURNISSEUR (règle du réseau). Les besoins courants de
    // chaque magasin se ventilent donc par fournisseur : autant de commandes à
    // passer que de fournisseurs concernés, chacune avec son nombre de
    // références et son montant estimé (qty_to_order × prix unitaire net).
    $chBesoins = [];
    foreach (array_keys($noms) as $sid2) { $chBesoins[$sid2] = '/shops/' . $sid2 . '/material-requisitions/list'; }
    $fournParShop = []; $aCommander = [];
    foreach (PanelApi::getParallele($chBesoins) as $sid2 => $besoins) {
        $parFourn = [];
        foreach (analyseListe(is_array($besoins) ? $besoins : []) as $b2) {
            $q2 = (float) ($b2['qty_to_order'] ?? 0);
            if ($q2 <= 0) { continue; }
            $n2 = $fournParMatiere[(int) ($b2['id_material'] ?? 0)] ?? null;
            if ($n2 === null) { continue; }
            $parFourn[$n2]['refs'] = ($parFourn[$n2]['refs'] ?? 0) + 1;
            $parFourn[$n2]['montant'] = ($parFourn[$n2]['montant'] ?? 0)
                + $q2 * (float) ($b2['base_unit_price_net'] ?? 0);
        }
        $fournParShop[$sid2] = array_keys($parFourn);
        foreach ($parFourn as $n2 => $x2) {
            $aCommander[] = ['magasin' => $noms[$sid2] ?? ('Magasin ' . $sid2),
                'fournisseur' => $n2, 'nbRefs' => (int) $x2['refs'],
                'montant' => round($x2['montant'], 2)];
        }
    }
    usort($aCommander, fn ($a, $b) => [$a['magasin'], -$b['montant']] <=> [$b['magasin'], -$a['montant']]);

    $lignes = []; $avecFournisseur = 0;
    foreach (PanelApi::getParallele($chemins) as $sid => $reqs) {
        foreach (analyseListe(is_array($reqs) ? $reqs : []) as $r) {
            $fours = [];
            foreach ((array) ($r['suppliers'] ?? []) as $f) {
                if (is_string($f) && $f !== '') { $fours[] = $f; continue; }
                if (is_array($f)) {
                    foreach (['name', 'display_name', 'label'] as $c2) {
                        if (!empty($f[$c2]) && is_string($f[$c2])) { $fours[] = trim($f[$c2]); break; }
                    }
                }
            }
            if ($fours === [] && (string) ($r['status'] ?? '') === 'PENDING') {
                $fours = $fournParShop[$sid] ?? [];
            }
            if ($fours !== []) { $avecFournisseur++; }
            $lignes[] = [
                'id' => (int) ($r['id'] ?? 0),
                // L'identifiant du magasin voyage avec la ligne : sans lui, on
                // ne peut pas notifier le franchisé qui doit valider.
                'magasinId' => (int) $sid,
                'magasin' => $noms[$sid] ?? ('Magasin ' . $sid),
                'fournisseurs' => $fours,
                'debut' => (string) ($r['beginning_of_period'] ?? ''),
                'jours' => (int) ($r['requisition_period_days'] ?? 0),
                'type' => (string) ($r['type_of_requisition'] ?? ''),
                'statut' => (string) ($r['status'] ?? ''),
                'valeur' => (float) ($r['estimated_value'] ?? 0),
                'par' => (string) ($r['employee']['display_name'] ?? ''),
            ];
        }
    }
    usort($lignes, fn ($a, $b) => $b['debut'] <=> $a['debut']);
    $manquants = [lacune('Lignes de la commande',
        'le détail produit par produit d’une réquisition',
        'API panel — /material-requisitions/{id} et /document existent, à câbler sur un clic de ligne')];
    $manquants[] = lacune('Fournisseur des réquisitions réalisées',
        'quel fournisseur a servi une commande passée',
        'API panel — l’ERP n’enregistre le fournisseur sur aucune réquisition (mesuré, réalm admin compris). '
        . 'Pour les EN ATTENTE, il est dérivé des besoins actuels croisés au mapping matière → fournisseur ; '
        . 'l’historique, lui, est perdu côté ERP');
    // Regroupement par fournisseur : l'écran montre chaque fournisseur avec ses
    // 5 dernières commandes ($lignes est déjà trié par début décroissant).
    // Plusieurs fournisseurs → « À répartir » ; aucun → « Sans fournisseur ».
    $groupes = [];
    foreach ($lignes as $l) {
        $fours = (array) $l['fournisseurs'];
        $cle = count($fours) === 1 ? (string) $fours[0] : (count($fours) > 1 ? 'À répartir' : 'Sans fournisseur');
        if (!isset($groupes[$cle])) {
            $groupes[$cle] = ['fournisseur' => $cle, 'enAttente' => 0, 'valeurAttente' => 0.0,
                'total' => 0, 'commandes' => []];
        }
        $groupes[$cle]['total']++;
        if ((string) $l['statut'] === 'PENDING') {
            $groupes[$cle]['enAttente']++;
            $groupes[$cle]['valeurAttente'] += (float) $l['valeur'];
        }
        if (count($groupes[$cle]['commandes']) < 5) { $groupes[$cle]['commandes'][] = $l; }
    }
    uksort($groupes, function ($a, $b) {
        $rang = fn ($k) => $k === 'À répartir' ? 1 : ($k === 'Sans fournisseur' ? 2 : 0);
        return [$rang($a), $a] <=> [$rang($b), $b];
    });

    return ['etat' => 'ok', 'titre' => 'Commandes franchisés',
        'source' => 'API panel — réquisitions matière (/shops/{id}/material-requisitions) et besoins courants (/list × catalog-mappings)',
        // Ce qui a déjà été rappelé au franchisé : la ligne dit « relancé le … »
        // au lieu de laisser notifier le même magasin dix fois.
        'relancesFranchise' => (function () { $v = setting('caRelancesFranchise');
            return is_array($v) ? $v : []; })(),
        'lignes' => $lignes, 'parFournisseur' => array_values($groupes),
        'aCommander' => $aCommander, 'manquants' => $manquants];
}

/**
 * GET /centrale/achats — le suivi fournisseurs : référentiel réel du panel.
 *
 * Source : /material-suppliers, complété du volume de catalogue par
 * fournisseur. Les commandes fournisseurs, réceptions et litiges ne sont pas
 * exposés en lecture (seuls des webhooks entrants existent) : annoncé.
 */
function ep_ca_achats(): array
{
    if (!PanelApi::configured()) {
        return ['etat' => 'attente', 'titre' => 'Suivi fournisseurs',
            'source' => 'API panel — compte consultant non configuré (Mon compte)', 'colonnes' => [], 'lignes' => []];
    }
    $fournisseurs = analyseListe(PanelApi::get('/material-suppliers') ?? []);
    $chemins = [];
    foreach ($fournisseurs as $f) {
        $id = (int) ($f['id'] ?? 0);
        if ($id > 0) { $chemins[$id] = '/material-suppliers/' . $id . '/catalog/products'; }
    }
    $catalogues = PanelApi::getParallele($chemins);
    // Les pourcentages sont un RÉGLAGE du cockpit : le panel ne porte ni la
    // marge centrale → franchisé, ni la redevance fournisseur → centrale.
    // Saisis à l'écran (clic sur la cellule), gardés dans ceo_app_setting.
    $pct = setting('caFournPct', []);
    if (!is_array($pct)) { $pct = []; }
    $lignes = [];
    foreach ($fournisseurs as $f) {
        $id = (int) ($f['id'] ?? 0);
        if ($id <= 0) { continue; }
        $cat = analyseListe(is_array($catalogues[$id] ?? null) ? $catalogues[$id] : []);
        $actives = 0;
        foreach ($cat as $p) { if ((int) ($p['is_active'] ?? 0) === 1) { $actives++; } }
        $p2 = is_array($pct[(string) $id] ?? null) ? $pct[(string) $id] : [];
        $lignes[] = [
            'id' => $id, 'nom' => (string) ($f['name'] ?? ''),
            'ville' => (string) ($f['city'] ?? ''),
            'telephone' => (string) ($f['phone'] ?? ''),
            'email' => (string) ($f['email'] ?? ''),
            'devise' => (string) ($f['currency'] ?? ''),
            // CENTRAL = la centrale d'achat elle-même ; le drapeau « intégré »
            // dit si la commande part électroniquement chez ce fournisseur.
            'type' => (string) ($f['type'] ?? ''),
            'integre' => (int) ($f['integrated_supplier'] ?? 0) === 1,
            'nbRefs' => count($cat), 'nbActives' => $actives,
            'margePct' => isset($p2['marge']) ? (float) $p2['marge'] : null,
            'redevancePct' => isset($p2['redevance']) ? (float) $p2['redevance'] : null,
        ];
    }

    // CA du réseau, mois par mois avec le cumul de l'année : la même route de
    // ventes fraîches, posée une fois par mois écoulé (en parallèle). C'est le
    // chiffre d'affaires MAGASINS — l'assiette des redevances et le volume que
    // la centrale sert.
    $caMensuel = []; $moisN = (int) date('n'); $annee = (int) date('Y');
    $cheminsCa = [];
    for ($mm = 1; $mm <= $moisN; $mm++) {
        $du = sprintf('%04d-%02d-01', $annee, $mm);
        $au = $mm === $moisN ? date('Y-m-d') : date('Y-m-t', strtotime($du));
        $cheminsCa[$mm] = '/consultant/shops/sales-kpis?' . http_build_query(['date_from' => $du, 'date_to' => $au]);
    }
    $parMois = PanelApi::getParallele($cheminsCa);
    $cumul = 0.0;
    for ($mm = 1; $mm <= $moisN; $mm++) {
        $tot = 0.0; $ok = false;
        foreach (analyseListe(is_array($parMois[$mm] ?? null) ? $parMois[$mm] : []) as $x) {
            $v = nombreOuNull($x, ['ca', 'turnover', 'revenue']);
            if ($v !== null) { $tot += $v; $ok = true; }
        }
        $cumul += $tot;
        $caMensuel[] = ['mois' => $mm, 'annee' => $annee, 'ca' => $ok ? round($tot, 2) : null,
            'cumul' => round($cumul, 2), 'enCours' => $mm === $moisN];
    }

    // Le suivi des commandes : 2 dernières par magasin chez chaque fournisseur.
    try { $noms = []; foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY sort_order, id') as $s2) {
        $noms[(int) $s2['id']] = (string) $s2['name']; } } catch (PDOException $e) { $noms = []; }
    $suivi = caSuiviCommandes($noms);

    $manquants = [];
    if (!$suivi['groupes']) {
        $manquants[] = lacune('Commandes fournisseurs',
            'les 2 dernières commandes de chaque magasin et leur état',
            'API panel — aucune route de LISTE n’est lisible (404 ORDER_NOT_FOUND en consultant et en admin) ; le suivi balaie GET /deliveries/{id}, qui n’a rien rendu sur la plage récente');
    }
    $manquants[] = lacune('Valeur des commandes',
        'le montant de chaque commande fournisseur',
        'API panel — GET /deliveries/{id} ne porte aucun montant ; à réclamer, ou une route de liste des commandes');
    $manquants[] = lacune('Réception et litiges',
        'quantités reçues face aux quantités commandées, et l’état des factures',
        'API panel — la réception vit sur POST /orders/{id}/delivery ; aucune route de lecture ne la restitue');

    return ['etat' => 'ok', 'titre' => 'Suivi fournisseurs',
        'source' => 'API panel — commandes lues une par une (/deliveries/{id}, seule route accessible), référentiel fournisseurs (/material-suppliers), ventes du réseau (sales-kpis)',
        'lignes' => $lignes, 'caMensuel' => $caMensuel, 'exercice' => $annee,
        'suivi' => $suivi, 'manquants' => $manquants];
}

/**
 * Les 2 dernières commandes de CHAQUE magasin, chez CHAQUE fournisseur.
 *
 * MESURÉ sur le serveur : aucune route de LISTE de commandes n'est lisible en
 * réalm consultant ni admin — /shops/{id}/orders(/materials), /orders et
 * /material-orders répondent 404 ORDER_NOT_FOUND. En revanche
 * GET /deliveries/{idCommande} rend la commande ENTIÈRE, quel que soit son
 * magasin et son fournisseur : identifiant, clé, dates, statut logistique,
 * statut de traitement fournisseur et horodatages des gestes.
 *
 * Le suivi balaie donc la plage des identifiants récents (en parallèle) et
 * garde ce qui répond. Le balayage coûte cher : son résultat est mémorisé
 * dans `ceo_app_setting.caSuiviCache` et rejoué pendant SUIVI_TTL, sauf
 * ?refresh=1. La borne haute connue vit dans `caSuiviMaxId` et monte toute
 * seule quand de nouvelles commandes apparaissent.
 */
const SUIVI_TTL = 300;          // 5 min — assez court pour voir un statut changer
const SUIVI_PROFONDEUR = 260;   // identifiants balayés vers le bas
const SUIVI_AVANCE = 40;        // identifiants sondés au-delà du max connu

function caSuiviCommandes(array $nomsMagasins): array
{
    $frais = empty($_GET['refresh']);
    if ($frais) {
        $cache = setting('caSuiviCache');
        if (is_array($cache) && (int) ($cache['quand'] ?? 0) > time() - SUIVI_TTL) {
            $cache['cache'] = true;
            return $cache;
        }
    }

    // 1. La borne haute : on sonde au-delà du dernier identifiant connu.
    $max = (int) (setting('caSuiviMaxId') ?? 0);
    if ($max <= 0) { $max = 300; }
    $sonde = [];
    for ($i = $max + 1; $i <= $max + SUIVI_AVANCE; $i++) { $sonde[$i] = '/deliveries/' . $i; }
    foreach (PanelApi::getParallele($sonde, 8) as $id => $r) {
        if (is_array($r) && !empty($r['id'])) { $max = max($max, (int) $id); }
    }

    // 2. Le balayage descendant.
    $chemins = [];
    for ($i = $max; $i > max(0, $max - SUIVI_PROFONDEUR); $i--) { $chemins[$i] = '/deliveries/' . $i; }
    $brutes = PanelApi::getParallele($chemins, 8);
    $cmds = [];
    foreach ($brutes as $id => $r) {
        if (is_array($r) && !empty($r['id'])) { $cmds[] = $r; }
    }
    if (!$cmds) {
        return ['groupes' => [], 'kpis' => null, 'quand' => time(),
            'indispo' => 'aucune commande lisible — GET /deliveries/{id} n’a rien rendu sur la plage balayée'];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caSuiviMaxId', json_encode($max)]);

    // 3. Les noms : fournisseurs du référentiel, magasins de la base.
    $nomsFourn = [];
    foreach (analyseListe(PanelApi::get('/material-suppliers') ?? []) as $f) {
        $fid = (int) ($f['id'] ?? 0);
        if ($fid > 0) { $nomsFourn[$fid] = (string) ($f['name'] ?? ('Fournisseur ' . $fid)); }
    }

    $vide = static fn ($v): bool => $v === null || $v === '' || $v === 'NULL';
    $aujourdHui = date('Y-m-d');

    // Les plus récentes d'abord.
    usort($cmds, static fn ($a, $b) => [(string) ($b['order_date'] ?? ''), (int) ($b['id'] ?? 0)]
        <=> [(string) ($a['order_date'] ?? ''), (int) ($a['id'] ?? 0)]);

    $parFourn = []; $pris = []; $aValider = [];
    foreach ($cmds as $o) {
        $fid = (int) ($o['id_supplier'] ?? 0);
        $sid = (int) ($o['id_shop'] ?? 0);
        $fourn = $nomsFourn[$fid] ?? ($fid > 0 ? 'Fournisseur ' . $fid : 'Sans fournisseur');
        $magasin = $nomsMagasins[$sid] ?? ('Magasin ' . $sid);
        $cle = $fourn . '|' . $magasin;
        if (($pris[$cle] ?? 0) >= 2) { continue; }   // 2 par magasin ET par fournisseur
        $pris[$cle] = ($pris[$cle] ?? 0) + 1;

        $ff = strtoupper((string) ($o['supplier_fulfillment_status'] ?? ''));
        $st = strtoupper((string) ($o['status'] ?? ''));
        $prevue = !$vide($o['supplier_planned_delivery_date'] ?? null) ? (string) $o['supplier_planned_delivery_date']
            : (!$vide($o['expected_date'] ?? null) ? (string) $o['expected_date'] : '');
        $livre = !$vide($o['delivered_on'] ?? null) ? substr((string) $o['delivered_on'], 0, 10) : '';

        $etape = 1; $bloque = false; $libelle = 'Envoyée'; $geste = '';
        if (!$vide($o['supplier_rejected_at'] ?? null) || $ff === 'REJECTED') {
            $bloque = true; $libelle = 'Refusée'; $geste = 'refusée le ' . substr((string) ($o['supplier_rejected_at'] ?? ''), 0, 10);
        } elseif (!$vide($o['supplier_cancelled_at'] ?? null) || $ff === 'CANCELLED') {
            $bloque = true; $libelle = 'Annulée'; $geste = 'annulée le ' . substr((string) ($o['supplier_cancelled_at'] ?? ''), 0, 10);
        } elseif ($livre !== '' || $st === 'DELIVERED') {
            $etape = 4; $libelle = 'Livrée'; $geste = $livre !== '' ? 'livrée le ' . $livre : '';
        } elseif (!$vide($o['in_transit_sent_at'] ?? null) || $st === 'IN_TRANSIT') {
            $etape = 3; $libelle = 'En transit'; $geste = 'expédiée le ' . substr((string) ($o['in_transit_sent_at'] ?? ''), 0, 10);
        } elseif ($ff === 'FINALIZED' || !$vide($o['supplier_finalized_at'] ?? null)) {
            $etape = 3; $libelle = 'Finalisée'; $geste = 'finalisée le ' . substr((string) ($o['supplier_finalized_at'] ?? ''), 0, 10);
        } elseif ($ff === 'ACCEPTED' || !$vide($o['supplier_accepted_at'] ?? null)) {
            $etape = 2; $libelle = 'Acceptée'; $geste = 'acceptée le ' . substr((string) ($o['supplier_accepted_at'] ?? ''), 0, 10);
        } elseif ($ff !== '' && $ff !== 'NEW') {
            $libelle = ucfirst(strtolower(str_replace('_', ' ', $ff)));
        }

        $retard = null;
        if ($etape < 4 && !$bloque && $prevue !== '' && $prevue < $aujourdHui) {
            $retard = (int) floor((strtotime($aujourdHui) - strtotime($prevue)) / 86400);
        }

        // Ce que le fournisseur n'a PAS encore accepté — sans la borne des deux
        // par magasin, qui ne vaut que pour l'affichage : un rappel doit citer
        // TOUTES les commandes qui attendent, pas les deux dernières.
        if ($etape === 1 && !$bloque) {
            $aValider[] = ['id' => (int) $o['id'],
                'cle' => (string) ($o['order_key'] ?? ('#' . $o['id'])),
                'fournisseur' => $fourn, 'magasin' => $magasin,
                'date' => substr((string) ($o['order_date'] ?? ''), 0, 10),
                'livraisonPrevue' => $prevue, 'retardJours' => $retard];
        }

        $parFourn[$fourn]['magasins'][$magasin][] = [
            'id' => (int) $o['id'],
            'cle' => (string) ($o['order_key'] ?? ('#' . $o['id'])),
            'date' => substr((string) ($o['order_date'] ?? ''), 0, 10),
            'livraisonPrevue' => $prevue,
            'etape' => $etape, 'bloque' => $bloque, 'libelle' => $libelle,
            'geste' => $geste, 'retardJours' => $retard,
            'source' => strtoupper((string) ($o['source_type'] ?? '')) === 'INTEGRATED' ? 'intégrée' : 'manuelle',
            'email' => $mailsFourn[$fid] ?? '',
            'relanceLe' => (string) ($relances[(string) $o['id']]['quand'] ?? ''),
        ];
    }

    // 4. Mise en forme + compteurs.
    $groupes = []; $kEnCours = 0; $kRetard = 0; $kNouv = 0; $kTotal = 0;
    foreach ($parFourn as $nom => $g) {
        ksort($g['magasins']);
        $lignes = []; $enCours = 0; $retard = 0; $nouv = 0;
        foreach ($g['magasins'] as $magasin => $liste) {
            foreach ($liste as $i => $o) {
                $o['magasin'] = $i === 0 ? $magasin : '';
                $lignes[] = $o; $kTotal++;
                if ($o['etape'] < 4 && !$o['bloque']) {
                    $enCours++; $kEnCours++;
                    if ($o['etape'] === 1) { $nouv++; $kNouv++; }
                }
                if ($o['retardJours'] !== null) { $retard++; $kRetard++; }
            }
        }
        $groupes[] = ['fournisseur' => $nom, 'nbMagasins' => count($g['magasins']),
            'nbCommandes' => count($lignes), 'enCours' => $enCours, 'retard' => $retard,
            'sansReponse' => $nouv, 'commandes' => $lignes];
    }
    usort($groupes, static fn ($a, $b) => [-$a['retard'], -$a['enCours'], $a['fournisseur']]
        <=> [-$b['retard'], -$b['enCours'], $b['fournisseur']]);

    // Les commandes que le fournisseur n'a pas acceptées, toutes, triées de la
    // plus ancienne à la plus récente : c'est la matière du rappel.
    usort($aValider, fn ($a, $b) => [(string) $a['date'], $a['id']] <=> [(string) $b['date'], $b['id']]);
    $out = ['groupes' => $groupes, 'aValider' => $aValider,
        'lues' => count($cmds), 'maxId' => $max, 'quand' => time(),
        'kpis' => ['enCours' => $kEnCours, 'retard' => $kRetard, 'aAccepter' => $kNouv,
            'fournisseurs' => count($groupes), 'total' => $kTotal]];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['caSuiviCache', json_encode($out, JSON_UNESCAPED_UNICODE)]);
    return $out;
}

/**
 * GET /centrale/achats/catalogue?fournisseur=N — le catalogue d'UN
 * fournisseur, ouvert d'un clic sur son nom dans le suivi.
 */
function ep_ca_achats_catalogue(): array
{
    $id = (int) ($_GET['fournisseur'] ?? 0);
    if ($id <= 0) { http_response_code(400); return ['error' => 'fournisseur requis']; }
    if (!PanelApi::configured()) { return ['etat' => 'attente', 'lignes' => []]; }

    // La fiche d'identité (/material-suppliers/{id}) porte ce que la liste
    // tait : type (CENTRAL = la centrale elle-même), intégration électronique,
    // adresse, TVA, site. Affichée en tête du catalogue — sans l'IBAN, qui n'a
    // rien à faire à l'écran.
    $fiche = null;
    $f = PanelApi::get('/material-suppliers/' . $id);
    if (is_array($f)) {
        if (isset($f[0]) && is_array($f[0])) { $f = $f[0]; }
        $adresse = trim(implode(' ', array_filter([
            $f['street'] ?? '', $f['street_number'] ?? ''])));
        $villeLigne = trim(implode(' ', array_filter([$f['zip'] ?? '', $f['city'] ?? ''])));
        $fiche = [
            'nom' => (string) ($f['name'] ?? ''),
            'type' => (string) ($f['type'] ?? ''),
            'integre' => (int) ($f['integrated_supplier'] ?? 0) === 1,
            'adresse' => trim($adresse . ($villeLigne !== '' ? ', ' . $villeLigne : ''), ', '),
            'pays' => (string) ($f['country_code'] ?? ''),
            'telephone' => (string) ($f['phone'] ?? ''),
            'email' => (string) ($f['email'] ?? ''),
            'tva' => (string) ($f['tax_number'] ?? ''),
            'web' => (string) ($f['website_url'] ?? ''),
            'notes' => (string) ($f['notes'] ?? ''),
        ];
    }
    $lignes = [];
    foreach (analyseListe(PanelApi::get('/material-suppliers/' . $id . '/catalog/products') ?? []) as $p) {
        $lignes[] = [
            'sku' => (string) ($p['sku'] ?? ''),
            'nom' => trim((string) ($p['name'] ?? '')),
            'colis' => trim(((string) ($p['package_size'] ?? '')) . ' ' . ((string) ($p['package_unit'] ?? ''))),
            'portion' => trim(((string) ($p['portion_size'] ?? '')) . ' ' . ((string) ($p['portion_unit'] ?? ''))),
            'poidsG' => isset($p['weight_grams']) ? (int) $p['weight_grams'] : null,
            'dlcJours' => isset($p['shelf_life_days']) ? (int) $p['shelf_life_days'] : null,
            'tvaPct' => isset($p['vat_rate']) ? (float) $p['vat_rate'] : null,
            'actif' => (int) ($p['is_active'] ?? 0) === 1,
        ];
    }
    usort($lignes, fn ($a, $b) => [$b['actif'], $a['nom']] <=> [$a['actif'], $b['nom']]);
    return ['etat' => 'ok', 'fournisseurId' => $id, 'fiche' => $fiche, 'lignes' => $lignes];
}

/**
 * GET /centrale/facturation — ce que les magasins doivent et paient.
 *
 * Deux flux, tous deux du réalm ADMIN de l'ERP (compte « admin ERP » des
 * Paramètres) :
 *   · les factures de REDEVANCES émises aux magasins (/admin/royalties/
 *     invoices) — numéro, échéance, montant, état de paiement ;
 *   · les factures d'ABONNEMENT TFBuddy par magasin (/admin/billing/invoices,
 *     Stripe) — le coût logiciel du réseau.
 */
function ep_ca_facturation(): array
{
    if (!ErpApi::disponible()) {
        $m = ep_ca_manquant('facturation');
        $m['source'] = 'Réalm admin de l’ERP — renseignez le compte « admin ERP » (Mon compte) pour lire '
            . 'les factures de redevances et les abonnements TFBuddy.';
        return $m;
    }
    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops') as $s) { $noms[(int) $s['id']] = (string) $s['name']; }
    } catch (PDOException $e) { /* noms indisponibles */ }

    $redevances = [];
    $rep = ErpApi::get('/admin/royalties/invoices');
    $liste = is_array($rep) ? ($rep['invoices'] ?? (analyseListe($rep) ?: [])) : [];
    foreach ((array) $liste as $f) {
        $redevances[] = [
            'numero' => (string) ($f['invoice_number'] ?? ('#' . ($f['id'] ?? '?'))),
            'magasin' => $noms[(int) ($f['id_shop'] ?? 0)] ?? ('Magasin ' . ($f['id_shop'] ?? '?')),
            'emise' => (string) ($f['issue_date'] ?? ''),
            'echeance' => (string) ($f['due_date'] ?? ''),
            'montant' => (float) ($f['gross_amount'] ?? 0),
            'statut' => (string) ($f['status'] ?? ''),
            'paiement' => (string) ($f['payment_status'] ?? ''),
            'payeLe' => $f['paid_at'] ?? null,
            'relanceLe' => $f['last_reminder_sent_at'] ?? null,
        ];
    }
    usort($redevances, fn ($a, $b) => $b['emise'] <=> $a['emise']);

    $abonnements = [];
    foreach (analyseListe(ErpApi::get('/admin/billing/invoices') ?? []) as $f) {
        $abonnements[] = [
            'magasin' => (string) ($f['store_name'] ?? ''),
            'offre' => (string) ($f['package_code'] ?? ''),
            'montant' => (float) ($f['amount_due'] ?? 0),
            'paye' => (float) ($f['amount_paid'] ?? 0),
            'statut' => (string) ($f['status'] ?? ''),
            'pdf' => (string) ($f['invoice_pdf_url'] ?? ($f['invoice_pdf'] ?? '')),
        ];
    }

    return ['etat' => 'ok', 'titre' => 'Facturation magasins',
        'source' => 'Réalm admin ERP — factures de redevances (/admin/royalties/invoices) et abonnements TFBuddy (/admin/billing/invoices)',
        'redevances' => $redevances, 'abonnements' => array_slice($abonnements, 0, 60),
        'manquants' => [lacune('Factures clients B2B des magasins',
            'les factures qu’un magasin émet à ses propres clients professionnels',
            '/shops/{id}/invoices exige une date jour par jour — à câbler avec un sélecteur si le besoin se confirme')]];
}

function ep_ca_manquant(string $ecran): array
{
    $def = [
        'campagnes' => [
            'titre'  => 'Campagnes commerciales',
            'source' => 'API cockpit marketing — lecture seule, ne jamais écrire depuis la centrale',
            'colonnes' => [
                ['col' => 'Campagne',            'champ' => 'campagnes.nom + statut',            'src' => 'API marketing'],
                ['col' => 'Période',             'champ' => 'campagnes.periode_debut / _fin',    'src' => 'API marketing'],
                ['col' => 'Assortiment',         'champ' => 'campagnes.assortiment_id → assortiments.produits[]', 'src' => 'référentiel assortiments'],
                ['col' => 'Objectif volume',     'champ' => 'campagnes.objectifs_volume{magasin}', 'src' => 'API marketing'],
                ['col' => 'Prix cible',          'champ' => 'campagnes.prix_cible_baisse_pct',   'src' => 'API marketing'],
                ['col' => 'Remise négociée',     'champ' => 'campagnes.remise_negociee_pct',     'src' => 'API marketing'],
                ['col' => 'Reçu / commandé',     'champ' => 'lignes_commande_fournisseur.quantite_recue / _commandee', 'src' => 'API achats'],
                ['col' => 'Hors prix négocié',   'champ' => '|prix_unitaire − produit.prix_achat| > 0,001', 'src' => 'API achats'],
                ['col' => 'Litige',              'champ' => 'factures_fournisseur.statut = litige', 'src' => 'API achats'],
            ]],
        'achats' => [
            'titre'  => 'Suivi fournisseurs',
            'source' => 'API achats — commandes fournisseurs, réception, litiges',
            'colonnes' => [
                ['col' => 'Commande',      'champ' => 'commandes_fournisseur.id + date',       'src' => 'API achats'],
                ['col' => 'Fournisseur',   'champ' => 'commandes_fournisseur.fournisseur_id',  'src' => 'API achats',
                 'note' => 'le référentiel fournisseurs existe déjà côté cockpit'],
                ['col' => 'Statut',        'champ' => 'envoyée | partiellement reçue | reçue', 'src' => 'API achats'],
                ['col' => 'Commandé',      'champ' => 'Σ lignes.quantite_commandee',           'src' => 'API achats'],
                ['col' => 'Reçu',          'champ' => 'Σ lignes.quantite_recue',               'src' => 'API achats'],
                ['col' => 'Écart',         'champ' => 'reçu − commandé (rouge si négatif)',    'src' => 'calcul local'],
                ['col' => 'Montant',       'champ' => 'commandes_fournisseur.total',           'src' => 'API achats'],
                ['col' => 'Facture',       'champ' => 'factures_fournisseur.statut',           'src' => 'API achats'],
            ]],
        'commandes' => [
            'titre'  => 'Commandes franchisés',
            'source' => 'API commandes franchisés — lecture + transition de statut',
            'colonnes' => [
                ['col' => 'Commande',   'champ' => 'commandes.id + date',                    'src' => 'API commandes'],
                ['col' => 'Magasin',    'champ' => 'commandes.magasin_id',                   'src' => 'API commandes',
                 'note' => 'les magasins sont déjà connus du cockpit'],
                ['col' => 'Statut',     'champ' => 'nouvelle | préparée | expédiée | livrée', 'src' => 'API commandes'],
                ['col' => 'Lignes',     'champ' => 'lignes_commande.produit_id, quantite, prix_unitaire', 'src' => 'API commandes'],
                ['col' => 'Total',      'champ' => 'commandes.total',                        'src' => 'API commandes'],
                ['col' => 'Marge nette', 'champ' => '(CA − achat) − commission marque',       'src' => 'calcul local',
                 'note' => 'le coût d\'achat est déjà disponible (coût matière des recettes)'],
            ]],
        'stock' => [
            'titre'  => 'Stock',
            'source' => 'API stock — aucune source connue à ce jour, ni base ni panel',
            'colonnes' => [
                ['col' => 'Référence',    'champ' => 'produits.id + nom',        'src' => 'déjà disponible (catalogue)'],
                ['col' => 'Stock actuel', 'champ' => 'produits.stock_actuel',    'src' => 'API stock'],
                ['col' => 'Stock mini',   'champ' => 'produits.stock_min',       'src' => 'API stock'],
                ['col' => 'Rotation',     'champ' => 'produits.rotation_attendue', 'src' => 'API stock'],
                ['col' => 'Délai réappro', 'champ' => 'produits.delai_reappro_jours', 'src' => 'API stock'],
                ['col' => 'Alerte',       'champ' => 'stock_actuel < stock_min → rupture', 'src' => 'calcul local'],
            ]],
        'facturation' => [
            'titre'  => 'Facturation magasins',
            'source' => 'API facturation — factures magasins, TVA par ligne, relances',
            'colonnes' => [
                ['col' => 'Facture',  'champ' => 'facture.id + date',                  'src' => 'API facturation'],
                ['col' => 'Magasin',  'champ' => 'facture.magasin_id',                 'src' => 'API facturation'],
                ['col' => 'Montant HT', 'champ' => 'Σ quantite × prix_unitaire',       'src' => 'API facturation'],
                ['col' => 'TVA',      'champ' => 'PAR LIGNE : quantite × prix × produit.tva_pct/100', 'src' => 'API produits (tva_pct)',
                 'note' => 'jamais de taux global ; repli sur le taux par défaut des réglages'],
                ['col' => 'Statut',   'champ' => 'à payer | payée | litige',           'src' => 'API facturation'],
                ['col' => 'Relance',  'champ' => 'date de dernière relance',           'src' => 'API facturation'],
            ]],
    ][$ecran] ?? ['titre' => $ecran, 'source' => 'source non déterminée', 'colonnes' => []];

    return ['ecran' => $ecran, 'etat' => 'attente', 'titre' => $def['titre'],
        'source' => $def['source'], 'colonnes' => $def['colonnes'], 'lignes' => []];
}

/**
 * Lacune déclarée par un endpoint : un champ qu'il ne peut pas servir, et
 * pourquoi.
 *
 * Deux causes que rien ne distinguait jusqu'ici, et que confondre coûte cher :
 *  · `api`  — aucune source n'expose la donnée. Il faut la RÉCLAMER, et l'écran
 *             dit à qui et sous quel nom.
 *  · `saisie` — la source existe, elle est vide. Il n'y a rien à réclamer, il
 *             faut REMPLIR. Envoyer quelqu'un chercher une API dans ce cas,
 *             c'est le lancer sur une piste qui n'existe pas.
 *
 * L'écran affiche « manque API » dans le premier cas, « à renseigner » dans le
 * second, avec les champs attendus nommés.
 */
function lacune(string $champ, string $quoi, string $source, string $type = 'api'): array
{
    return ['champ' => $champ, 'quoi' => $quoi, 'source' => $source, 'type' => $type];
}


/**
 * Catalogue des lacunes, DÉTECTÉES à l'exécution et non listées à la main.
 *
 * Une liste écrite en dur périme : le jour où le panel expose enfin le food
 * cost, l'écran continuerait de le réclamer. On mesure donc l'état réel — un
 * champ n'est déclaré manquant que s'il l'est vraiment, sur toutes les lignes.
 *
 * La distinction entre « manque API » et « à renseigner » est portée ici, une
 * fois, plutôt que devinée par chaque écran.
 */
function ep_lacunes(): array
{
    $out = [];

    // --- performance magasin : ce que le P&L du panel ne porte pas
    try {
        $perf = ep_perf();
        $n = count($perf);
        if ($n > 0) {
            $sansFc = 0; $sansVal = 0;
            foreach ($perf as $r) {
                if (($r['foodCostPct'] ?? null) === null) { $sansFc++; }
                if (($r['valorisation'] ?? null) === null) { $sansVal++; }
            }
            if ($sansFc === $n) {
                $out['magasins'][] = lacune('Food cost %',
                    'le ratio matière par magasin et par mois',
                    'API panel — mac_shop_monthly_pnl ne porte pas le poste « material » (ticket T5a du panel)');
                $out['marge'][] = end($out['magasins']);
            }
            if ($sansVal === $n) {
                $out['magasins'][] = lacune('Valorisation',
                    'la valeur du fonds par magasin',
                    'API panel — le ValuationService du panel la calcule sans la stocker dans le snapshot mensuel');
            }
        }
    } catch (Throwable $e) { /* la perf est indisponible : rien à déclarer */ }

    // --- exploitation : objectifs absents parce que le budget n'est pas saisi
    try {
        $b = Db::row('SELECT COUNT(*) AS n FROM ceo_shop_budget');
        $s = Db::row('SELECT COUNT(*) AS n FROM shops WHERE active = 1');
        $nb = (int) ($b['n'] ?? 0); $ns = (int) ($s['n'] ?? 0);
        if ($ns > 0 && $nb < $ns) {
            $out['exploitation'][] = lacune('Objectif / Atteinte',
                'le budget mensuel de ' . ($ns - $nb) . ' magasin(s) sur ' . $ns,
                'Écran « Encodage du budget » — la table existe, elle attend la saisie', 'saisie');
        }
    } catch (PDOException $e) { /* tables absentes */ }

    // --- consultants : référentiel incomplet, pas une API manquante
    try {
        $c = ep_consultants();
        $n = count($c);
        if ($n > 0) {
            $sans = 0;
            foreach ($c as $r) { if (($r['tjm'] ?? null) === null && ($r['charge'] ?? null) === null) { $sans++; } }
            if ($sans === $n) {
                $out['parametres'][] = lacune('TJM / Charge',
                    'le taux journalier et la charge des ' . $n . ' consultants',
                    'Référentiel consultants — le panel ne porte pas ces champs, ils se saisissent côté cockpit', 'saisie');
            }
        }
    } catch (Throwable $e) { /* consultants indisponibles */ }

    // --- catalogue produit : coût matière et prix de vente incomplets
    try {
        $cat = ep_prod_catalogue();
        $n = count($cat);
        if ($n > 0) {
            $sansMat = 0; $sansPrix = 0; $sansFour = $n;
            foreach ($cat as $p) {
                if (($p['mat'] ?? null) === null) { $sansMat++; }
                if (($p['prix'] ?? null) === null) { $sansPrix++; }
            }
            if ($sansMat > 0) {
                $out['catalogue'][] = lacune('Coût matière',
                    $sansMat . ' référence(s) sur ' . $n . ' sans coût',
                    'Recettes du panel — recipe_cost ne couvre pas toutes les références ; le reste se saisit par fiche', 'saisie');
            }
            if ($sansPrix > 0) {
                $out['catalogue'][] = lacune('Prix de vente',
                    $sansPrix . ' référence(s) sur ' . $n . ' sans prix',
                    'Caisse du panel — shop_product.portion_price absent pour ces références', 'api');
            }
            // Le batch : sans lui, l'assortiment ne peut pas proposer de minimum
            // tenable, et le suivi de production n'a pas d'unité de fournée.
            $sansBatch = 0;
            foreach ($cat as $p) { if ((int) ($p['bmin'] ?? 0) <= 0) { $sansBatch++; } }
            if ($sansBatch === $n) {
                $out['assortiment'][] = lacune('Batch (fournée minimale)',
                    'aucune des ' . $n . ' références ne porte de batch',
                    'Fiche de production — le champ existe (ceo_prod_product.bmin), il attend la saisie. '
                    . 'Sans lui, le minimum d\'assortiment ne peut pas être calé sur une fournée', 'saisie');
                $out['catalogue'][] = end($out['assortiment']);
            }
            // Le rattachement référence → fournisseur n'existe nulle part.
            $out['catalogue'][] = lacune('Fournisseur',
                'le fournisseur de chacune des ' . $n . ' références',
                'API achats — aucune source ne rattache une référence à un fournisseur');
        }
        // Références écartées : identifiant négatif, donc inexploitable. On dit
        // combien, et pourquoi — une exclusion silencieuse se lit comme un trou.
        try {
            $f = Db::row('SELECT COUNT(*) AS n FROM product WHERE is_active = 1 AND id <= 0');
            if ((int) ($f['n'] ?? 0) > 0) {
                $out['catalogue'][] = lacune('Référence fantôme',
                    (int) $f['n'] . ' référence(s) écartée(s) du catalogue',
                    'Catalogue du panel — identifiant négatif et catégorie inexistante ; à corriger côté panel, '
                    . 'aucun magasin ne peut tenir une référence sans identifiant');
                $out['assortiment'][] = end($out['catalogue']);
            }
        } catch (PDOException $e) { /* table de caisse absente */ }
    } catch (Throwable $e) { /* catalogue indisponible */ }

    // --- assortiment : rien n'est déclaré obligatoire
    try {
        $c2 = Db::row('SELECT COUNT(*) AS n FROM ceo_prod_product WHERE must = 1');
        if ((int) ($c2['n'] ?? 0) === 0) {
            $out['assortiment'][] = lacune('Références obligatoires',
                'aucune référence déclarée obligatoire pour le réseau',
                'Cet écran — « Afficher tout le catalogue », puis cocher les références '
                . 'que toute boutique doit tenir', 'saisie');
        }
    } catch (PDOException $e) { /* table absente */ }

    // --- scoring / analyse : la vente par magasin et par référence
    $out['produits'][] = lacune('Vente par magasin',
        'le volume vendu d\'une référence dans UN magasin',
        'API panel — /shops/{id}/products/waste rend un sold_qty RÉSEAU, identique dans les quatre boutiques (mesuré : 5165 partout)');
    $out['analyse'][] = end($out['produits']);

    // --- contrôle des tâches : les tâches restant à contrôler
    try {
        $d = Db::row('SELECT MAX(review_date) AS d FROM mac_task_review');
        $der = (string) ($d['d'] ?? '');
        if ($der !== '' && $der < date('Y-m-d')) {
            $out['controle'][] = lacune('Tâches à contrôler',
                'les tâches rendues depuis le ' . $der . ' et non encore notées',
                'L\'écran part de mac_task_review (notes DÉJÀ posées) ; il doit partir de /consultant/shops/{id}/tasks');
        }
    } catch (PDOException $e) { /* table absente */ }

    // Les repères se posent et se gardent ; c'est le canal RETOUR qui manque.
    $out['controle'][] = annotationLacune();

    // --- centrale d'achat. Stock, commandes franchisés et suivi fournisseurs
    //     sont désormais servis par l'API du panel : ne restent en manque que
    //     ce que leurs endpoints déclarent eux-mêmes, et les écrans encore
    //     sans source.
    $ecransSansSource = ['campagnes' => 'Campagnes commerciales'];
    if (!ErpApi::disponible()) {
        // Servie par le réalm admin de l'ERP dès que le compte est renseigné.
        $ecransSansSource['facturation'] = 'Facturation magasins';
    }
    foreach ($ecransSansSource as $k => $lib) {
        $m = ep_ca_manquant($k);
        $n = 0;
        foreach ($m['colonnes'] as $c) { if (!preg_match('/déjà|calcul local/i', (string) $c['src'])) { $n++; } }
        $out['centrale'][] = lacune($lib, $n . ' colonne(s) sur ' . count($m['colonnes']), $m['source']);
    }
    // Résidus des trois écrans désormais branchés — déclarés ici sans rappeler
    // leurs endpoints, qui font chacun un appel amont par boutique.
    $out['centrale'][] = lacune('Commandes fournisseurs, réception, litiges',
        'les commandes fournisseurs avec quantités commandées/reçues et l’état des factures',
        'API panel — seuls des webhooks entrants existent, aucune route de lecture. À réclamer : GET /material-orders');
    $out['centrale'][] = lacune('Détail d’une réquisition franchisé',
        'les lignes produit par produit d’une commande',
        'API panel — /material-requisitions/{id} et /document existent, à câbler sur un clic de ligne');
    $out['centrale'][] = lacune('Rotation & délai de réappro',
        'la rotation attendue et le délai de réapprovisionnement par référence en stock',
        'API panel — l’inventaire ne porte que le stock courant et le minimum journalier');
    return $out;
}

/**
 * Sonde : que rend l'API des tâches du panel pour une journée donnée ?
 *
 * L'écran « Contrôle des tâches » partait de `mac_task_review`, la table des
 * notes DÉJÀ posées : il ne pouvait donc afficher que du déjà-contrôlé, jamais
 * le reste à contrôler. Avant de le rebrancher sur la route amont, il faut
 * savoir ce qu'elle rend, et sous quels noms de champs — un mauvais mapping
 * produirait une liste de tâches à contrôler qui aurait l'air juste.
 */
function ep_pwa_tasks_sonde(): array
{
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    $out = ['date' => $date, 'shops' => [], 'erreur' => null];
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte API non configuré']; }
    try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY id'); }
    catch (PDOException $e) { $shops = []; }
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        $l = PanelApi::shopTasks($sid, $date);
        $e = ['id' => $sid, 'nom' => $s['name'], 'n' => count($l), 'erreur' => PanelApi::$lastError];
        if ($l && is_array($l[0])) {
            $e['clesPremier'] = array_keys($l[0]);
            $e['premier'] = $l[0];
            // Quelles valeurs distinctes portent les champs d'état ? C'est ce
            // qui dira comment reconnaître « rendue, en attente de contrôle ».
            foreach (['status', 'state', 'is_done', 'done', 'completed', 'is_completed'] as $k) {
                $vals = [];
                foreach ($l as $t) { if (array_key_exists($k, $t)) { $vals[json_encode($t[$k])] = true; } }
                if ($vals) { $e['valeurs'][$k] = array_keys($vals); }
            }
        }
        $out['shops'][] = $e;
    }
    return $out;
}

/**
 * Audit de FRAÎCHEUR : jusqu'à quand chaque source va-t-elle, et l'API va-t-elle
 * plus loin ?
 *
 * La caisse en base partagée s'arrête à une date qui n'est pas aujourd'hui.
 * Tout écran qui la lit affiche donc des ventes périmées sans le dire — et rien
 * ne le signale, puisqu'un total de juillet reste un total plausible en août.
 * Cet audit met les deux côte à côte : la dernière date en base, la dernière
 * que l'API accepte de servir, et l'écart entre les deux.
 */
function ep_audit_fraicheur(): array
{
    $auj = date('Y-m-d');
    $out = ['aujourdhui' => $auj, 'sources' => [], 'api' => [], 'ecrans' => []];

    // 1) Jusqu'où va chaque table lue par les écrans ?
    $tables = [
        'transaction'          => ['MAX(DATE(insert_timestamp))', 'ventes de caisse (ligne à ligne)'],
        'product_movement'     => ['MAX(DATE(created_at))', 'production et rebut déclarés'],
        'mac_shop_monthly_pnl' => [null, 'P&L mensuel du panel'],
        'mac_task_review'      => ['MAX(review_date)', 'avis sur les tâches'],
    ];
    foreach ($tables as $t => $def) {
        $e = ['table' => $t, 'quoi' => $def[1], 'derniere' => null, 'retard' => null, 'erreur' => null];
        try {
            if ($t === 'mac_shop_monthly_pnl') {
                $r = Db::row("SELECT CONCAT(MAX(year), '-', LPAD(MAX(month), 2, '0')) d FROM mac_shop_monthly_pnl");
                $e['derniere'] = $r['d'] ?? null;
            } else {
                $r = Db::row("SELECT {$def[0]} d FROM {$t}");
                $e['derniere'] = $r['d'] ?? null;
            }
            if ($e['derniere'] !== null && strlen((string) $e['derniere']) >= 10) {
                $e['retard'] = (int) floor((strtotime($auj) - strtotime((string) $e['derniere'])) / 86400);
            }
        } catch (PDOException $ex) { $e['erreur'] = 'table absente'; }
        $out['sources'][] = $e;
    }

    // 2) Jusqu'où l'API accepte-t-elle d'aller ? On lui demande AUJOURD'HUI.
    if (PanelApi::configured()) {
        $r = PanelApi::shopsSalesKpisEntre($auj, $auj);
        $n = count(analyseListe(is_array($r) ? $r : []));
        $ca = 0.0;
        foreach (analyseListe(is_array($r) ? $r : []) as $sh) {
            $v = nombreOuNull($sh, ['ca', 'turnover', 'revenue']);
            if ($v !== null) { $ca += $v; }
        }
        $out['api'][] = ['route' => '/consultant/shops/sales-kpis', 'date' => $auj,
            'magasins' => $n, 'ca' => round($ca, 2),
            'verdict' => $n > 0 ? 'sert le jour même' : 'rien rendu pour aujourd\'hui'];
    } else {
        $out['api'][] = ['route' => '—', 'verdict' => 'compte consultant non configuré'];
    }

    // 3) Quels écrans en dépendent, et que devraient-ils lire ?
    $der = null;
    foreach ($out['sources'] as $s) { if ($s['table'] === 'transaction') { $der = $s['derniere']; } }
    $retard = $der !== null ? (int) floor((strtotime($auj) - strtotime((string) $der)) / 86400) : null;
    $out['ecrans'] = [
        ['ecran' => 'Scoring des références', 'route' => '/products/scoring',
         'lit' => 'transaction (caisse en base)', 'consequence' => 'volumes arrêtés au ' . ($der ?? '?'),
         'remplacer' => '/shops/{id}/products/waste — sold_qty, servi jusqu\'au jour même'],
        ['ecran' => 'Suivi de production', 'route' => '/production/suivi',
         'lit' => 'product_movement + transaction', 'consequence' => 'taux de perte sur un mois clos, pas sur le mois courant',
         'remplacer' => '/shops/{id}/products/waste — waste_qty par magasin, à la journée'],
        ['ecran' => 'Détail de perte d\'une référence', 'route' => '/products/waste',
         'lit' => 'transaction pour le denominateur des ventes', 'consequence' => 'ventes périmées face à un rebut à jour',
         'remplacer' => 'la même route waste porte déjà les deux'],
        ['ecran' => 'Tableau des magasins / Marge', 'route' => '/stores/perf',
         'lit' => 'mac_shop_monthly_pnl (snapshot mensuel)', 'consequence' => 'aucun mois en cours : le snapshot est mensuel',
         'remplacer' => '/consultant/shops/sales-kpis entre deux dates, pour le mois courant'],
        ['ecran' => 'P&L magasins', 'route' => '/exploitation',
         'lit' => 'transaction, ancré sur la dernière journée encodée',
         'consequence' => 'affiche « jour » = ' . ($der ?? '?') . ', pas aujourd\'hui',
         'remplacer' => '/consultant/shops/sales-kpis — déjà utilisé par /exploitation/reseau'],
    ];
    $out['retardCaisse'] = $retard;
    $out['resume'] = $retard === null ? 'caisse illisible'
        : ($retard <= 1 ? 'la caisse en base est à jour' : 'la caisse en base a ' . $retard . ' jour(s) de retard sur aujourd\'hui');
    return $out;
}

/**
 * Proposition de note par l'IA pour une tâche photographiée.
 *
 * L'écran de contrôle affiche la photo prise en boutique et, quand elle existe,
 * la fiche technique attendue. Cette route soumet les deux au modèle et rend une
 * PROPOSITION : niveau du barème réseau, constats, commentaire. Rien n'est
 * écrit — le consultant valide ou corrige, et c'est son geste qui note.
 */
function ep_ia_note(): array
{
    $shopId = (int) ($_GET['shop'] ?? 0);
    $taskId = (int) ($_GET['task'] ?? 0);
    $date   = (string) ($_GET['date'] ?? '');
    if ($shopId <= 0 || $taskId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        return ['error' => 'shop, task et date (YYYY-MM-DD) sont requis'];
    }
    $out = ['shopId' => (string) $shopId, 'taskId' => (string) $taskId, 'date' => $date,
        'etat' => 'attente', 'niveau' => null, 'nom' => null, 'commentaire' => null,
        'constats' => [], 'confiance' => null, 'modele' => null, 'motif' => null];

    if (!Anthropic::configured()) {
        $out['motif'] = 'manque API — aucune clé Anthropic enregistrée (Paramètres → Assistance IA)';
        return $out;
    }
    // La photo vient du détail : même source que l'écran, donc même image que
    // celle que le consultant a sous les yeux. Juger une autre photo que la
    // sienne serait le pire des malentendus.
    $d = ep_pwa_task_detail();
    if (empty($d['photo'])) {
        $out['motif'] = 'aucune photo sur cette tâche — il n\'y a rien à évaluer';
        return $out;
    }
    $sig = setting('signalement', []);
    $niveaux = (is_array($sig) && !empty($sig['niveaux'])) ? $sig['niveaux'] : signalementDefaut()['niveaux'];

    $ctx = trim(($d['checklist'] ? 'checklist « ' . $d['checklist'] . ' »' : '')
        . (!empty($d['obligatoire']) ? ', tâche obligatoire' : '')
        . ($d['produit'] ? ', produit attendu : ' . $d['produit'] : ''));
    $r = Anthropic::noterPhoto((string) ($d['tache'] ?? ('Tâche #' . $taskId)),
        (string) $d['photo'], $d['photoRef'] ?? null, $niveaux, $ctx);

    $out['modele'] = $r['modele'];
    // L'état du connecteur : une proposition rendue est un succès, un refus du
    // modèle ou une panne réseau un échec — c'est le seul geste que le cockpit
    // fait vers Anthropic, donc le seul qui puisse le renseigner.
    connecteurNote('anthropic', $r['niveau'] !== null, (string) ($r['erreur'] ?? ('niveau ' . $r['niveau'])));
    if ($r['erreur'] !== null && $r['niveau'] === null) { $out['motif'] = $r['erreur']; return $out; }
    $out['etat'] = 'ok';
    $out['niveau'] = $r['niveau'];
    $out['nom'] = $r['nom'];
    $out['commentaire'] = $r['commentaire'];
    $out['constats'] = $r['constats'];
    $out['confiance'] = $r['confiance'];
    // Une confiance faible n'est pas une note : elle invite à regarder soi-même.
    if ($r['confiance'] === 'faible') {
        $out['avertissement'] = 'Le modèle annonce une confiance faible : la photo ne permet peut-être pas de conclure. Jugez par vous-même.';
    }
    return $out;
}

/** État de l'assistance IA pour l'écran Paramètres — jamais la clé. */
function ep_ia_statut(): array
{
    return Anthropic::statut();
}

/**
 * GET /marketing — campagnes, calendrier et types, lus dans les tables mar_*.
 *
 * Le module marketing autonome va disparaître : le cockpit reprend Pilotage →
 * Calendrier / Campagnes / Types de campagne en lisant DIRECTEMENT ses tables,
 * comme il le fait pour pla_* — il n'y a plus d'API à appeler quand le module
 * n'existe plus. Les tables restent les mêmes : rien à migrer, l'historique
 * des campagnes survit à la suppression du module.
 */
function ep_mkt(): array
{
    try {
        $marques = Db::rows('SELECT id, name FROM mar_brand ORDER BY id');
    } catch (PDOException $e) {
        return ['indispo' => true,
            'raison' => 'Les tables mar_* sont absentes de la base partagée : le module marketing n’a jamais été installé ici.'];
    }
    $statuts = array_map(fn ($s) => ['code' => $s['code'], 'nom' => $s['label'],
        'texte' => $s['text_hex'], 'fond' => $s['bg_rgba'], 'ordre' => (int) $s['sort_order']],
        Db::rows('SELECT * FROM mar_campaign_status ORDER BY sort_order, id'));

    // Les types, avec TOUT ce que le module en connaissait : description, couleur,
    // icône et levier lié. N'en lire qu'une partie donnait une fiche à moitié
    // vide dès qu'un type venait de l'assistant — et la réécrivait à moitié
    // vide au premier enregistrement.
    //
    // `lever_label` : le badge du type s'il en porte un, sinon le nom du levier
    // lié. Même COALESCE que le module, pour que les deux affichent le même mot.
    // `default_lever_code` reste lu en dernier recours : les types d'avant la
    // liaison ne portent que ça.
    $colLevier = mktTypeAColonne('lever_id');
    $sqlTypes = $colLevier
        ? 'SELECT t.*, COALESCE(t.lever_badge_label, l.label) AS lever_label, l.color_hex AS lever_color
             FROM mar_campaign_type t LEFT JOIN mar_lever l ON l.id = t.lever_id
            ORDER BY t.sort_order, t.id'
        : 'SELECT t.* FROM mar_campaign_type t ORDER BY t.sort_order, t.id';
    $compte = static fn (string $sql): string => str_replace('SELECT t.*',
        'SELECT t.*, (SELECT COUNT(*) FROM mar_campaign c WHERE c.type_id = t.id) AS campagnes', $sql);
    // La liste des colonnes n'est qu'une lecture d'`information_schema` : si
    // elle se trompe, l'écran des campagnes entier tombait en 500 sur une
    // jointure. Le repli sans levier reste lisible — badge du type d'un côté,
    // couleur du levier en moins — et se voit tout de suite.
    try { $lignesTypes = Db::rows($compte($sqlTypes)); }
    catch (PDOException $e) {
        $lignesTypes = Db::rows($compte('SELECT t.* FROM mar_campaign_type t ORDER BY t.sort_order, t.id'));
    }
    $types = array_map(fn ($t) => [
        'id' => (int) $t['id'], 'code' => $t['code'], 'nom' => $t['label'],
        'description' => $t['description'] ?? null,
        'levier' => $t['lever_label'] ?? $t['default_lever_code'] ?? null,
        'levierId' => isset($t['lever_id']) && $t['lever_id'] !== null ? (int) $t['lever_id'] : null,
        'levierBadge' => $t['lever_badge_label'] ?? null,
        'levierCouleur' => $t['lever_color'] ?? null,
        'kpi' => $t['default_kpi_label'],
        'couleur' => $t['color_hex'] ?? null,
        'icone' => $t['icon_key'] ?? null, 'iconePath' => $t['icon_path'] ?? null,
        'ordre' => (int) $t['sort_order'],
        'actif' => (bool) $t['is_active'],
        // Compté en base, pas sur les campagnes chargées : c'est lui qui décide
        // si « Supprimer » a un sens, et une liste tronquée le rendrait faux.
        'nCampagnes' => (int) ($t['campagnes'] ?? 0)],
        $lignesTypes);
    $nShops = [];
    foreach (Db::rows('SELECT campaign_id, COUNT(*) n FROM mar_campaign_shop GROUP BY campaign_id') as $r) {
        $nShops[(int) $r['campaign_id']] = (int) $r['n'];
    }
    $campagnes = [];
    foreach (Db::rows('SELECT c.*, t.label AS type_label, t.color_hex AS type_color,
                              s.label AS status_label, s.text_hex, s.bg_rgba
                       FROM mar_campaign c
                       LEFT JOIN mar_campaign_type t ON t.id = c.type_id
                       LEFT JOIN mar_campaign_status s ON s.code = c.status_code
                       ORDER BY c.starts_on IS NULL, c.starts_on DESC, c.id DESC') as $c) {
        $tid = $c['type_id'] !== null ? (int) $c['type_id'] : null;
        $campagnes[] = [
            'id' => (int) $c['id'], 'nom' => (string) $c['name'],
            'typeId' => $tid, 'type' => $c['type_label'], 'typeCouleur' => $c['type_color'],
            'scope' => (string) $c['scope'],
            'statut' => (string) $c['status_code'], 'statutNom' => $c['status_label'] ?? $c['status_code'],
            'statutTexte' => $c['text_hex'] ?? '#666666', 'statutFond' => $c['bg_rgba'] ?? 'rgba(120,116,110,.12)',
            'debut' => $c['starts_on'], 'fin' => $c['ends_on'],
            'budget' => (float) $c['budget_amount'], 'depense' => (float) $c['spent_amount'],
            'nBoutiques' => $nShops[(int) $c['id']] ?? 0,
            'image' => $c['image_url'] ?: null,
        ];
    }
    // Les leviers et la bibliothèque d'icônes accompagnent les types : l'écran
    // ne redéclare ni les uns ni les autres, il affiche ce que le serveur sert.
    $leviers = [];
    try {
        $leviers = array_map(fn ($l) => ['id' => (int) $l['id'], 'code' => $l['code'],
            'nom' => $l['label'], 'couleur' => $l['color_hex']],
            Db::rows('SELECT id, code, label, color_hex FROM mar_lever WHERE is_active = 1 ORDER BY sort_order, id'));
    } catch (PDOException $e) { /* mar_lever absente : le choix de levier se réduit à « aucun » */ }

    return ['campagnes' => $campagnes, 'types' => $types, 'statuts' => $statuts,
        'leviers' => $leviers, 'icones' => mktIcones(),
        'marqueId' => $marques ? (int) $marques[0]['id'] : null,
        'marque' => $marques ? (string) $marques[0]['name'] : ''];
}

/**
 * GET /reputation — la réputation digitale du réseau, magasin par magasin.
 *
 * Trois chiffres, et un seul calcul qui compte :
 *
 *  - la note et le nombre d'avis viennent de `ceo_shop_reputation`, c'est-à-dire
 *    de ce que Google affiche. Les cinq avis rapatriés sont un échantillon de
 *    lecture : les moyennes ne s'en déduisent pas ;
 *  - la moyenne réseau est PONDÉRÉE par le nombre d'avis. Une moyenne de
 *    moyennes donnerait le même poids à un magasin qui a 12 avis et à un qui en
 *    a 400, et flatterait le réseau ;
 *  - `avis5Requis` répond à « combien d'avis 5 étoiles pour remonter à la
 *    cible ». Depuis une moyenne A sur n avis, ajouter x avis à 5 donne
 *    (A·n + 5x)/(n + x) ≥ C, soit x ≥ n(C − A)/(5 − C). On arrondit au
 *    supérieur : un demi-avis n'existe pas.
 *
 * Cible dans `ceo_app_setting.reputationCible` (Paramètres), 4,5 par défaut.
 */
function ep_reputation(): array
{
    $cible = (float) setting('reputationCible', 4.5);
    if ($cible < 1 || $cible > 5) { $cible = 4.5; }

    try {
        $agr = [];
        foreach (Db::rows('SELECT * FROM ceo_shop_reputation') as $r) { $agr[$r['shop_id']] = $r; }
    } catch (PDOException $e) {
        return ['indispo' => true, 'cible' => $cible,
            'raison' => 'Les tables de réputation sont absentes de cette base.'];
    }

    // Les magasins viennent de la table PARTAGÉE `shops` — la même autorité que
    // `ep_stores()`. `ceo_shop` n'est qu'un miroir local rempli à la demande, et
    // il n'a pas de colonne `city` : le lire ici cassait la requête et, même
    // corrigée, n'aurait montré que les magasins déjà passés par l'encodage
    // budget. Le miroir reste le repli des installations autonomes (démo).
    try {
        $shops = array_map(fn ($s) => ['id' => (string) $s['id'], 'name' => $s['name'],
            'ville' => (string) ($s['city'] ?: ($s['zone'] ?: ($s['region'] ?: '')))],
            Db::rows('SELECT id, name, city, zone, region FROM shops WHERE active = 1 ORDER BY name'));
    } catch (PDOException $e) {
        $shops = array_map(fn ($s) => ['id' => (string) $s['id'], 'name' => $s['name'], 'ville' => (string) $s['zone']],
            Db::rows("SELECT id, name, zone FROM ceo_shop WHERE status = 'Ouvert' ORDER BY name"));
    }

    $repartitions = reputationRepartitions(array_map(fn ($s2) => (string) $s2['id'], $shops));

    $magasins = [];
    $sommeNotes = 0.0; $sommeAvis = 0;
    foreach ($shops as $s) {
        $a = $agr[$s['id']] ?? null;
        $note = ($a && $a['rating_avg'] !== null) ? (float) $a['rating_avg'] : null;
        $n    = $a ? (int) $a['rating_count'] : 0;
        if ($note !== null && $n > 0) { $sommeNotes += $note * $n; $sommeAvis += $n; }

        // Les cinq derniers avis du magasin — une requête bornée par magasin
        // plutôt qu'un balayage de toute la table à découper ensuite.
        $derniers = array_map(fn ($v) => [
            'auteur' => $v['author'] ?: 'Client Google',
            'note'   => (int) $v['rating'],
            'texte'  => $v['comment'],
            'le'     => substr((string) $v['reviewed_at'], 0, 10),
            // Tri-état : vrai si l'on sait que le magasin a répondu, NUL si la
            // source ne le dit pas. L'API Places ne rend pas les réponses :
            // afficher « Sans réponse » sur chaque avis serait une affirmation
            // fausse, pas une information manquante.
            'repondu' => $v['replied_at'] !== null ? true : null,
        ], Db::rows('SELECT * FROM ceo_shop_review WHERE shop_id = ? ORDER BY reviewed_at DESC, id DESC LIMIT 5', [$s['id']]));

        $magasins[] = [
            'id' => $s['id'], 'nom' => $s['name'], 'ville' => $s['ville'],
            'note' => $note, 'avis' => $n,
            'ecart' => $note !== null ? round($note - $cible, 2) : null,
            'avis5Requis' => reputationAvis5($note, $n, $cible),
            'repartition' => $repartitions['magasins'][(string) $s['id']] ?? null,
            'placeId' => $a['place_id'] ?? null,
            'url' => $a['profile_url'] ?? null,
            'synchro' => ($a && $a['synced_at'] !== null) ? substr((string) $a['synced_at'], 0, 16) : null,
            'derniers' => $derniers,
        ];
    }

    $moyenne = $sommeAvis > 0 ? round($sommeNotes / $sommeAvis, 2) : null;
    $notes = array_values(array_filter(array_column($magasins, 'note'), fn ($v) => $v !== null));

    $derniereSynchro = null;
    foreach ($agr as $r) {
        if ($r['synced_at'] !== null && ($derniereSynchro === null || $r['synced_at'] > $derniereSynchro)) {
            $derniereSynchro = $r['synced_at'];
        }
    }

    return [
        'cible' => $cible,
        // L'état du connecteur voyage avec les données : l'écran doit pouvoir
        // dire « aucune clé » plutôt que d'afficher un réseau vide sans raison.
        // La dernière synchro vient de la table des connecteurs : c'est elle qui
        // enregistre les gestes. La déduire de `synced_at` des fiches donnait la
        // même réponse tant que tout allait bien, et une réponse fausse dès
        // qu'une synchro échouait — les fiches gardaient leur ancienne date.
        'connecteur' => GoogleApi::statut() + (connecteurEtat('google') ?? []) + [
            'raccordes' => count(array_filter($agr, fn ($r) => ($r['place_id'] ?? '') !== '')),
            'derniereSynchro' => $derniereSynchro !== null ? substr((string) $derniereSynchro, 0, 16) : null,
        ],
        'reseau' => [
            'moyenne' => $moyenne,
            'avis' => $sommeAvis,
            'magasins' => count($magasins),
            'notes' => count($notes),
            'sousCible' => count(array_filter($notes, fn ($v) => $v < $cible)),
            'avis5Requis' => reputationAvis5($moyenne, $sommeAvis, $cible),
            // La répartition par étoiles porte sur les avis RAPATRIÉS, pas sur
            // les `avis` de Google : l'API Places ne publie pas l'histogramme
            // d'une fiche, seulement la moyenne et le total. Les deux chiffres
            // voyagent donc ensemble, et l'écran dit sur quoi il compte —
            // présenter 24 avis lus comme la répartition de 989 serait faux.
            'repartition' => $repartitions['reseau'],
        ],
        'magasins' => $magasins,
    ];
}

/**
 * La répartition par étoiles des avis que NOUS détenons, réseau et magasins.
 *
 * Google ne publie pas l'histogramme d'une fiche : l'API Places rend la
 * moyenne, le nombre total, et cinq avis. Notre table accumule ces cinq-là à
 * chaque synchronisation — l'échantillon grossit avec le temps, mais il reste
 * un échantillon. Le total lu part avec la répartition pour que l'écran puisse
 * le dire.
 *
 * Une seule requête, groupée par magasin ET par note : le réseau est la somme
 * des magasins, il ne se redemande pas à la base. À neuf magasins la
 * différence est mince ; à cinquante, ce serait cinquante et un allers-retours
 * pour un bloc décoratif.
 *
 * @param list<string> $shopIds les magasins affichés, pour ne pas compter ceux
 *                              qui ont quitté le réseau
 * @return array{reseau: array, magasins: array<string, array>}
 */
function reputationRepartitions(array $shopIds): array
{
    $vide = static fn (): array => ['lus' => 0,
        'niveaux' => array_map(fn ($n) => ['note' => $n, 'n' => 0], [5, 4, 3, 2, 1])];
    $out = ['reseau' => $vide(), 'magasins' => []];
    foreach ($shopIds as $id) { $out['magasins'][$id] = $vide(); }
    if ($shopIds === []) { return $out; }

    $in = implode(',', array_fill(0, count($shopIds), '?'));
    try {
        $rows = Db::rows("SELECT shop_id, rating, COUNT(*) n FROM ceo_shop_review
                           WHERE shop_id IN ($in) GROUP BY shop_id, rating", $shopIds);
    } catch (PDOException $e) {
        return $out;
    }

    $parMag = []; $parReseau = [];
    foreach ($rows as $r) {
        $sid = (string) $r['shop_id']; $note = (int) $r['rating']; $n = (int) $r['n'];
        $parMag[$sid][$note] = ($parMag[$sid][$note] ?? 0) + $n;
        $parReseau[$note] = ($parReseau[$note] ?? 0) + $n;
    }
    $forme = static fn (array $par): array => ['lus' => array_sum($par),
        'niveaux' => array_map(fn ($n) => ['note' => $n, 'n' => $par[$n] ?? 0], [5, 4, 3, 2, 1])];
    $out['reseau'] = $forme($parReseau);
    foreach ($shopIds as $id) { $out['magasins'][$id] = $forme($parMag[$id] ?? []); }
    return $out;
}

/**
 * Combien d'avis 5 étoiles pour atteindre la cible — null si la question n'a
 * pas de réponse.
 *
 * Deux cas rendent `null` plutôt que zéro, parce que ce n'est pas la même
 * chose : aucune note connue (rien à calculer), et une cible à 5 sur une
 * moyenne inférieure (aucun nombre d'avis parfaits n'y suffit, la moyenne
 * tend vers 5 sans l'atteindre).
 */
function reputationAvis5(?float $note, int $avis, float $cible): ?int
{
    if ($note === null || $avis <= 0) { return null; }
    if ($note >= $cible) { return 0; }
    if ($cible >= 5) { return null; }
    return (int) ceil($avis * ($cible - $note) / (5 - $cible));
}

/* --- Budget × Campagnes ------------------------------------------------------
 *
 * Le budget dit ce qu'un magasin doit faire ; la campagne dit ce qu'on lui
 * demande EN PLUS, et sur quels jours. Les deux vivaient dans deux écrans qui
 * ne se parlaient pas : impossible de dire si un objectif de campagne était
 * ambitieux ou déjà acquis.
 */

/** La table des objectifs par campagne ET par magasin — créée à la demande. */
function ensureCampagneObjectifs(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_campagne_objectif ('
        . 'campagne_id INT NOT NULL,'
        . 'shop_id VARCHAR(32) NOT NULL,'
        . 'objectif DECIMAL(12,2) NULL,'
        . 'maj DATETIME NULL,'
        . 'PRIMARY KEY (campagne_id, shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Le budget d'un magasin sur une FENÊTRE de dates, au prorata des jours.
 *
 * Une campagne du 4 au 17 août prend 14 jours sur 31 : elle vaut 14/31 du
 * budget d'août. Sans budget validé pour un mois, le CA théorique de l'étude
 * prend le relais — et la source est dite, pour qu'on ne prenne pas l'un pour
 * l'autre.
 */
function budgetSurFenetre(array $mois, string $du, string $au): array
{
    $d = new DateTimeImmutable($du); $f = new DateTimeImmutable($au);
    $total = 0.0; $source = null; $partiel = false;
    for ($m = 1; $m <= 12; $m++) {
        $an = (int) $d->format('Y');
        $debutMois = new DateTimeImmutable(sprintf('%04d-%02d-01', $an, $m));
        $finMois = new DateTimeImmutable($debutMois->format('Y-m-t'));
        $deb = max($d, $debutMois); $fin = min($f, $finMois);
        if ($deb > $fin) { continue; }
        $joursPris = (int) $deb->diff($fin)->days + 1;
        $joursMois = (int) $finMois->format('j');
        $cell = $mois[$m - 1] ?? ['budget' => null, 'theorique' => null];
        $val = $cell['budget'] !== null && $cell['budget'] > 0 ? $cell['budget'] : null;
        $src = $val !== null ? 'budget' : null;
        if ($val === null && ($cell['theorique'] ?? null) !== null && $cell['theorique'] > 0) {
            $val = $cell['theorique']; $src = 'theorique';
        }
        if ($val === null) { $partiel = true; continue; }
        $total += $val * $joursPris / $joursMois;
        // Une fenêtre à cheval sur deux mois peut mélanger les sources : on
        // garde la moins sûre, c'est elle qui doit se voir.
        $source = $source === 'theorique' || $src === 'theorique' ? 'theorique' : 'budget';
    }
    return ['montant' => $total > 0 ? round($total, 2) : null, 'source' => $source, 'partiel' => $partiel];
}

/** GET /marketing/budget-campagnes — le calendrier sur la courbe, et le détail. */
function ep_budget_campagnes(): array
{
    ensureCampagneObjectifs();
    $exercice = (int) ($_GET['exercice'] ?? setting('exercice', (int) date('Y')));
    try {
        // Le TYPE voyage avec la campagne : les bandes se rangent par type,
        // une ligne chacun — sinon deux campagnes qui se chevauchent se
        // marchent dessus et aucune ne se lit.
        $camps = Db::rows('SELECT c.id, c.name, c.starts_on, c.ends_on, c.status_code, s.label AS statut,
                                  t.label AS type_label, t.color_hex AS type_color
                             FROM mar_campaign c LEFT JOIN mar_campaign_status s ON s.code = c.status_code
                             LEFT JOIN mar_campaign_type t ON t.id = c.type_id
                            WHERE (c.starts_on IS NOT NULL AND c.starts_on <= ? AND (c.ends_on IS NULL OR c.ends_on >= ?))
                            ORDER BY c.starts_on', [$exercice . '-12-31', $exercice . '-01-01']);
    } catch (PDOException $e) {
        return ['indispo' => true, 'raison' => 'Les tables du module marketing (mar_*) sont absentes de cette base.'];
    }
    $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    $nomDe = [];
    foreach ($shops as $sh) { $nomDe[(string) $sh['id']] = (string) $sh['name']; }

    // Les douze mois, magasin par magasin : budget validé et théorique.
    $parMag = [];
    foreach ($shops as $sh) { $parMag[(string) $sh['id']] = array_fill(0, 12, ['budget' => null, 'theorique' => null]); }
    foreach (Db::rows('SELECT shop_id, month, revenue_budget, ca_theorique FROM ceo_shop_month_perf WHERE year = ?', [$exercice]) as $r) {
        $sid = (string) $r['shop_id']; $m = (int) $r['month'];
        if (!isset($parMag[$sid]) || $m < 1 || $m > 12) { continue; }
        $parMag[$sid][$m - 1] = [
            'budget' => $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : null,
            'theorique' => $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : null,
        ];
    }
    // La courbe réseau : la somme des magasins, mois par mois.
    $reseau = [];
    for ($i = 0; $i < 12; $i++) {
        $b = null; $t = null;
        foreach ($parMag as $mm) {
            if ($mm[$i]['budget'] !== null) { $b = ($b ?? 0) + $mm[$i]['budget']; }
            if ($mm[$i]['theorique'] !== null) { $t = ($t ?? 0) + $mm[$i]['theorique']; }
        }
        $reseau[] = ['budget' => $b, 'theorique' => $t];
    }
    // Quels mois sont couverts par au moins une campagne.
    $couvert = array_fill(0, 12, false);
    $liste = [];
    foreach ($camps as $c) {
        $du = (string) $c['starts_on']; $au = (string) ($c['ends_on'] ?: $c['starts_on']);
        $liste[] = ['id' => (int) $c['id'], 'nom' => (string) $c['name'], 'debut' => $du, 'fin' => $au,
            'statut' => (string) ($c['statut'] ?? $c['status_code'] ?? ''),
            'type' => (string) ($c['type_label'] ?? 'Sans type'),
            'typeCouleur' => (string) ($c['type_color'] ?? '')];
        $d = new DateTimeImmutable(max($du, $exercice . '-01-01'));
        $f = new DateTimeImmutable(min($au, $exercice . '-12-31'));
        for ($x = $d; $x <= $f; $x = $x->modify('first day of next month')) {
            $couvert[(int) $x->format('n') - 1] = true;
        }
    }

    // La campagne regardée : celle demandée, sinon la plus récente commencée.
    $choisie = (int) ($_GET['campagne'] ?? 0);
    if ($choisie <= 0) {
        foreach ($liste as $c) { if ($c['debut'] <= date('Y-m-d')) { $choisie = $c['id']; } }
        if ($choisie <= 0 && $liste !== []) { $choisie = $liste[0]['id']; }
    }
    $camp = null;
    foreach ($liste as $c) { if ($c['id'] === $choisie) { $camp = $c; } }

    $lignes = []; $realiseDispo = false;
    if ($camp !== null) {
        // Le périmètre de la campagne : ses magasins, ou tout le réseau.
        $perim = [];
        foreach (Db::rows('SELECT shop_id FROM mar_campaign_shop WHERE campaign_id = ?', [$camp['id']]) as $r) {
            $sid = (string) $r['shop_id'];
            if (isset($nomDe[$sid])) { $perim[] = $sid; }
        }
        if ($perim === []) { $perim = array_keys($nomDe); }

        $obj = [];
        foreach (Db::rows('SELECT shop_id, objectif FROM ceo_campagne_objectif WHERE campagne_id = ?', [$camp['id']]) as $r) {
            $obj[(string) $r['shop_id']] = $r['objectif'] !== null ? (float) $r['objectif'] : null;
        }
        // Le réalisé : le panel, sur les jours ÉCOULÉS de la campagne. Une
        // campagne à venir n'a pas de réalisé — et zéro n'en serait pas un.
        $finLue = min($camp['fin'], date('Y-m-d'));
        $ventes = [];
        if ($camp['debut'] <= date('Y-m-d') && PanelApi::configured()) {
            foreach (analyseListe(PanelApi::shopsSalesKpisEntre($camp['debut'], $finLue) ?? []) as $x) {
                $id = 0;
                foreach (['shop_id', 'id_shop', 'id'] as $k) {
                    if (isset($x[$k]) && is_numeric($x[$k])) { $id = (int) $x[$k]; break; }
                }
                $ca = (float) (nombreOuNull($x, ['ca', 'turnover', 'revenue']) ?? 0);
                if ($ca > 0) { $ventes[(string) $id] = $ca; $realiseDispo = true; }
            }
        }
        // Ce que la campagne devrait RAPPORTER, magasin par magasin : le panier
        // moyen des trois derniers mois clos × les clients par jour en plus
        // visés × la durée. Le pourcentage d'objectif ne dit rien à personne en
        // boutique ; des euros, si — et ils se comparent au budget encodé.
        $effet = mktEffetAttendu($camp['id'], $camp['debut'], $camp['fin'], $perim);

        foreach ($perim as $sid) {
            $bud = budgetSurFenetre($parMag[$sid] ?? [], $camp['debut'], $camp['fin']);
            $o = $obj[$sid] ?? null;
            $r = $ventes[$sid] ?? null;
            $ef = $effet['magasins'][$sid] ?? [];
            $attendu = ($ef['base'] ?? null) !== null && ($ef['gain'] ?? null) !== null
                ? $ef['base'] + $ef['gain'] : null;
            $lignes[] = [
                'shopId' => $sid, 'nom' => $nomDe[$sid] ?? $sid,
                'budgetPeriode' => $bud['montant'], 'source' => $bud['source'],
                // L'effet attendu et ce qui le compose — jamais un total sans
                // ses termes : « 56 653 € » ne se discute pas, « 50 594 + 6 059 »
                // se discute.
                'panier' => $ef['panier'] ?? null,
                'panierSource' => $ef['panierSource'] ?? null,
                'clientsJour' => $ef['clientsJour'] ?? null,
                'clientsJourPlus' => $ef['plus'] ?? null,
                'clientsA1' => $ef['clientsA1'] ?? null,
                'clientsA1Source' => $ef['clientsA1Source'] ?? null,
                'clientsPrevus' => $ef['clientsPrevus'] ?? null,
                'base' => $ef['base'] ?? null,
                'baseSource' => $ef['baseSource'] ?? null,
                'gain' => $ef['gain'] ?? null,
                'attendu' => $attendu,
                'atteinteAttendue' => $attendu !== null && $bud['montant']
                    ? round(100 * $attendu / $bud['montant']) : null,
                'objectif' => $o,
                'objectifPct' => $o !== null && $bud['montant'] ? round(100 * ($o / $bud['montant'] - 1), 1) : null,
                'realise' => $r,
                'ecart' => $o !== null && $r !== null ? round($r - $o, 2) : null,
                'atteinte' => $o !== null && $r !== null && $o > 0 ? round(100 * $r / $o) : null,
            ];
        }
    }
    return [
        'exercice' => $exercice,
        'mois' => $reseau, 'moisParMagasin' => $parMag, 'couvert' => $couvert,
        'campagnes' => $liste, 'campagne' => $camp, 'lignes' => $lignes,
        'effet' => $camp !== null ? ($effet['entete'] ?? null) : null,
        'realiseJusquau' => $camp !== null ? min($camp['fin'], date('Y-m-d')) : null,
        'realiseDispo' => $realiseDispo,
        'magasins' => array_map(fn ($id) => ['id' => $id, 'nom' => $nomDe[$id]], array_keys($nomDe)),
    ];
}

/* --- Écrans ouverts ---------------------------------------------------------
 *
 * Le journal trace les ACTIONS ; il ne disait rien des écrans ouverts. Sans
 * cette mesure, affiner le rail de navigation revient à deviner ce qui sert.
 * On compte donc les ouvertures — pas le temps passé, qui demanderait de
 * suivre la fenêtre et se lit mal : un écran laissé ouvert n'est pas un écran
 * utilisé.
 */

/** Le compteur d'ouvertures : une ligne par écran, par jour et par personne. */
function ensureEcranVues(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_ecran_vue ('
        . 'ecran VARCHAR(40) NOT NULL,'
        . 'jour DATE NOT NULL,'
        . 'acteur VARCHAR(80) NOT NULL DEFAULT \'\','
        . 'n INT NOT NULL DEFAULT 0,'
        . 'PRIMARY KEY (ecran, jour, acteur)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** GET /ecrans/vues?jours=30 — de quoi dessiner la heatmap du rail. */
function ep_ecran_vues(): array
{
    ensureEcranVues();
    $jours = max(7, min(90, (int) ($_GET['jours'] ?? 30)));
    $depuis = date('Y-m-d', strtotime('-' . ($jours - 1) . ' days'));
    $lignes = Db::rows('SELECT ecran, jour, SUM(n) n FROM ceo_ecran_vue WHERE jour >= ? GROUP BY ecran, jour', [$depuis]);
    $parEcran = []; $parJour = [];
    foreach ($lignes as $l) {
        $e = (string) $l['ecran']; $j = substr((string) $l['jour'], 0, 10); $n = (int) $l['n'];
        $parEcran[$e] = ($parEcran[$e] ?? 0) + $n;
        $parJour[$e][$j] = $n;
    }
    arsort($parEcran);
    $joursListe = [];
    for ($i = $jours - 1; $i >= 0; $i--) { $joursListe[] = date('Y-m-d', strtotime('-' . $i . ' days')); }
    $out = [];
    foreach ($parEcran as $e => $tot) {
        $out[] = ['ecran' => $e, 'total' => $tot,
            'jours' => array_map(fn ($j) => (int) ($parJour[$e][$j] ?? 0), $joursListe)];
    }
    // Qui ouvre quoi : utile pour distinguer l'écran d'un seul utilisateur de
    // celui que tout le monde ouvre.
    $acteurs = [];
    foreach (Db::rows('SELECT ecran, acteur, SUM(n) n FROM ceo_ecran_vue WHERE jour >= ? GROUP BY ecran, acteur', [$depuis]) as $l) {
        $acteurs[(string) $l['ecran']][(string) $l['acteur']] = (int) $l['n'];
    }
    return ['depuis' => $depuis, 'joursListe' => $joursListe, 'ecrans' => $out, 'acteurs' => $acteurs,
        'total' => array_sum($parEcran)];
}
