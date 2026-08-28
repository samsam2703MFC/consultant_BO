<?php
declare(strict_types=1);

/**
 * Cockpit CEO — le personnel de vente : target et classement.
 *
 * Qui vend le mieux, personne par personne. Le classement se fait au
 * CA PAR HEURE PRESTÉE, jamais au CA brut : une vendeuse à 20 heures ne se
 * compare pas à une à 38. Le panier moyen (CA ÷ tickets) et le cross-selling
 * (lignes par ticket) complètent la lecture. Chaque mois, la marque prime la
 * meilleure de chaque magasin et la meilleure du réseau.
 *
 * Deux sources, toutes deux RÉSOLUES et jamais supposées :
 *  - le vendeur sur le ticket (la caisse) ;
 *  - les heures prestées (le planning du panel).
 * Si l'une manque, l'écran nomme ce qui manque — il ne classe pas au CA brut
 * en silence, ce qui ferait gagner les plus gros horaires.
 */

/**
 * Sous ce volume d'heures, on montre mais on ne classe pas. À ZÉRO sur
 * demande du réseau : le classement est ouvert à toutes les heures prestées —
 * il reste impossible de classer sans AUCUNE heure au planning, un CA par
 * heure sans heures n'existe pas.
 */
const VENTE_SEUIL_HEURES = 0;

/**
 * Le lissage du classement : chacun se voit ajouter ces heures « à vide ».
 *
 * Le CA/heure brut couronnait cinq bonnes heures (mesuré : 509 €/h sur 5 h
 * devant 241 €/h sur 53 h). Le score classant est donc le CA/heure PONDÉRÉ
 * par un coefficient qui monte avec les heures prestées —
 * coefficient = heures ÷ (heures + lissage), soit score = CA ÷ (heures +
 * lissage). Au plus d'heures, au plus le coefficient approche 1 : la
 * régularité pèse, sans jamais cacher le CA/heure réel, affiché à côté.
 */
const VENTE_LISSAGE_HEURES = 20;

/**
 * Le coefficient de créneau : vendre l'après-midi ou en semaine est plus dur
 * que le samedi matin — la file fait le chiffre toute seule.
 *
 * La difficulté n'est pas DÉCRÉTÉE, elle est MESURÉE : le CA du réseau par
 * heure planifiée, sur quatre créneaux (matin/après-midi × semaine/week-end,
 * la bascule à 13 h). Une personne dont le planning porte surtout les
 * créneaux creux reçoit un coefficient > 1, une personne sur les rushs < 1 —
 * borné, pour qu'un mois entier ne se joue jamais sur le seul planning.
 */
const VENTE_CRENEAU_BASCULE = 13;        // heure de bascule matin → après-midi
const VENTE_COEF_CRENEAU_MIN = 0.8;
const VENTE_COEF_CRENEAU_MAX = 1.3;

/** Les colonnes candidates pour le vendeur, sur `transaction`. */
const VENTE_COLS_VENDEUR = ['id_user', 'user_id', 'id_employee', 'employee_id',
    'id_seller', 'seller_id', 'id_cashier', 'cashier_id', 'id_user_membership',
    'id_staff', 'staff_id', 'id_worker'];

/**
 * GET /ventes/sonde — ce que la base expose réellement.
 *
 * Écran de diagnostic, pas de pilotage : il dit quelle colonne porte le
 * vendeur, quelles tables ressemblent à un planning, et ce que chacune
 * contient. C'est lui qui décide de la suite — pas une supposition.
 */
function ep_ventes_sonde(): array
{
    $out = ['transaction' => [], 'candidatsVendeur' => [], 'tables' => [], 'users' => []];
    try {
        foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction'") as $r) {
            $v = is_array($r) ? (string) reset($r) : (string) $r;
            if ($v !== '') { $out['transaction'][] = $v; }
        }
        foreach (VENTE_COLS_VENDEUR as $c) {
            if (in_array($c, array_map('strtolower', $out['transaction']), true)) { $out['candidatsVendeur'][] = $c; }
        }
        foreach (Db::rows("SELECT TABLE_NAME nom, TABLE_ROWS lignes FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                              AND (TABLE_NAME LIKE '%plan%' OR TABLE_NAME LIKE '%shift%'
                                OR TABLE_NAME LIKE '%schedul%' OR TABLE_NAME LIKE '%hour%'
                                OR TABLE_NAME LIKE '%presence%' OR TABLE_NAME LIKE '%clock%'
                                OR TABLE_NAME LIKE '%pointage%' OR TABLE_NAME LIKE '%work%'
                                OR TABLE_NAME LIKE '%staff%' OR TABLE_NAME LIKE '%employee%'
                                OR TABLE_NAME LIKE '%hr\\_%')
                            ORDER BY TABLE_NAME") as $t) {
            $nom = (string) $t['nom'];
            $cols = [];
            foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$nom]) as $r) {
                $v = is_array($r) ? (string) reset($r) : (string) $r;
                if ($v !== '') { $cols[] = $v; }
            }
            $out['tables'][] = ['nom' => $nom, 'lignes' => (int) ($t['lignes'] ?? 0), 'colonnes' => $cols];
        }
        foreach (Db::rows("SELECT TABLE_NAME nom, TABLE_ROWS lignes FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%user%'
                            ORDER BY TABLE_NAME") as $t) {
            $out['users'][] = ['nom' => (string) $t['nom'], 'lignes' => (int) ($t['lignes'] ?? 0)];
        }
    } catch (Throwable $e) {
        $out['erreur'] = $e->getMessage();
    }
    return $out;
}

/** `2026-07` → bornes SQL du mois. */
function venteBornes(string $m): array
{
    $t = strtotime($m . '-01');
    return [date('Y-m-01 00:00:00', $t), date('Y-m-01 00:00:00', strtotime('+1 month', $t))];
}

/** Le personnel de vente, id → identité. */
function venteEmployes(): array
{
    $out = [];
    foreach (Db::rows('SELECT e.id, e.id_shop, e.display_name, e.name, e.surname
                         FROM franchisee_employee e') as $r) {
        $nom = trim((string) ($r['display_name'] ?? ''));
        if ($nom === '') { $nom = trim(((string) ($r['name'] ?? '')) . ' ' . ((string) ($r['surname'] ?? ''))); }
        $out[(int) $r['id']] = ['nom' => $nom !== '' ? $nom : 'Employé ' . $r['id'],
            'shop' => (string) $r['id_shop']];
    }
    return $out;
}

/**
 * Le classement d'UN mois : une ligne par personne, toutes les mesures.
 *
 * @return array{lignes:list<array<string,mixed>>,sansVendeur:array,motif:?string}
 */
function venteMois(string $m, array $nomDe): array
{
    [$du, $au] = venteBornes($m);
    $emp = venteEmployes();

    // Les heures du planning — la SEULE base de comparaison. start/end sont
    // des heures du jour : la différence se prend modulo 24 h, un service qui
    // passe minuit ne devient pas négatif. Chaque service est aussi VENTILÉ
    // sur quatre créneaux (la bascule à 13 h, le week-end au samedi-dimanche) ;
    // un service qui passe minuit n'est pas ventilé — trop rare pour mériter
    // une arithmétique qui se lirait mal.
    $bascule = VENTE_CRENEAU_BASCULE * 3600;
    $matin = 'GREATEST(0, LEAST(TIME_TO_SEC(end_hour), ' . $bascule . ') - LEAST(TIME_TO_SEC(start_hour), ' . $bascule . '))';
    $duree = 'GREATEST(TIME_TO_SEC(end_hour) - TIME_TO_SEC(start_hour), 0)';
    $heures = []; $creneauxEmp = [];
    try {
        foreach (Db::rows('SELECT id_employee,
                                  SUM((TIME_TO_SEC(end_hour) - TIME_TO_SEC(start_hour) + 86400) % 86400) / 3600 h,
                                  SUM(CASE WHEN WEEKDAY(work_date) < 5 THEN ' . $matin . ' ELSE 0 END) / 3600 matSem,
                                  SUM(CASE WHEN WEEKDAY(work_date) < 5 THEN ' . $duree . ' - ' . $matin . ' ELSE 0 END) / 3600 amSem,
                                  SUM(CASE WHEN WEEKDAY(work_date) >= 5 THEN ' . $matin . ' ELSE 0 END) / 3600 matWe,
                                  SUM(CASE WHEN WEEKDAY(work_date) >= 5 THEN ' . $duree . ' - ' . $matin . ' ELSE 0 END) / 3600 amWe
                             FROM franchisee_employee_schedule
                            WHERE work_date >= ? AND work_date < ?
                            GROUP BY id_employee', [substr($du, 0, 10), substr($au, 0, 10)]) as $r) {
            $id = (int) $r['id_employee'];
            $heures[$id] = (float) $r['h'];
            $creneauxEmp[$id] = ['matSem' => (float) $r['matSem'], 'amSem' => (float) $r['amSem'],
                'matWe' => (float) $r['matWe'], 'amWe' => (float) $r['amWe']];
        }
    } catch (PDOException $e) {
        return ['lignes' => [], 'sansVendeur' => [], 'motif' => 'le planning du panel n’a pas pu être lu : ' . $e->getMessage()];
    }

    // La difficulté MESURÉE de chaque créneau : le CA du réseau ÷ les heures
    // planifiées du réseau, créneau par créneau.
    $caSeg = ['matSem' => 0.0, 'amSem' => 0.0, 'matWe' => 0.0, 'amWe' => 0.0];
    try {
        foreach (Db::rows('SELECT (WEEKDAY(DATE(insert_timestamp)) >= 5) we,
                                  (HOUR(insert_timestamp) >= ' . VENTE_CRENEAU_BASCULE . ') am,
                                  SUM(total_gross_amount_after_discount) ca
                             FROM `transaction`
                            WHERE insert_timestamp >= ? AND insert_timestamp < ?
                            GROUP BY we, am', [$du, $au]) as $r) {
            $cle = ((int) $r['we'] ? ($r['am'] ? 'amWe' : 'matWe') : ((int) $r['am'] ? 'amSem' : 'matSem'));
            $caSeg[$cle] = (float) $r['ca'];
        }
    } catch (PDOException $e) { /* sans ventilation : coefficient neutre partout */ }
    $hSeg = ['matSem' => 0.0, 'amSem' => 0.0, 'matWe' => 0.0, 'amWe' => 0.0];
    foreach ($creneauxEmp as $c2) { foreach ($hSeg as $k => $v) { $hSeg[$k] += $c2[$k]; } }
    $intens = [];
    $caTous = array_sum($caSeg); $hTous = array_sum($hSeg);
    $iGlobal = $hTous > 0 ? $caTous / $hTous : 0.0;
    foreach ($hSeg as $k => $h2) { $intens[$k] = $h2 > 1 ? $caSeg[$k] / $h2 : null; }

    // Les ventes, par vendeur.
    $ventes = []; $sans = ['tickets' => 0, 'ca' => 0.0];
    try {
        foreach (Db::rows('SELECT id_employee, COUNT(DISTINCT ticket_key) tickets,
                                  SUM(total_gross_amount_after_discount) ca
                             FROM `transaction`
                            WHERE insert_timestamp >= ? AND insert_timestamp < ?
                            GROUP BY id_employee', [$du, $au]) as $r) {
            if ($r['id_employee'] === null) {
                $sans = ['tickets' => (int) $r['tickets'], 'ca' => (float) $r['ca']];
                continue;
            }
            $ventes[(int) $r['id_employee']] = ['tickets' => (int) $r['tickets'], 'ca' => (float) $r['ca'], 'lignes' => 0];
        }
        foreach (Db::rows('SELECT t.id_employee, COUNT(*) lignes
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                              AND t.id_employee IS NOT NULL
                            GROUP BY t.id_employee', [$du, $au]) as $r) {
            $id = (int) $r['id_employee'];
            if (isset($ventes[$id])) { $ventes[$id]['lignes'] = (int) $r['lignes']; }
        }
    } catch (PDOException $e) {
        return ['lignes' => [], 'sansVendeur' => [], 'motif' => 'lecture des tickets impossible'];
    }

    $lignes = [];
    foreach ($emp as $id => $e) {
        $v = $ventes[$id] ?? null;
        $h = $heures[$id] ?? 0.0;
        if ($v === null && $h <= 0) { continue; }   // ni vente ni planning : pas ce mois-ci
        $ca = $v['ca'] ?? 0.0;
        $tickets = $v['tickets'] ?? 0;
        $classable = $h > 0 && $h >= VENTE_SEUIL_HEURES && $ca > 0;
        $lisse = $h + VENTE_LISSAGE_HEURES;
        // Le créneau : à quel « débit réseau » cette personne était-elle
        // exposée ? Son attendu = la moyenne des intensités de SES créneaux,
        // pondérée par ses heures. Coefficient = intensité moyenne réseau ÷
        // son attendu — > 1 sur les créneaux creux, < 1 sur les rushs, borné.
        $c2 = $creneauxEmp[$id] ?? null;
        $coefCre = 1.0; $partAm = null; $partWe = null;
        if ($c2 !== null && $iGlobal > 0) {
            $hVent = array_sum($c2);
            if ($hVent > 0.5) {
                $attendu = 0.0; $hPris = 0.0;
                foreach ($c2 as $k => $h3) {
                    if ($h3 <= 0 || $intens[$k] === null) { continue; }
                    $attendu += $h3 * $intens[$k]; $hPris += $h3;
                }
                if ($hPris > 0 && $attendu > 0) {
                    $coefCre = max(VENTE_COEF_CRENEAU_MIN, min(VENTE_COEF_CRENEAU_MAX, $iGlobal / ($attendu / $hPris)));
                }
                $partAm = round(100 * ($c2['amSem'] + $c2['amWe']) / $hVent);
                $partWe = round(100 * ($c2['matWe'] + $c2['amWe']) / $hVent);
            }
        }
        $lignes[] = [
            'id' => $id, 'nom' => $e['nom'],
            'shopId' => $e['shop'], 'magasin' => $nomDe[$e['shop']] ?? ('Magasin ' . $e['shop']),
            'heures' => round($h, 1),
            'ca' => (int) round($ca),
            'tickets' => $tickets,
            'caHeure' => $classable ? (int) round($ca / $h) : null,
            'coef' => $classable ? round($h / $lisse, 2) : null,
            'coefCreneau' => $classable ? round($coefCre, 2) : null,
            'partAm' => $partAm, 'partWe' => $partWe,
            'score' => $classable ? (int) round($ca / $lisse * $coefCre) : null,
            'panier' => $tickets > 0 ? round($ca / $tickets, 2) : null,
            'lignesTicket' => $tickets > 0 ? round(($v['lignes'] ?? 0) / $tickets, 1) : null,
            'classable' => $classable,
            'motifHorsClassement' => $classable ? null
                : ($ca <= 0 ? 'aucune vente à son nom'
                    : ($h <= 0 ? 'aucune heure au planning'
                        : 'moins de ' . VENTE_SEUIL_HEURES . ' h au planning')),
        ];
    }
    // Les classables d'abord, au CA / heure ; les autres suivent, montrés
    // mais jamais classés.
    usort($lignes, static fn ($a, $b) =>
        ($b['classable'] <=> $a['classable'])
        ?: (($b['score'] ?? 0) <=> ($a['score'] ?? 0))
        ?: ($b['ca'] <=> $a['ca']));
    $rang = 0;
    foreach ($lignes as $i => $l) {
        $lignes[$i]['rang'] = $l['classable'] ? ++$rang : null;
    }
    return ['lignes' => $lignes, 'sansVendeur' => $sans, 'motif' => null,
        'creneaux' => ['matSem' => $intens['matSem'] !== null ? (int) round($intens['matSem']) : null,
            'amSem' => $intens['amSem'] !== null ? (int) round($intens['amSem']) : null,
            'matWe' => $intens['matWe'] !== null ? (int) round($intens['matWe']) : null,
            'amWe' => $intens['amWe'] !== null ? (int) round($intens['amWe']) : null,
            'global' => (int) round($iGlobal)]];
}

/** Les montants de prime, réglables. */
function ventePrimesConfig(): array
{
    $c = setting('ventePrimes');
    return ['reseau' => (int) ($c['reseau'] ?? 150), 'magasin' => (int) ($c['magasin'] ?? 75)];
}

/** Les gagnantes d'un classement : la première du réseau, puis de chaque magasin. */
function venteGagnantes(array $lignes): array
{
    $reseau = null; $parShop = [];
    foreach ($lignes as $l) {
        if (!$l['classable']) { continue; }
        if ($reseau === null) { $reseau = $l; }
        if (!isset($parShop[$l['shopId']])) { $parShop[$l['shopId']] = $l; }
    }
    return ['reseau' => $reseau, 'magasins' => array_values($parShop)];
}

/**
 * GET /ventes/classement?m=2026-07
 *
 * Le mois par défaut est le DERNIER MOIS RÉVOLU : le mois en cours n'a ni
 * son planning complet ni toutes ses ventes, et primer sur un mois entamé
 * primerait le hasard du calendrier.
 */
function ep_ventes_classement(): array
{
    $m = trim((string) ($_GET['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m', strtotime('first day of last month')); }

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }

    // Les identifiants de magasins partent en CHAÎNES : les clés numériques
    // d'un tableau PHP redeviennent des entiers, et `'4' === 4` est faux —
    // le filtre de l'écran et les pages du PDF comparaient dans le vide.
    $out = ['m' => $m, 'seuil' => VENTE_SEUIL_HEURES, 'primes' => ventePrimesConfig(),
        'mois' => [], 'magasins' => array_map(static fn ($id, $n) => ['id' => (string) $id, 'nom' => $n],
            array_keys($nomDe), $nomDe)];
    for ($i = 5; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $out['mois'][] = ['cle' => date('Y-m', $t), 'lib' => strftime_fr($t, 'M Y'),
            'encours' => date('Y-m', $t) === date('Y-m')];
    }

    $r = venteMois($m, $nomDe);
    $out['lignes'] = $r['lignes'];
    $out['sansVendeur'] = $r['sansVendeur'];
    $out['creneaux'] = $r['creneaux'] ?? null;
    $out['motif'] = $r['motif'];
    if ($r['motif'] === null) {
        $out['gagnantes'] = venteGagnantes($r['lignes']);
        $caTot = array_sum(array_column($r['lignes'], 'ca')) + $r['sansVendeur']['ca'];
        $out['partSansVendeur'] = $caTot > 0 ? round(100 * $r['sansVendeur']['ca'] / $caTot, 1) : null;
        // La prime déjà enregistrée pour ce mois, si elle l'est.
        $hist = setting('ventePrimesHist');
        $out['primeEnregistree'] = is_array($hist) && isset($hist[$m]) ? $hist[$m] : null;
    }
    return $out;
}

/**
 * GET /ventes/fiche?id=12&n=6 — une personne, mois par mois.
 */
function ep_ventes_fiche(): array
{
    $id = (int) ($_GET['id'] ?? 0);
    $n = max(2, min(12, (int) ($_GET['n'] ?? 6)));
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    $emp = venteEmployes();
    if (!isset($emp[$id])) { http_response_code(404); return ['error' => 'personne inconnue']; }

    $hist = setting('ventePrimesHist');
    $out = ['id' => $id, 'nom' => $emp[$id]['nom'],
        'magasin' => $nomDe[$emp[$id]['shop']] ?? '', 'mois' => []];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $m = date('Y-m', $t);
        $r = venteMois($m, $nomDe);
        if ($r['motif'] !== null) { continue; }
        foreach ($r['lignes'] as $l) {
            if ($l['id'] !== $id) { continue; }
            $prime = null;
            if (is_array($hist) && isset($hist[$m])) {
                if ((int) ($hist[$m]['reseau']['id'] ?? 0) === $id) { $prime = 'réseau · ' . $hist[$m]['montants']['reseau'] . ' €'; }
                else {
                    foreach ($hist[$m]['magasins'] ?? [] as $g) {
                        if ((int) ($g['id'] ?? 0) === $id) { $prime = 'magasin · ' . $hist[$m]['montants']['magasin'] . ' €'; }
                    }
                }
            }
            $classables = count(array_filter($r['lignes'], static fn ($x) => $x['classable']));
            $out['mois'][] = ['cle' => $m, 'lib' => strftime_fr($t, 'M Y'),
                'heures' => $l['heures'], 'ca' => $l['ca'], 'caHeure' => $l['caHeure'],
                'coef' => $l['coef'] ?? null, 'score' => $l['score'] ?? null,
                'panier' => $l['panier'], 'lignesTicket' => $l['lignesTicket'],
                'rang' => $l['rang'], 'sur' => $classables, 'prime' => $prime,
                'encours' => $m === date('Y-m')];
        }
    }
    return $out;
}

/**
 * POST /ventes/primes {m: "2026-07"} — enregistre les primes du mois.
 *
 * Le calcul désigne, l'humain confirme : rien ne se prime tout seul. Un mois
 * déjà primé ne se re-prime pas — la prime est un engagement, pas un état
 * recalculable.
 */
function wr_ventes_primes(): array
{
    $b = body();
    $m = trim((string) ($b['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { http_response_code(422); return ['error' => 'mois attendu : AAAA-MM']; }
    if ($m >= date('Y-m')) { http_response_code(422); return ['error' => 'le mois en cours ne se prime pas : il n’est pas fini']; }

    $hist = setting('ventePrimesHist');
    if (!is_array($hist)) { $hist = []; }
    if (isset($hist[$m])) { http_response_code(409); return ['error' => 'les primes de ' . $m . ' sont déjà enregistrées', 'primes' => $hist[$m]]; }

    if (isset($b['montants']) && is_array($b['montants'])) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['ventePrimes', json_encode(['reseau' => (int) ($b['montants']['reseau'] ?? 150),
                'magasin' => (int) ($b['montants']['magasin'] ?? 75)])]);
    }
    $montants = ventePrimesConfig();

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }
    $r = venteMois($m, $nomDe);
    if ($r['motif'] !== null) { http_response_code(503); return ['error' => $r['motif']]; }
    $g = venteGagnantes($r['lignes']);
    if ($g['reseau'] === null) { http_response_code(422); return ['error' => 'aucune vendeuse classable sur ' . $m]; }

    $enr = ['m' => $m, 'quand' => date('Y-m-d H:i'), 'montants' => $montants,
        'reseau' => ['id' => $g['reseau']['id'], 'nom' => $g['reseau']['nom'],
            'magasin' => $g['reseau']['magasin'], 'caHeure' => $g['reseau']['caHeure']],
        'magasins' => []];
    journalAdd('CEO', 'Vente', $g['reseau']['nom'],
        'Prime réseau ' . $m . ' — ' . $montants['reseau'] . ' € (score ' . ($g['reseau']['score'] ?? '?')
        . ', ' . $g['reseau']['caHeure'] . ' €/h sur ' . $g['reseau']['heures'] . ' h, ' . $g['reseau']['magasin'] . ')');
    foreach ($g['magasins'] as $x) {
        // La meilleure du réseau ne cumule pas : sa prime magasin irait à un
        // classement qu'elle a déjà gagné plus haut.
        if ($x['id'] === $g['reseau']['id']) { continue; }
        $enr['magasins'][] = ['id' => $x['id'], 'nom' => $x['nom'], 'magasin' => $x['magasin'], 'caHeure' => $x['caHeure']];
        journalAdd('CEO', 'Vente', $x['nom'],
            'Prime magasin ' . $m . ' — ' . $montants['magasin'] . ' € (' . $x['caHeure'] . ' €/h, ' . $x['magasin'] . ')');
    }
    $hist[$m] = $enr;
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['ventePrimesHist', json_encode($hist, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'primes' => $enr];
}

/**
 * Le croisement Flip & Flap × boissons : l'attache, ticket par ticket.
 *
 * Le cross-selling global (lignes par ticket) dit qu'on vend plusieurs
 * choses ; il ne dit pas LE geste qu'on veut coacher — « un Flip & Flap ?
 * proposez la boisson ». Ici : sur les tickets contenant un Flip & Flap,
 * la part qui contient AUSSI une boisson, par magasin et par personne, et
 * les euros laissés au comptoir (tickets sans boisson × prix moyen d'une
 * boisson réellement encaissé ce mois-là).
 *
 * @return array{magasins:array,vendeurs:array,prixBoisson:float,motif:?string}
 */
function venteCroisementFF(string $du, string $au, array $nomDe): array
{
    $ff = []; $boi = [];
    foreach (ep_prod_catalogue() as $pr) {
        $pid = $pr['pwaId'] ?? null;
        if ($pid === null) { continue; }
        if (stripos((string) ($pr['categorie'] ?? ''), 'flip') !== false) { $ff[] = (int) $pid; }
        if ((string) ($pr['groupe'] ?? '') === 'Boissons') { $boi[] = (int) $pid; }
    }
    if ($ff === [] || $boi === []) {
        return ['magasins' => [], 'vendeurs' => [], 'prixBoisson' => 0.0,
            'motif' => 'le catalogue ne porte pas la catégorie Flip & Flap ou le groupe Boissons'];
    }
    $marks = static fn (array $ids) => implode(',', array_map('intval', $ids));

    // Un passage par famille : les tickets qui contiennent l'une, l'autre —
    // l'intersection se fait en PHP, sur les identifiants de tickets.
    $ticketsFF = [];   // ticket => ['emp'=>, 'shop'=>]
    $avecBoisson = [];
    $caBoi = 0.0; $qBoi = 0.0;
    try {
        foreach (Db::rows('SELECT DISTINCT t.id, t.id_employee, t.id_shop
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                              AND l.id_product IN (' . $marks($ff) . ')', [$du, $au]) as $r) {
            $ticketsFF[(int) $r['id']] = ['emp' => $r['id_employee'] !== null ? (int) $r['id_employee'] : null,
                'shop' => (string) $r['id_shop']];
        }
        foreach (Db::rows('SELECT t.id, SUM(l.total_gross_value_after_discount) ca, SUM(l.`quantity`) q
                             FROM transaction_product l JOIN `transaction` t ON t.id = l.id_transaction
                            WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                              AND l.id_product IN (' . $marks($boi) . ')
                            GROUP BY t.id', [$du, $au]) as $r) {
            $avecBoisson[(int) $r['id']] = true;
            $caBoi += (float) $r['ca']; $qBoi += (float) $r['q'];
        }
    } catch (PDOException $e) {
        return ['magasins' => [], 'vendeurs' => [], 'prixBoisson' => 0.0,
            'motif' => 'lecture des tickets impossible'];
    }
    $prixBoisson = $qBoi > 0 ? $caBoi / $qBoi : 0.0;

    $emp = venteEmployes();
    $parShop = []; $parVend = [];
    foreach ($ticketsFF as $tid => $t) {
        $avec = isset($avecBoisson[$tid]) ? 1 : 0;
        $sid = $t['shop'];
        if (isset($nomDe[$sid])) {
            $parShop[$sid] = $parShop[$sid] ?? ['ff' => 0, 'avec' => 0];
            $parShop[$sid]['ff']++; $parShop[$sid]['avec'] += $avec;
        }
        if ($t['emp'] !== null && isset($emp[$t['emp']])) {
            $parVend[$t['emp']] = $parVend[$t['emp']] ?? ['ff' => 0, 'avec' => 0];
            $parVend[$t['emp']]['ff']++; $parVend[$t['emp']]['avec'] += $avec;
        }
    }
    $mags = [];
    foreach ($parShop as $sid => $x) {
        $mags[] = ['id' => (string) $sid, 'nom' => $nomDe[$sid],
            'ff' => $x['ff'], 'avec' => $x['avec'],
            'taux' => $x['ff'] > 0 ? round(100 * $x['avec'] / $x['ff'], 1) : null,
            'manques' => $x['ff'] - $x['avec'],
            'eur' => (int) round(($x['ff'] - $x['avec']) * $prixBoisson)];
    }
    usort($mags, static fn ($a, $b) => ($a['taux'] ?? 0) <=> ($b['taux'] ?? 0));
    $vends = [];
    foreach ($parVend as $id => $x) {
        $vends[] = ['id' => $id, 'nom' => $emp[$id]['nom'],
            'magasin' => $nomDe[$emp[$id]['shop']] ?? '', 'shopId' => (string) $emp[$id]['shop'],
            'ff' => $x['ff'], 'avec' => $x['avec'],
            'taux' => $x['ff'] > 0 ? round(100 * $x['avec'] / $x['ff'], 1) : null,
            'manques' => $x['ff'] - $x['avec'],
            'eur' => (int) round(($x['ff'] - $x['avec']) * $prixBoisson)];
    }
    usort($vends, static fn ($a, $b) => $b['ff'] <=> $a['ff']);
    return ['magasins' => $mags, 'vendeurs' => $vends,
        'prixBoisson' => round($prixBoisson, 2), 'motif' => null];
}

/**
 * GET /ventes/classement.pdf?m=2026-07 — le rapport mensuel, à afficher en
 * réserve à côté du planning.
 *
 * Même identité que la note de campagne et l'analyse magasin : le bandeau
 * logo, le filet bordeaux, la Georgia pour les grands chiffres, les cartes
 * crème. Page 1 : les primes et les trois top 10 — CA, CA/heure, lignes par
 * ticket. Page 2 : le classement complet, TOUTES les données. Puis une page
 * par magasin.
 */
function venteClotureDoc(string $mois = '', string $shop = ''): array
{
    // Le mois voyage par $_GET jusqu'à ep_ventes_classement — on le pose puis
    // on le repose, pour que le reporting automatisé passe par le même chemin
    // que l'écran sans dupliquer la lecture du paramètre.
    $mAvant = $_GET['m'] ?? null;
    if ($mois !== '') { $_GET['m'] = $mois; }
    $d = ep_ventes_classement();
    if ($mAvant === null) { unset($_GET['m']); } else { $_GET['m'] = $mAvant; }
    if (($d['motif'] ?? null) !== null) { return ['error' => $d['motif']]; }
    // `shop` réduit le rapport à UN magasin (sa clôture, ses combos face au
    // réseau, son équipe) : la version que le reporting automatisé envoie à
    // chaque gérant — `mois` choisit le mois, dernier révolu sinon.
    $seulShop = trim($shop);
    $garde = static fn ($sid) => $seulShop === '' || (string) $sid === $seulShop;
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $n1 = static fn ($v) => number_format((float) $v, 1, ',', ' ');
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $logo = rapLogoDataUri();
    $libMois = strftime_fr(strtotime($d['m'] . '-01'), 'M Y');
    $hist = $d['primeEnregistree'];
    $g = $d['gagnantes'];

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .h1{font-size:19pt;margin:4mm 0 1mm}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.or{color:#8a5a1c}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      .k{font-size:7pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a}
      .prime{border:1px solid #E8C9A0;background:#FFF9EC;border-radius:8px;padding:3mm 4mm;margin-bottom:4mm}
      .prime .qui{font-family:Georgia,"DejaVu Serif",serif;font-size:13pt;margin:1mm 0}
      .badge{display:inline-block;font-size:7pt;font-weight:bold;border-radius:3mm;padding:.6mm 2.4mm;background:#8D1D2C;color:#fff}
      .badge.or{background:#FFF3D6;color:#8a5a1c;border:1px solid #E8C9A0}
      table.t{width:100%;border-collapse:collapse;margin-bottom:5mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.3pt;text-align:right;padding:1.3mm 2mm;border-bottom:.5pt solid #EAE3D8}
      .t .l{text-align:left}
      .gris td{color:#9a9186}
      .rang{display:inline-block;width:4.4mm;height:4.4mm;border-radius:50%;background:#EFE3D5;color:#8a5a1c;font-size:6.6pt;font-weight:bold;text-align:center;line-height:4.4mm}
      .top td{font-size:8pt;text-align:left;padding:1.15mm 1mm;border-bottom:.5pt solid #EFE9DF}
      .top .v{text-align:right;font-weight:bold;white-space:nowrap}
      .top .s{text-align:right;color:#7a736a;font-size:6.8pt;white-space:nowrap}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6}
    </style>';

    $entete = static fn (string $droite) =>
        '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="" style="height:34px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Target de vente &amp; classement<br>' . $droite . '</td></tr></table>';

    // L'encadré de méthode, construit UNE fois et répété en pied de CHAQUE
    // page : une feuille magasin s'affiche seule en réserve, et un classement
    // dont la formule n'est pas sur la même feuille se conteste.
    $methode = '<div class="methode" style="margin-top:2mm"><b style="color:#221E1A">Comment lire.</b> Le classement se fait au <b>score</b> : '
        . 'CA ÷ (heures + ' . VENTE_LISSAGE_HEURES . ') × coefficient de créneau. '
        . 'Le coefficient d’heures — heures ÷ (heures + ' . VENTE_LISSAGE_HEURES . ') — monte avec les heures prestées : la régularité pèse, cinq bonnes heures ne battent pas un mois entier. '
        . 'Le coefficient de créneau paie ceux qui vendent quand c’est difficile, et la difficulté est MESURÉE, pas décrétée — le CA du réseau par heure planifiée ce mois-ci : '
        . ($d['creneaux'] !== null ? 'matin semaine ' . $eur($d['creneaux']['matSem']) . '/h · après-midi semaine ' . $eur($d['creneaux']['amSem'])
            . '/h · matin week-end ' . $eur($d['creneaux']['matWe']) . '/h · après-midi week-end ' . $eur($d['creneaux']['amWe']) . '/h' : '')
        . ' — borné entre ' . number_format(VENTE_COEF_CRENEAU_MIN, 2, ',', ' ') . ' et ' . number_format(VENTE_COEF_CRENEAU_MAX, 2, ',', ' ') . '. '
        . 'Le CA/heure réel reste affiché. Sans heure au planning ou sans vente à son nom : montré(e), jamais classé(e) ni primé(e). '
        . 'Panier = CA ÷ tickets · vente complémentaire = lignes par ticket (30 tickets au moins pour le top 10). '
        . ($d['partSansVendeur'] !== null && $d['partSansVendeur'] > 0
            ? $d['partSansVendeur'] . ' % du CA du mois est encaissé sans vendeur identifié sur le ticket : cette part n’est attribuée à personne. '
            : '')
        . 'La meilleure du réseau ne cumule pas la prime magasin.</div>';

    // --- Page 1 : les primes, puis les trois top 10.
    $classables = array_values(array_filter($d['lignes'], static fn ($l) => $l['classable']));
    $h = $css . '<div class="doc">' . $entete($e($libMois))
        . '<div class="serif h1">Le mois de vente — ' . $e($libMois) . '</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:5mm">' . count($classables) . ' classé(e)s sur '
        . count($d['lignes']) . ' · ' . count($d['magasins']) . ' magasins · classement au CA par heure prestée (planning du panel)</div>';

    if (($g['reseau'] ?? null) !== null) {
        $r = $g['reseau'];
        $h .= '<div class="prime"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td><div class="k">🏆 Prime réseau — ' . (int) $d['primes']['reseau'] . ' €'
            . ($hist !== null ? ' · enregistrée le ' . $e($hist['quand']) : ' · à enregistrer dans le cockpit') . '</div>'
            . '<div class="qui">' . $e($r['nom']) . ' <span class="badge">réseau</span></div>'
            . '<div style="font-size:8pt;color:#7a736a">' . $e($court($r['magasin'])) . ' · <b class="acc">score ' . $eur($r['score'] ?? 0) . '</b> · ' . $eur($r['caHeure']) . ' / h réel'
            . ' · panier ' . number_format((float) $r['panier'], 2, ',', ' ') . ' € · ' . $n1($r['lignesTicket'])
            . ' lignes/ticket · ' . $n1($r['heures']) . ' h</div></td>'
            . '<td align="right" valign="top"><div class="k">🥇 Primes magasin — ' . (int) $d['primes']['magasin'] . ' €</div>'
            . '<div style="font-size:8.2pt;line-height:1.7;margin-top:1mm">';
        foreach ($g['magasins'] as $x) {
            if ($x['id'] === $r['id']) { continue; }
            $h .= '<b>' . $e($x['nom']) . '</b> <span class="mut">· ' . $e($court($x['magasin'])) . ' · score ' . $eur($x['score'] ?? 0) . '</span><br>';
        }
        $h .= '</div></td></tr></table></div>';
    }

    // Les trois top 10, côte à côte — les mêmes règles que l'écran.
    $actifs = array_values(array_filter($d['lignes'], static fn ($l) => ($l['tickets'] ?? 0) > 0));
    $parCa = $actifs; usort($parCa, static fn ($a, $b) => $b['ca'] <=> $a['ca']);
    $parCaH = array_values(array_filter($actifs, static fn ($l) => $l['score'] !== null));
    usort($parCaH, static fn ($a, $b) => $b['score'] <=> $a['score']);
    $parLt = array_values(array_filter($actifs, static fn ($l) => $l['lignesTicket'] !== null && $l['tickets'] >= 30));
    usort($parLt, static fn ($a, $b) => $b['lignesTicket'] <=> $a['lignesTicket']);

    $colonne = static function (string $titre, string $note, array $liste, callable $val, callable $sub) use ($e, $court): string {
        $h2 = '<td width="33%" valign="top" class="tile"><div class="k">' . $titre . '</div>'
            . '<div style="font-size:6.8pt;color:#7a736a;margin:.6mm 0 1.6mm">' . $note . '</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" class="top">';
        foreach (array_slice($liste, 0, 10) as $i => $l) {
            $h2 .= '<tr><td width="16"><span class="rang">' . ($i + 1) . '</span></td>'
                . '<td><b>' . $e($l['nom']) . '</b> <span style="color:#7a736a;font-size:6.8pt">' . $e($court($l['magasin'])) . '</span></td>'
                . '<td class="v' . ($i === 0 ? ' acc' : '') . '">' . $val($l) . '</td>'
                . '<td class="s">' . $sub($l) . '</td></tr>';
        }
        return $h2 . '</table></td>';
    };
    $h .= '<div class="sec">Les trois lectures du mois</div>'
        . '<table width="100%" cellpadding="0" cellspacing="4" style="margin:0 -1mm 4mm"><tr>'
        . $colonne('Top 10 — CA', 'le volume, brut', $parCa,
            static fn ($l) => $eur($l['ca']), static fn ($l) => $n1($l['heures']) . ' h')
        . $colonne('Top 10 — CA/h pondéré', 'CA/h × coefficient d’heures — la mesure des primes', $parCaH,
            static fn ($l) => $eur($l['score']), static fn ($l) => $eur($l['caHeure']) . '/h · ' . $n1($l['heures']) . ' h')
        . $colonne('Top 10 — Lignes / ticket', 'la vente complémentaire — 30 tickets au moins', $parLt,
            static fn ($l) => $n1($l['lignesTicket']), static fn ($l) => $l['tickets'] . ' tkt')
        . '</tr></table>' . $methode;

    // --- Page 2 : les COMBOS du mois — les taux et les écarts, sans un euro :
    // cette page-là parle du geste, pas de l'argent.
    $combosRows = [];
    try { $combosRows = Db::rows('SELECT * FROM ceo_combo ORDER BY a_lib, b_lib, dp'); } catch (Throwable $eC) {}
    if ($combosRows !== []) {
        $nomDeC = [];
        foreach ($d['magasins'] as $magC) { $nomDeC[(string) $magC['id']] = $magC['nom']; }
        $h .= '<div style="page-break-before:always">' . $entete($e($libMois))
            . '<div class="serif h1">Les combos du mois</div>'
            . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Taux d’attache = tickets contenant A qui contiennent aussi B'
            . ($seulShop !== '' ? ' — ce magasin face au réseau.' : ' — réseau et magasin par magasin.') . '</div>';
        foreach ($combosRows as $cb2) {
            $aC = croisIds((string) $cb2['a_sel']); $bC = croisIds((string) $cb2['b_sel']);
            if ($aC === null || $bC === null) { continue; }
            $dpC = croisDaypart((string) ($cb2['dp'] ?? ''));
            $cC = croisMoisServi((string) $cb2['a_sel'], (string) $cb2['b_sel'], $aC['ids'], $bC['ids'], $d['m'], $nomDeC, $dpC['cle']);
            if ($cC === null) { continue; }
            $tC = isset($cb2['target']) && $cb2['target'] !== null ? (float) $cb2['target'] : null;
            $ffR = 0; $avR = 0;
            foreach ($nomDeC as $sidC => $nC) {
                $xC = $cC['shops'][$sidC] ?? $cC['shops'][(string) $sidC] ?? ['ff' => 0, 'avec' => 0];
                $ffR += $xC['ff']; $avR += $xC['avec'];
            }
            $rangsC = [];
            if ($seulShop !== '') {
                $xC = $cC['shops'][$seulShop] ?? $cC['shops'][(int) $seulShop] ?? ['ff' => 0, 'avec' => 0];
                $rangsC[] = [$court($nomDeC[$seulShop] ?? ''), $xC['ff'], $xC['avec'], true];
                $rangsC[] = ['RÉSEAU', $ffR, $avR, false];
            } else {
                $rangsC[] = ['RÉSEAU', $ffR, $avR, true];
                foreach ($nomDeC as $sidC => $nC) {
                    $xC = $cC['shops'][$sidC] ?? $cC['shops'][(string) $sidC] ?? ['ff' => 0, 'avec' => 0];
                    $rangsC[] = [$court($nC), $xC['ff'], $xC['avec'], false];
                }
            }
            $h .= '<div style="page-break-inside:avoid;margin-bottom:4mm">'
                . '<div style="font-family:Georgia,\'DejaVu Serif\',serif;font-size:11pt;border-bottom:1.2pt solid #8D1D2C;padding-bottom:1.2mm;margin-bottom:1.5mm">'
                . $e($cb2['a_lib'] . ' × ' . $cb2['b_lib'])
                . ' <span class="mut" style="font-size:7.5pt;font-family:Helvetica,Arial,sans-serif">· '
                . ($dpC['lib'] !== '' ? $e($dpC['lib']) : 'toute la journée')
                . ($tC !== null ? ' · target ' . number_format($tC, 1, ',', ' ') . ' %' : '') . '</span></div>'
                . '<table class="t" cellpadding="0" cellspacing="0"><tr>'
                . '<th class="l">Périmètre</th><th>Tickets A</th><th>Avec B</th><th>Taux</th><th>Δ target</th></tr>';
            foreach ($rangsC as [$nomR, $ffX, $avX, $grasX]) {
                $tauxX = $ffX > 0 ? round(100 * $avX / $ffX, 1) : null;
                $dX = ($tC !== null && $tauxX !== null) ? $tauxX - $tC : null;
                $h .= '<tr' . ($grasX ? ' style="background:#fbf9f5;font-weight:bold"' : '') . '>'
                    . '<td class="l">' . $e($nomR) . '</td>'
                    . '<td>' . number_format($ffX, 0, ',', ' ') . '</td>'
                    . '<td>' . number_format($avX, 0, ',', ' ') . '</td>'
                    . '<td style="font-weight:bold">' . ($tauxX !== null ? number_format($tauxX, 1, ',', ' ') . ' %' : '') . '</td>'
                    . '<td style="font-weight:bold;color:' . ($dX === null ? '#7a736a' : ($dX >= 0 ? '#2d7a3e' : '#8D1D2C')) . '">'
                    . ($dX === null ? '' : ($dX >= 0 ? '+ ' : '− ') . number_format(abs($dX), 1, ',', ' ') . ' pt') . '</td></tr>';
            }
            $h .= '</table></div>';
        }
        $h .= '</div>';
    }

    // --- La page de CLÔTURE : qui a gagné quoi, toutes primes confondues.
    // C'est la feuille qu'on imprime en fin de mois — le score, le geste
    // personnel, l'équipe, et le total que la marque verse.
    $reglagesR = venteRecordReglages();
    $nomDeC2 = [];
    foreach ($d['magasins'] as $magN) { $nomDeC2[(string) $magN['id']] = (string) $magN['nom']; }
    // La fenêtre des records du mois clôturé.
    $parMoisR = [$d['m'] => $d['lignes']];
    foreach (venteFenetreRecord($d['m']) as $mF) {
        $rF = venteMois($mF, $nomDeC2);
        $parMoisR[$mF] = $rF['motif'] === null ? $rF['lignes'] : null;
    }
    $histCross = setting('ventePrimesCrossHist');
    $histCrossM = is_array($histCross) && isset($histCross[$d['m']]) ? $histCross[$d['m']] : null;
    $g2 = $d['gagnantes'];
    $totalPrimes = 0;
    $hClot = '<div style="page-break-before:always">' . $entete($e($libMois))
        . '<div class="serif h1">La clôture — qui a gagné quoi</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Toutes les primes de ' . $e($libMois) . ' — '
        . ($hist !== null || $histCrossM !== null ? 'enregistrées au journal.' : 'désignées par le calcul, à enregistrer dans le cockpit.') . '</div>';

    // 1. le score
    $hClot .= '<div class="sec">Au score — les meilleures vendeuses</div><table class="t" cellpadding="0" cellspacing="0">'
        . '<tr><th class="l">Prime</th><th class="l">Gagnante</th><th class="l">Magasin</th><th>Score</th><th>CA / h réel</th><th>Montant</th></tr>';
    if (($g2['reseau'] ?? null) !== null) {
        // La ligne réseau s'affiche toujours — c'est le « rapport au réseau » —
        // mais son montant n'entre dans le total d'un magasin que s'il y dort.
        $r2 = $g2['reseau'];
        if ($garde($r2['shopId'] ?? '')) { $totalPrimes += (int) $d['primes']['reseau']; }
        $hClot .= '<tr><td class="l or"><b>🏆 Réseau</b></td><td class="l"><b>' . $e($r2['nom']) . '</b></td>'
            . '<td class="l mut">' . $e($court($r2['magasin'])) . '</td><td>' . (int) $r2['score'] . '</td>'
            . '<td>' . $eur($r2['caHeure']) . '</td><td class="acc"><b>' . $eur($d['primes']['reseau']) . '</b></td></tr>';
        foreach ($g2['magasins'] as $x2) {
            if ($x2['id'] === $r2['id']) { continue; }
            if (!$garde($x2['shopId'] ?? '')) { continue; }
            $totalPrimes += (int) $d['primes']['magasin'];
            $hClot .= '<tr><td class="l or">🥇 Magasin</td><td class="l"><b>' . $e($x2['nom']) . '</b></td>'
                . '<td class="l mut">' . $e($court($x2['magasin'])) . '</td><td>' . (int) $x2['score'] . '</td>'
                . '<td>' . $eur($x2['caHeure']) . '</td><td class="acc"><b>' . $eur($d['primes']['magasin']) . '</b></td></tr>';
        }
    } else { $hClot .= '<tr><td class="l mut" colspan="6">aucune classable ce mois-ci</td></tr>'; }
    $hClot .= '</table>';

    // 2. BATS TON RECORD — chacune face à son propre record.
    $hClot .= '<div class="sec">Bats ton record — ' . $reglagesR['eurDixieme'] . ' € par dixième dès le deuxième au-dessus du record (12 mois glissants, plafonné à ' . $reglagesR['maxDixiemes'] . ' tranches)</div>'
        . '<table class="t" cellpadding="0" cellspacing="0"><tr><th class="l">Magasin</th><th class="l">Records battus</th><th>Total</th></tr>';
    $parShopR = [];
    foreach ($d['lignes'] as $l2) {
        if (($l2['tickets'] ?? 0) < VENTE_CROSS_MIN_TICKETS || $l2['lignesTicket'] === null) { continue; }
        $recV2 = venteRecordVendeuse($parMoisR, (string) $l2['id'], $d['m']);
        $prV2 = venteRecordPrime((float) $l2['lignesTicket'], $recV2, $reglagesR['eurDixieme'], $reglagesR['maxDixiemes']);
        if ($prV2['prime'] === 0) { continue; }
        $parShopR[(string) $l2['shopId']][] = ['l' => $l2, 'rec' => $recV2, 'prime' => $prV2['prime']];
    }
    foreach ($d['magasins'] as $mag2) {
        if (!$garde($mag2['id'])) { continue; }
        $siens2 = $parShopR[(string) $mag2['id']] ?? [];
        $totR = array_sum(array_column($siens2, 'prime'));
        $totalPrimes += $totR;
        $hClot .= '<tr><td class="l"><b>' . $e($court($mag2['nom'])) . '</b></td>'
            . '<td class="l" style="font-size:7.8pt">' . ($siens2 === [] ? '<span class="mut">aucun record battu ce mois-ci</span>'
                : implode(' · ', array_map(static fn ($x4) => $e($x4['l']['nom'])
                    . ' (' . ($x4['rec'] !== null ? number_format($x4['rec'], 2, ',', ' ') . ' → ' : '')
                    . number_format((float) $x4['l']['lignesTicket'], 2, ',', ' ') . ' = ' . $x4['prime'] . ' €)', $siens2))) . '</td>'
            . '<td class="acc"><b>' . ($totR > 0 ? $eur($totR) : '') . '</b></td></tr>';
    }
    $hClot .= '</table>'
        . '<div class="prime" style="text-align:center"><span class="k">Total des primes du mois' . ($seulShop !== '' ? ' — ce magasin' : '') . '</span>'
        . '<div class="serif" style="font-size:24pt;color:#8D1D2C;margin-top:1mm">' . $eur($totalPrimes) . '</div>'
        . '<div class="regle" style="font-size:7.6pt;color:#7a736a">payés en bons par la marque — jamais par le magasin. Chaque prime est au journal du cockpit, avec son motif et sa formule.</div></div>'
        . '</div>';
    $h .= $hClot;

    // --- Page suivante : le classement complet.
    $tableau = static function (array $lignes, bool $avecMagasin) use ($e, $eur, $n1, $court, $hist): string {
        $h2 = '<table class="t" cellpadding="0" cellspacing="0"><tr><th class="l">#</th><th class="l">Vendeur·se</th>'
            . ($avecMagasin ? '<th class="l">Magasin</th>' : '')
            . '<th>Heures</th><th>CA</th><th>CA / h</th><th>Coef. h</th><th>Coef. crén.</th><th>Score</th><th>Panier</th><th>Lignes / tkt</th><th>Tickets</th><th class="l">Prime</th></tr>';
        foreach ($lignes as $l) {
            $prime = '';
            if ($hist !== null) {
                if ((int) ($hist['reseau']['id'] ?? 0) === $l['id']) { $prime = 'réseau · ' . $hist['montants']['reseau'] . ' €'; }
                else { foreach ($hist['magasins'] ?? [] as $g2) { if ((int) $g2['id'] === $l['id']) { $prime = 'magasin · ' . $hist['montants']['magasin'] . ' €'; } } }
            }
            $h2 .= '<tr' . ($l['classable'] ? '' : ' class="gris"') . '>'
                . '<td class="l">' . ($l['rang'] !== null ? (int) $l['rang'] : '') . '</td>'
                . '<td class="l"><b>' . $e($l['nom']) . '</b>'
                . ($l['classable'] ? '' : ' <span style="font-size:7pt;color:#9a9186">· ' . $e($l['motifHorsClassement']) . '</span>') . '</td>'
                . ($avecMagasin ? '<td class="l mut">' . $e($court($l['magasin'])) . '</td>' : '')
                . '<td>' . $n1($l['heures']) . ' h</td>'
                . '<td>' . $eur($l['ca']) . '</td>'
                . '<td>' . ($l['caHeure'] !== null ? $eur($l['caHeure']) : '') . '</td>'
                . '<td class="mut">' . ($l['coef'] !== null ? number_format((float) $l['coef'], 2, ',', ' ') : '') . '</td>'
                . '<td class="mut">' . ($l['coefCreneau'] !== null ? number_format((float) $l['coefCreneau'], 2, ',', ' ') : '') . '</td>'
                . '<td class="acc"><b>' . ($l['score'] !== null ? $eur($l['score']) : '') . '</b></td>'
                . '<td>' . ($l['panier'] !== null ? number_format((float) $l['panier'], 2, ',', ' ') . ' €' : '') . '</td>'
                . '<td>' . ($l['lignesTicket'] !== null ? $n1($l['lignesTicket']) : '') . '</td>'
                . '<td>' . number_format((float) $l['tickets'], 0, ',', ' ') . '</td>'
                . '<td class="l or" style="font-size:7.4pt"><b>' . $e($prime) . '</b></td></tr>';
        }
        return $h2 . '</table>';
    };

    // Le classement complet ne liste QUE les classées : les grisés y faisaient
    // du bruit sans rien classer. Personne ne disparaît pour autant — chacun
    // reste sur la page de SON magasin, avec son motif.
    if ($seulShop === '') $h .= '<div style="page-break-before:always">' . $entete($e($libMois))
        . '<div class="serif h1">Le classement complet</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">' . count($classables)
        . ' classé(e)s au score. Les personnes sans heures au planning ou sans vente à leur nom ne figurent que sur la page de leur magasin.</div>'
        . $tableau($classables, true) . $methode . '</div>';

    // --- La feuille à part : le croisement Flip & Flap × boissons.
    // Réseau seulement : la version d'un magasin a sa page de combos, sans
    // les euros ni les vendeuses des autres.
    [$duX, $auX] = venteBornes($d['m']);
    $x = $seulShop === '' ? venteCroisementFF($duX, $auX, array_column($d['magasins'], 'nom', 'id')) : ['motif' => 'hors périmètre', 'magasins' => []];
    if ($x['motif'] === null && $x['magasins'] !== []) {
        $h .= '<div style="page-break-before:always">' . $entete($e($libMois))
            . '<div class="serif h1">Le croisement Flip &amp; Flap × boissons</div>'
            . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Sur les tickets contenant un Flip &amp; Flap : la part qui contient aussi une boisson. '
            . 'Chaque ticket sans boisson est une proposition qui n’a pas été faite — valorisée au prix moyen d’une boisson encaissé ce mois-ci ('
            . number_format($x['prixBoisson'], 2, ',', ' ') . ' €).</div>'
            . '<table width="100%" cellpadding="0" cellspacing="4" style="margin:0 -1mm 4mm"><tr>';
        foreach ($x['magasins'] as $mg) {
            $h .= '<td width="' . (int) (100 / max(1, count($x['magasins']))) . '%" valign="top" class="tile">'
                . '<div class="k">' . $e($court($mg['nom'])) . '</div>'
                . '<div style="font-family:Georgia,\'DejaVu Serif\',serif;font-size:14pt;margin-top:1mm;color:' . (($mg['taux'] ?? 0) >= 30 ? '#2d7a3e' : '#8D1D2C') . '">'
                . number_format((float) $mg['taux'], 1, ',', ' ') . ' %</div>'
                . '<div style="font-size:7.5pt;color:#7a736a;margin-top:.8mm">' . $mg['avec'] . ' / ' . $mg['ff'] . ' tickets Flip &amp; Flap<br>'
                . 'laissé au comptoir : <b style="color:#8D1D2C">' . $eur($mg['eur']) . '</b></div></td>';
        }
        $h .= '</tr></table>'
            . '<table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Vendeur·se</th><th class="l">Magasin</th><th>Tickets F&amp;F</th><th>Avec boisson</th>'
            . '<th>Taux d’attache</th><th>Manqués</th><th>À la clé / mois</th></tr>';
        $petits = ['ff' => 0, 'avec' => 0, 'eur' => 0, 'n' => 0];
        foreach ($x['vendeurs'] as $v) {
            // Dix tickets au moins : en dessous, un taux d'attache n'est pas
            // un geste, c'est un hasard — cumulés sur une ligne.
            if ($v['ff'] < 10) { $petits['ff'] += $v['ff']; $petits['avec'] += $v['avec']; $petits['eur'] += $v['eur']; $petits['n']++; continue; }
            $h .= '<tr><td class="l"><b>' . $e($v['nom']) . '</b></td>'
                . '<td class="l mut">' . $e($court($v['magasin'])) . '</td>'
                . '<td>' . $v['ff'] . '</td><td>' . $v['avec'] . '</td>'
                . '<td style="font-weight:bold;color:' . (($v['taux'] ?? 0) >= 30 ? '#2d7a3e' : '#8D1D2C') . '">'
                . number_format((float) $v['taux'], 1, ',', ' ') . ' %</td>'
                . '<td>' . $v['manques'] . '</td>'
                . '<td class="acc"><b>' . $eur($v['eur']) . '</b></td></tr>';
        }
        if ($petits['n'] > 0) {
            $h .= '<tr class="gris"><td class="l" colspan="2">' . $petits['n'] . ' personne(s) sous 10 tickets F&amp;F — cumulées</td>'
                . '<td>' . $petits['ff'] . '</td><td>' . $petits['avec'] . '</td>'
                . '<td>' . ($petits['ff'] > 0 ? number_format(100 * $petits['avec'] / $petits['ff'], 1, ',', ' ') : '') . ' %</td>'
                . '<td>' . ($petits['ff'] - $petits['avec']) . '</td><td>' . $eur($petits['eur']) . '</td></tr>';
        }
        $h .= '</table>'
            . '<div class="methode"><b style="color:#221E1A">Comment lire.</b> Le taux d’attache = tickets Flip &amp; Flap contenant aussi une boisson ÷ tickets Flip &amp; Flap. '
            . 'C’est LE geste qui se coache en brief : « un Flip &amp; Flap ? proposez la boisson ». '
            . 'Le « à la clé » suppose une boisson par ticket manqué, au prix moyen réellement encaissé — un plafond de geste, pas une promesse. '
            . 'Sous 10 tickets Flip &amp; Flap dans le mois, le taux est cumulé plutôt qu’affiché : deux tickets ne font pas un geste. '
            . 'Les tickets sans vendeur identifié comptent dans les totaux magasin, pas dans les lignes individuelles.</div>'
            . '</div>';
    }

    // --- Une page par magasin.
    foreach ($d['magasins'] as $mag) {
        if (!$garde($mag['id'])) { continue; }
        $siens = array_values(array_filter($d['lignes'], static fn ($l) => (string) $l['shopId'] === (string) $mag['id']));
        if ($siens === []) { continue; }
        $h .= '<div style="page-break-before:always">' . $entete($e($court($mag['nom'])) . ' · ' . $e($libMois))
            . '<div class="serif h1">' . $e($court($mag['nom'])) . ' — l’équipe de vente</div>'
            . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Les mêmes mesures que la page réseau, resserrées sur l’équipe — la feuille du brief du mois.</div>'
            . $tableau($siens, false) . $methode . '</div>';
    }

    $h .= '</div>';


    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Target de vente — ' . $e($libMois) . '</title></head><body>' . $h . '</body></html>';
    $label = 'Réseau';
    $nomSeul = '';
    if ($seulShop !== '') { foreach ($d['magasins'] as $magF) { if ((string) $magF['id'] === $seulShop) { $label = $court($magF['nom']); $nomSeul = '-' . mktSlug($label); } } }
    return ['doc' => $doc, 'm' => $d['m'], 'libMois' => $libMois, 'magasin' => $label,
        'nom' => 'target-vente-' . $d['m'] . $nomSeul . '.pdf'];
}

/** GET /ventes/classement.pdf?m=2026-07&shop=2 — le document, rendu et servi. */
function ep_ventes_pdf(): array
{
    $r = venteClotureDoc(trim((string) ($_GET['m'] ?? '')), trim((string) ($_GET['shop'] ?? '')));
    if (isset($r['error'])) { http_response_code(422); return ['error' => $r['error']]; }
    $pdf = rapPdfRendu($r['doc'], ['magasin' => $r['magasin'], 'rapport' => 'Target de vente — ' . $r['libMois'],
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $r['nom'] . '"');
    echo $pdf;
    exit;
}

/**
 * La prime cross-selling : une target de lignes par ticket, PAR MAGASIN et
 * ÉVOLUTIVE — posée un mois, elle vaut pour les suivants jusqu'à la
 * prochaine. Toute vendeuse qui l'atteint touche la prime : ce n'est pas un
 * podium, c'est un seuil — on peut être plusieurs à y arriver, c'est le but.
 *
 * Le geste visé est celui du comptoir : proposer le dessert, proposer la
 * boisson. Trente tickets au moins dans le mois, comme partout : deux tickets
 * ne font pas un geste.
 */
const VENTE_CROSS_MIN_TICKETS = 30;

/** L'appli du personnel — le QR imprimé au pied de l'affiche. */
const VENTE_APPLI_URL = 'https://atelierby.tfbuddy.com/employee';
const VENTE_APPLI_QR = 'iVBORw0KGgoAAAANSUhEUgAAAPgAAAD4CAIAAABOs7xcAAAE2klEQVR4nO3dMY5kNRRAURqxiwkI2P+SCAhmHZ8NjCWM7LZ/3XNSpOpfNVcOXplXX8/z/Aaf7vfTDwDfQegkCJ0EoZMgdBKEToLQSfhj9B/++vPHdz7H//b3Pz9PP8J/Mvt5zr6v217/lNH7cqKTIHQShE6C0EkQOglCJ0HoJAzn6COn5tar5rij1xm9r93z6Vmrnmf2c5h9/d1mPwcnOglCJ0HoJAidBKGTIHQShE7C9Bx9ZNWc+7a57Kr5+sju1z/lth6c6CQInQShkyB0EoROgtBJEDoJy+bobzE7t151b3uVVc9f40QnQegkCJ0EoZMgdBKEToLQScjN0UdO7QvfPed+y/743ZzoJAidBKGTIHQShE6C0EkQOgnL5ui3zWtv2ytyan/Lqf3ut/XgRCdB6CQInQShkyB0EoROgtBJmJ6jv31PyG37zm97nllv6cGJToLQSRA6CUInQegkCJ0EoZPw9TzP6Wf4Vqvm2av2wOx+ndv2u5/iRCdB6CQInQShkyB0EoROgtBJGM7Rb5v7jpzalzKye8/67vn3p87pnegkCJ0EoZMgdBKEToLQSRA6Ccvuo5/aR757Dn1qTrx7nn3bv9fu13GikyB0EoROgtBJEDoJQidB6CRMz9FPzWt3e/t960/d7z4y+/k70UkQOglCJ0HoJAidBKGTIHQShr8zOjsH/dT9JKucuv+96nN++x4eJzoJQidB6CQInQShkyB0EoROwvR+9JFV89q37DVf5dS97bfsdVnVoROdBKGTIHQShE6C0EkQOglCJ2H7HP3UvHZk9/uq7Xt5Cyc6CUInQegkCJ0EoZMgdBKETsJwr8vIqT0hb5lbr7Lq/a66L37bnpwR99FJEzoJQidB6CQInQShkyB0Epb9zuhun7p3ZeTU732+ZW+93xmFXxA6CUInQegkCJ0EoZMgdBKm76PPWjXf3T1/XTWvvW0fzqq/u/v/Q9jNiU6C0EkQOglCJ0HoJAidBKGTcN199Lfchx7Zvcf9LffyT31fYa8LaUInQegkCJ0EoZMgdBKETsLwPvpb7nOPjP7ubXP6kd1z/VPPeaoHJzoJQidB6CQInQShkyB0EoROwvR99Nt86v7yt/x+526rvjdwopMgdBKEToLQSRA6CUInQegkDOfop+a7b7nfXPu7q5zay+5EJ0HoJAidBKGTIHQShE6C0EkY7nVZtd9jld37s1e5bd4/a/d+992v4z46aUInQegkCJ0EoZMgdBKETsL0ffTb3Db3nXXq901P7Z859X2IE50EoZMgdBKEToLQSRA6CUInYXgffeQte7J3z8VP7Ts/tTf9tu8rZj9nJzoJQidB6CQInQShkyB0EoROwvQcfeTU/u/Z13n7fH33Pe/b5vGrnseJToLQSRA6CUInQegkCJ0EoZOwbI5+m933vG+bN4+c2q+y+3Oz1wV+QegkCJ0EoZMgdBKEToLQSfjYOfru++i37bcZPc+q+/Gn3u+q53eikyB0EoROgtBJEDoJQidB6CQsm6OfmrOuctvvYq7aB3/q+W+7T+9EJ0HoJAidBKGTIHQShE6C0EmYnqPv3meyyu7f+5z19t8TPfX7qas40UkQOglCJ0HoJAidBKGTIHQSvp7nOf0MsJ0TnQShkyB0EoROgtBJEDoJQifhXwBfLKJaMdi9AAAAAElFTkSuQmCC';

/**
 * Les paliers de prime : des CRANS AU-DESSUS DE LA CIBLE DU MAGASIN — pas
 * des seuils absolus. « Cible +0,1 » vaut 2,6 là où la cible est 2,5 et 2,4
 * là où elle est 2,3 : l'effort demandé est le même partout, le point de
 * départ non. L'échelle des crans et des montants reste UNE pour le réseau.
 *
 * Les anciens paliers absolus (2,6 / 2,8 / 3,0 posés quand la cible commune
 * était 2,5) se relisent comme des écarts à 2,5 — la conversion est
 * transparente et ne change aucun montant.
 *
 * @return list<array{plus:float,montant:int}> triés par écart croissant
 */
function venteCrossPaliers(): array
{
    $p = setting('venteCrossPaliers');
    if (!is_array($p)) { return []; }
    $out = [];
    foreach ($p as $x) {
        if (!is_array($x) || !isset($x['montant'])) { continue; }
        $plus = isset($x['plus']) ? (float) $x['plus']
            : (isset($x['seuil']) ? (float) $x['seuil'] - 2.5 : null);
        if ($plus === null || $plus < 0.05) { continue; }
        $out[] = ['plus' => round($plus, 1), 'montant' => max(1, (int) $x['montant'])];
    }
    usort($out, static fn ($a, $b) => $a['plus'] <=> $b['plus']);
    return $out;
}

/**
 * La prime d'une vendeuse : le plus haut cran franchi au-dessus de SA cible.
 *
 * @return array{montant:int,seuil:float}|null  seuil = la valeur absolue franchie
 */
function venteCrossPrime(float $lignesTicket, float $targetMagasin, int $montantBase, array $paliers): ?array
{
    if ($lignesTicket < $targetMagasin) { return null; }
    $prime = ['montant' => $montantBase, 'seuil' => $targetMagasin];
    foreach ($paliers as $pal) {
        $seuil = round($targetMagasin + $pal['plus'], 2);
        // MIEUX NE PAIE JAMAIS MOINS : un cran mal réglé sous la prime de
        // cible (cible → 500 €, +0,1 → 75 €) rendait le barème absurde —
        // dépasser la cible faisait PERDRE de l'argent à l'équipe. Le cran
        // ne s'applique que s'il paie au moins autant que l'étage d'en
        // dessous ; sinon l'étage tient.
        if ($lignesTicket >= $seuil && $pal['montant'] >= $prime['montant']) {
            $prime = ['montant' => $pal['montant'], 'seuil' => $seuil];
        }
    }
    return $prime;
}

/** La target applicable pour un magasin et un mois : la dernière posée. */
function venteCrossTarget(array $cfg, string $shop, string $m): ?float
{
    $par = $cfg[$shop] ?? [];
    $val = null; $depuis = null;
    foreach ($par as $depuisM => $t) {
        if ($depuisM <= $m && ($depuis === null || $depuisM > $depuis)) { $depuis = $depuisM; $val = (float) $t; }
    }
    return $val;
}

/**
 * « BATS TON RECORD » — une seule règle, pour chaque vendeuse ET chaque
 * magasin : ta référence est TON RECORD des 12 derniers mois (ta meilleure
 * moyenne mensuelle de lignes par ticket) ; chaque dixième au-dessus paie.
 * Le record GLISSE (un exploit de plus d'un an sort de la fenêtre — la barre
 * reste à portée), et la COURONNE du mois (plus haute moyenne du réseau,
 * vendeuse et magasin) garde le sommet concurrentiel. On ne paie jamais deux
 * fois le même progrès : le nouveau record devient la référence.
 */
function venteRecordReglages(): array
{
    return [
        'eurDixieme' => is_numeric(setting('venteRecordEurDixieme')) ? (int) setting('venteRecordEurDixieme') : 100,
        // Le garde-fou : on paie au plus N dixièmes par mois — un record
        // fantôme (mois faible) ne fait pas sauter la banque ; le nouveau
        // record se pose quand même, le compteur repart le mois suivant.
        'maxDixiemes' => is_numeric(setting('venteRecordMaxDixiemes')) ? (int) setting('venteRecordMaxDixiemes') : 3,
    ];
}

/** POST /ventes/record — les trois réglages du système. */
function wr_ventes_record(): array
{
    $b = body();
    foreach ([['eurDixieme', 'venteRecordEurDixieme'], ['maxDixiemes', 'venteRecordMaxDixiemes']] as [$cle, $reg]) {
        if (isset($b[$cle]) && is_numeric($b[$cle])) {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$reg, json_encode(max(0, (int) $b[$cle]))]);
        }
    }
    journalAdd('CEO', 'Paramètre', 'Bats ton record', 'Réglages mis à jour');
    return ['ok' => true, 'reglages' => venteRecordReglages()];
}

/** Les 12 mois AVANT $m — la fenêtre glissante du record. */
function venteFenetreRecord(string $m): array
{
    $out = [];
    for ($i = 1; $i <= 12; $i++) { $out[] = date('Y-m', strtotime($m . '-01 -' . $i . ' month')); }
    return $out;
}

/** Le record d'une vendeuse sur la fenêtre : sa meilleure moyenne mensuelle
 *  (30 tickets au moins ce mois-là — un record se pose sur du réel). */
function venteRecordVendeuse(array $parMois, string $id, string $m): ?float
{
    $rec = null;
    foreach (venteFenetreRecord($m) as $mF) {
        foreach (($parMois[$mF] ?? []) ?: [] as $l) {
            if ((string) $l['id'] !== $id) { continue; }
            if (($l['tickets'] ?? 0) < VENTE_CROSS_MIN_TICKETS || $l['lignesTicket'] === null) { continue; }
            if ($rec === null || (float) $l['lignesTicket'] > $rec) { $rec = (float) $l['lignesTicket']; }
        }
    }
    return $rec;
}

/** La moyenne d'un magasin sur un mois — la même règle partout. */
function venteMoyenneMagasin(?array $lignes, string $sid): ?array
{
    if ($lignes === null) { return null; }
    $lg = 0.0; $tk = 0;
    foreach ($lignes as $l) {
        if ((string) $l['shopId'] !== $sid || $l['lignesTicket'] === null) { continue; }
        $lg += $l['lignesTicket'] * $l['tickets']; $tk += $l['tickets'];
    }
    return $tk > 0 ? ['moyenne' => round($lg / $tk, 2), 'tickets' => $tk] : null;
}

/** Le record d'un magasin sur la fenêtre glissante. */
function venteRecordMagasin(array $parMois, string $sid, string $m): ?float
{
    // Un mois trop maigre ne pose pas de record : sa moyenne est du bruit.
    // Le plancher se cale sur le magasin lui-même — 40 % de son meilleur
    // mois en tickets sur la fenêtre — plutôt que sur une constante.
    $mois = [];
    $maxTk = 0;
    foreach (venteFenetreRecord($m) as $mF) {
        $x = venteMoyenneMagasin($parMois[$mF] ?? null, $sid);
        if ($x !== null) { $mois[] = $x; $maxTk = max($maxTk, $x['tickets']); }
    }
    $rec = null;
    foreach ($mois as $x) {
        if ($x['tickets'] < 0.4 * $maxTk) { continue; }
        if ($rec === null || $x['moyenne'] > $rec) { $rec = $x['moyenne']; }
    }
    return $rec;
}

/** Les tranches de 0,1 gagnées au-dessus du record → la prime. */
function venteRecordPrime(?float $moy, ?float $record, int $eurDixieme, int $maxDixiemes = 0): array
{
    if ($moy === null || $record === null || $moy <= $record + 1e-9) { return ['tranches' => 0, 'prime' => 0]; }
    // Le PREMIER dixième pose le nouveau record mais ne paie pas — la prime
    // démarre au deuxième : +0,1 → 0, +0,2 → 1 tranche, +0,3 → 2 tranches.
    $tranches = max(0, (int) floor(($moy - $record + 1e-9) / 0.1) - 1);
    if ($maxDixiemes > 0) { $tranches = min($tranches, $maxDixiemes); }
    return ['tranches' => $tranches, 'prime' => $tranches * $eurDixieme];
}

/** GET /ventes/cross?n=6 — le tableau mois × magasin, targets et atteintes. */
function ep_ventes_cross(): array
{
    $n = max(2, min(12, (int) ($_GET['n'] ?? 6)));
    $reglages = venteRecordReglages();
    $hist = setting('ventePrimesCrossHist');
    if (!is_array($hist)) { $hist = []; }

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }

    $out = ['reglages' => $reglages, 'minTickets' => VENTE_CROSS_MIN_TICKETS,
        'mois' => [], 'magasins' => [], 'dernierRevolu' => null];
    $moisListe = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $m = date('Y-m', $t);
        $enc = $m === date('Y-m');
        $moisListe[] = $m;
        $out['mois'][] = ['cle' => $m, 'lib' => strftime_fr($t, 'M Y'), 'encours' => $enc];
        if (!$enc) { $out['dernierRevolu'] = $m; }
    }
    // La fenêtre des RECORDS : douze mois avant le plus ancien mois affiché.
    // Un mois sans transaction se lit vite — la table ne remonte pas si loin.
    $parMois = [];
    for ($i = $n - 1 + 12; $i >= 0; $i--) {
        $m = date('Y-m', strtotime('-' . $i . ' month', strtotime(date('Y-m-01'))));
        $r = venteMois($m, $nomDe);
        $parMois[$m] = $r['motif'] === null ? $r['lignes'] : null;
    }

    foreach ($nomDe as $sid => $nom) {
        $cells = [];
        foreach ($moisListe as $m) {
            $x = venteMoyenneMagasin($parMois[$m] ?? null, (string) $sid);
            // Les vendeuses du magasin face à LEUR record.
            $gagnantes = [];
            foreach (($parMois[$m] ?? []) ?: [] as $l) {
                if ((string) $l['shopId'] !== (string) $sid) { continue; }
                if (($l['tickets'] ?? 0) < VENTE_CROSS_MIN_TICKETS || $l['lignesTicket'] === null) { continue; }
                $recV = venteRecordVendeuse($parMois, (string) $l['id'], $m);
                $prV = venteRecordPrime((float) $l['lignesTicket'], $recV, $reglages['eurDixieme'], $reglages['maxDixiemes']);
                if ($prV['prime'] === 0) { continue; }
                $gagnantes[] = ['id' => $l['id'], 'nom' => $l['nom'],
                    'lignesTicket' => $l['lignesTicket'], 'record' => $recV,
                    'tranches' => $prV['tranches'], 'prime' => $prV['prime']];
            }
            usort($gagnantes, static fn ($a, $b) => $b['prime'] <=> $a['prime']);
            $cells[] = ['m' => $m,
                'moyenne' => $x['moyenne'] ?? null, 'tickets' => $x['tickets'] ?? 0,
                'gagnantes' => $gagnantes, 'nb' => count($gagnantes),
                'eur' => array_sum(array_column($gagnantes, 'prime'))];
        }
        $out['magasins'][] = ['id' => (string) $sid, 'nom' => $nom, 'cells' => $cells];
    }
    $out['enregistre'] = $out['dernierRevolu'] !== null && isset($hist[$out['dernierRevolu']])
        ? $hist[$out['dernierRevolu']] : null;

    // --- Le SIMULATEUR : les données du dernier mois SERVI (la table des
    // transactions peut être en retard sur la caisse) — tickets, moyenne de
    // lignes par ticket TOUS tickets confondus, valeur moyenne d'une ligne
    // encaissée. Les scénarios et le compte se calculent à l'écran avec ça.
    $sim = null;
    for ($rec = 0; $rec <= 2; $rec++) {
        $mSim = date('Y-m', strtotime('first day of -' . $rec . ' months'));
        [$duS, $auS] = venteBornes($mSim);
        try {
            // COUNT(*) et pas SUM(quantity) : la cible parle en LIGNES par
            // ticket (le geste de proposer), pas en unités — trois croissants
            // sur une ligne restent un seul geste. La même règle que venteMois.
            $rows = Db::rows('SELECT t.id_shop sid, COUNT(DISTINCT t.id) tickets,
                                     COUNT(*) q, SUM(l.total_gross_value_after_discount) ca
                                FROM `transaction` t JOIN transaction_product l ON l.id_transaction = t.id
                               WHERE t.insert_timestamp >= ? AND t.insert_timestamp < ?
                               GROUP BY t.id_shop', [$duS, $auS]);
        } catch (PDOException $e) { $rows = []; }
        $tk = array_sum(array_map(fn ($r2) => (int) $r2['tickets'], $rows));
        if ($tk === 0) { continue; }
        // Les TICKETS viennent de la CAISSE (clients/jour × jours du mois,
        // endpoint des KPIs annuels) : la table locale n'en porte qu'une
        // partie et aurait sous-estimé le CA en plus. La moyenne de lignes
        // par ticket, elle, reste mesurée sur la table — la seule qui voit
        // les lignes — et la source des tickets est dite à l'écran.
        $caisse = [];
        try {
            $ka = rapAppel('ep_stores_kpis_annuels');
            $numMois = (int) substr($mSim, 5, 2);
            $joursMois = (int) date('t', strtotime($mSim . '-01'));
            if (is_array($ka) && (int) ($ka['annee'] ?? 0) === (int) substr($mSim, 0, 4)) {
                foreach (($ka['magasins'] ?? []) as $kaM) {
                    $cj = $kaM['mois'][$numMois]['clientsJour'] ?? ($kaM['mois'][(string) $numMois]['clientsJour'] ?? null);
                    if (is_numeric($cj) && (float) $cj > 0) {
                        $caisse[(string) $kaM['id']] = (int) round((float) $cj * $joursMois);
                    }
                }
            }
        } catch (Throwable $eK) { /* caisse muette : repli table locale */ }
        $qTot = 0.0; $caTot = 0.0; $mags = [];
        foreach ($rows as $r2) {
            $sid2 = (string) $r2['sid'];
            if (!isset($nomDe[$sid2])) { continue; }
            $qTot += (float) $r2['q']; $caTot += (float) $r2['ca'];
            $vend = 0;
            foreach (($parMois[$mSim] ?? []) ?: [] as $l2) {
                if ((string) $l2['shopId'] === $sid2 && ($l2['tickets'] ?? 0) >= VENTE_CROSS_MIN_TICKETS) { $vend++; }
            }
            $mags[] = ['id' => $sid2, 'nom' => $nomDe[$sid2],
                'tickets' => $caisse[$sid2] ?? (int) $r2['tickets'],
                'ticketsCaisse' => isset($caisse[$sid2]),
                'moyenne' => (int) $r2['tickets'] > 0 ? round((float) $r2['q'] / (int) $r2['tickets'], 2) : null,
                'vendeuses' => $vend];
        }
        $mesuree = $qTot > 0 ? round($caTot / $qTot, 2) : null;
        $forcee = setting('venteSimValeurLigne');
        $sim = ['mois' => $mSim, 'lib' => strftime_fr(strtotime($mSim . '-01'), 'M Y'),
            'ticketsCaisse' => $caisse !== [],
            'valeurLigneMesuree' => $mesuree,
            'valeurLigne' => is_numeric($forcee) ? (float) $forcee : $mesuree,
            'valeurForcee' => is_numeric($forcee),
            'marge' => is_numeric(setting('venteSimMarge')) ? (float) setting('venteSimMarge') : 65.0,
            'magasins' => $mags];
        break;
    }
    $out['sim'] = $sim;
    return $out;
}

/** POST /ventes/sim — les deux variables du simulateur, en réglages. */
function wr_ventes_sim(): array
{
    $b = body();
    if (array_key_exists('marge', $b)) {
        $v = is_numeric($b['marge']) ? max(0, min(100, (float) $b['marge'])) : 65;
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['venteSimMarge', json_encode($v)]);
    }
    if (array_key_exists('valeurLigne', $b)) {
        // Vide = retour à la valeur MESURÉE ; un nombre = la forcer.
        $v = is_numeric($b['valeurLigne']) ? (float) $b['valeurLigne'] : null;
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['venteSimValeurLigne', json_encode($v)]);
    }
    journalAdd('CEO', 'Paramètre', 'Simulateur ventes', 'Variables mises à jour');
    return ['ok' => true];
}

/**
 * GET /ventes/explication.pdf — la feuille pour les ÉQUIPES : le système des
 * primes expliqué simplement, avec les montants et cibles RÉELLEMENT réglés
 * au moment de l'impression — jamais un chiffre recopié à la main.
 */
function ep_ventes_explication_pdf(): array
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $primes = ventePrimesConfig();
    $reg = venteRecordReglages();
    $logo = rapLogoDataUri();
    $blocTitre = static fn (string $n, string $t2) =>
        '<div style="display:flex;align-items:baseline;gap:3mm;margin:6mm 0 2mm">'
        . '<span style="font-family:Georgia,\'DejaVu Serif\',serif;font-size:16pt;color:#8D1D2C">' . $n . '</span>'
        . '<span style="font-family:Georgia,\'DejaVu Serif\',serif;font-size:13pt">' . $t2 . '</span></div>';
    $h = '<style>
      .doc { font-family: Helvetica, Arial, sans-serif; color: #222; font-size: 10pt; line-height: 1.55; }
      .k { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.08em; color: #7a736a; }
      .eur { font-family: Georgia, "DejaVu Serif", serif; font-size: 15pt; color: #8D1D2C; }
      .encart { background: #fbf9f5; border: 1px solid #e5e0d8; border-radius: 10px; padding: 4mm 5mm; }
    </style><div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:11mm">' : '') . '</td>'
        . '<td align="right" class="k">Les primes de vente — le mode d\'emploi de l\'équipe</td></tr></table>'
        . '<div style="border-bottom:2px solid #8D1D2C;margin:2mm 0 4mm"></div>'
        . '<div style="font-family:Georgia,\'DejaVu Serif\',serif;font-size:19pt">Chaque mois, deux façons de gagner. Elles s\'additionnent.</div>'
        . '<div style="color:#7a736a;font-size:9.5pt;margin-top:1mm">Un seul geste les nourrit toutes : proposer quelque chose en plus à chaque client. « Et avec ça ? »</div>'

        . $blocTitre('1', 'Bats ton record — ' . $reg['eurDixieme'] . ' € par dixième dès le deuxième')
        . '<div>Ta référence, c\'est <b>ton record des 12 derniers mois</b> — ta meilleure moyenne de lignes par ticket. '
        . 'Le premier dixième au-dessus pose ton nouveau record ; <b>à partir du deuxième, chaque dixième = ' . $reg['eurDixieme'] . ' €</b>. Bats-le de 0,3 : ' . (2 * $reg['eurDixieme']) . ' €. '
        . 'Le nouveau record devient ta référence — et un record de plus d\'un an sort du compte : la barre reste toujours à ta portée. '
        . '<span style="color:#7a736a">(Au moins ' . VENTE_CROSS_MIN_TICKETS . ' tickets dans le mois.)</span></div>'
        . '<table width="100%" cellpadding="0" cellspacing="3" style="margin-top:2mm"><tr>'
        . '<td width="25%" align="center" style="background:#d8cec2;border-radius:8px;padding:3mm 1mm;color:#6b5f52"><div style="font-size:8pt">+ 0,1</div><div style="font-family:Georgia,serif;font-size:11pt">nouveau record</div></td>'
        . '<td width="25%" align="center" style="background:#a8734d;border-radius:8px;padding:3mm 1mm;color:#fff"><div style="font-size:8pt">+ 0,2</div><div style="font-family:Georgia,serif;font-size:14pt">' . $reg['eurDixieme'] . ' €</div></td>'
        . '<td width="25%" align="center" style="background:#8d5a3a;border-radius:8px;padding:3mm 1mm;color:#fff"><div style="font-size:8pt">+ 0,3</div><div style="font-family:Georgia,serif;font-size:14pt">' . (2 * $reg['eurDixieme']) . ' €</div></td>'
        . '<td width="25%" align="center" style="background:#8D1D2C;border-radius:8px;padding:3mm 1mm;color:#fff"><div style="font-size:8pt">+ 0,4</div><div style="font-family:Georgia,serif;font-size:14pt">' . (3 * $reg['eurDixieme']) . ' €</div></td>'
        . '</tr></table>'
        . '<div style="color:#7a736a;font-size:9pt;margin-top:1.5mm">Ta barre est &#224; toi : personne d\'autre &#224; battre que toi-m&#234;me.</div>'


        . $blocTitre('2', 'La meilleure vendeuse — ' . $primes['magasin'] . ' € / ' . $primes['reseau'] . ' €')
        . '<div>Chaque mois, un score est calculé pour chacune : vos ventes, ramenées à vos <b>heures de travail</b>. '
        . 'Le calcul tient compte de vos horaires — l\'après-midi et la semaine, c\'est plus dur que le samedi matin : le score le sait, et corrige. '
        . 'Peu d\'heures ce mois-ci ? Vous êtes quand même dans la course : c\'est par heure travaillée.</div>'
        . '<table width="100%" cellpadding="0" cellspacing="3" style="margin-top:2mm"><tr>'
        . '<td width="50%" class="encart" align="center"><span class="k">Meilleure du magasin</span><div class="eur">' . $primes['magasin'] . ' €</div></td>'
        . '<td width="50%" class="encart" align="center"><span class="k">Meilleure du réseau</span><div class="eur">' . $primes['reseau'] . ' €</div></td>'
        . '</tr></table>'

        . '<div class="encart" style="margin-top:6mm">'
        . '<span class="k">Les règles, en clair</span>'
        . '<div style="font-size:9.5pt;margin-top:1mm">Tout se calcule sur le <b>mois complet</b>, jamais en cours de route — les primes tombent au début du mois suivant, <b>en bons payés par la marque</b>, jamais par le magasin. '
        . 'Rien n\'est caché : l\'affiche du magasin montre les cibles, les montants et le podium du mois dernier. '
        . 'Le calcul est le même pour toutes, écrit noir sur blanc — s\'il vous semble faux, dites-le, on vérifie.</div></div>'

        . '<div style="margin-top:5mm;font-family:Georgia,\'DejaVu Serif\',serif;font-size:12pt;text-align:center;color:#8D1D2C">'
        . 'Un article en plus à chaque client : bon pour votre record et pour votre score.<br>Un seul geste, deux primes.</div>'
        . '</div>';
    $pdf = rapPdfRendu($h, ['magasin' => 'Réseau', 'rapport' => 'Primes de vente — mode d\'emploi',
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="primes-mode-demploi.pdf"');
    echo $pdf;
    exit;
}

/** POST /ventes/cross-target {shop, target[, m]} — poser la target, dès ce mois. */
function wr_ventes_cross_target(): array
{
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    $m = trim((string) ($b['m'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m'); }
    $cfg = setting('venteCrossTargets');
    if (!is_array($cfg)) { $cfg = []; }
    $t = $b['target'] ?? '';
    if ($t === '' || $t === null || !is_numeric(str_replace(',', '.', (string) $t))) {
        unset($cfg[$shop][$m]);
        $msg = 'Target cross-selling retirée';
    } else {
        // Entre 1 et 10 lignes par ticket : au-delà, ce n'est plus un geste
        // de vente, c'est une erreur de saisie.
        $v = max(1.0, min(10.0, (float) str_replace(',', '.', (string) $t)));
        $cfg[$shop][$m] = round($v, 1);
        $msg = 'Target cross-selling posée à ' . number_format($v, 1, ',', ' ') . ' lignes/ticket dès ' . $m;
    }
    if (isset($b['montant']) && is_numeric($b['montant'])) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['venteCrossMontant', json_encode(max(1, (int) $b['montant']))]);
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['venteCrossTargets', json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }
    journalAdd('CEO', 'Vente', $nomDe[$shop] ?? ('Magasin ' . $shop), $msg);
    return ['ok' => true];
}

/** POST /ventes/cross-paliers {paliers: [{seuil, montant}]} — l'échelle des primes. */
function wr_ventes_cross_paliers(): array
{
    $b = body();
    $liste = [];
    foreach ((array) ($b['paliers'] ?? []) as $x) {
        $plus = str_replace(',', '.', (string) ($x['plus'] ?? $x['seuil'] ?? ''));
        if (!is_numeric($plus) || !is_numeric($x['montant'] ?? null)) { continue; }
        $liste[] = ['plus' => round(max(0.1, min(5.0, (float) $plus)), 1),
            'montant' => max(1, (int) $x['montant'])];
    }
    usort($liste, static fn ($p, $q) => $p['plus'] <=> $q['plus']);
    if (isset($b['montantBase']) && is_numeric($b['montantBase'])) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['venteCrossMontant', json_encode(max(1, (int) $b['montantBase']))]);
    }
    if (isset($b['montantShop']) && is_numeric($b['montantShop'])) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['venteCrossMontantShop', json_encode(max(1, (int) $b['montantShop']))]);
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['venteCrossPaliers', json_encode($liste)]);
    journalAdd('CEO', 'Vente', null, 'Paliers cross-selling : '
        . ($liste === [] ? 'retirés' : implode(' · ', array_map(
            static fn ($p) => 'cible +' . number_format($p['plus'], 1, ',', ' ') . ' → ' . $p['montant'] . ' €', $liste))));
    return ['ok' => true, 'paliers' => $liste];
}

/** POST /ventes/primes-montants {reseau, magasin} — les montants du score. */
function wr_ventes_primes_montants(): array
{
    $b = body();
    $c = ventePrimesConfig();
    if (isset($b['reseau']) && is_numeric($b['reseau'])) { $c['reseau'] = max(1, (int) $b['reseau']); }
    if (isset($b['magasin']) && is_numeric($b['magasin'])) { $c['magasin'] = max(1, (int) $b['magasin']); }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['ventePrimes', json_encode($c)]);
    journalAdd('CEO', 'Vente', null, 'Montants des primes au score : réseau ' . $c['reseau'] . ' € · magasin ' . $c['magasin'] . ' €');
    return ['ok' => true, 'primes' => $c];
}

/** POST /ventes/cross-primes {m} — enregistre les primes cross d'un mois révolu. */
function wr_ventes_cross_primes(): array
{
    $b = body();
    $m = trim((string) ($b['m'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { http_response_code(422); return ['error' => 'mois attendu : AAAA-MM']; }
    if ($m >= date('Y-m')) { http_response_code(422); return ['error' => 'le mois en cours ne se prime pas : il n’est pas fini']; }
    $hist = setting('ventePrimesCrossHist');
    if (!is_array($hist)) { $hist = []; }
    if (isset($hist[$m])) { http_response_code(409); return ['error' => 'les primes de ' . $m . ' sont déjà enregistrées']; }

    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }
    // Le mois primé + sa fenêtre de records.
    $parMois = [];
    foreach (array_merge(venteFenetreRecord($m), [$m]) as $mF) {
        $r2 = venteMois($mF, $nomDe);
        $parMois[$mF] = $r2['motif'] === null ? $r2['lignes'] : null;
    }
    if (($parMois[$m] ?? null) === null) { http_response_code(503); return ['error' => 'le mois ' . $m . ' n’a pas pu être calculé']; }

    $reglages = venteRecordReglages();
    $enr = ['m' => $m, 'quand' => date('Y-m-d H:i'), 'reglages' => $reglages, 'gagnantes' => [], 'magasinsGagnants' => []];

    // CHACUNE face à son record.
    foreach ($parMois[$m] as $l) {
        if (($l['tickets'] ?? 0) < VENTE_CROSS_MIN_TICKETS || $l['lignesTicket'] === null) { continue; }
        $rec = venteRecordVendeuse($parMois, (string) $l['id'], $m);
        $pr = venteRecordPrime((float) $l['lignesTicket'], $rec, $reglages['eurDixieme'], $reglages['maxDixiemes']);
        if ($pr['prime'] === 0) { continue; }
        $enr['gagnantes'][] = ['id' => $l['id'], 'nom' => $l['nom'], 'magasin' => $l['magasin'],
            'lignesTicket' => $l['lignesTicket'], 'record' => $rec,
            'tranches' => $pr['tranches'], 'prime' => $pr['prime']];
        journalAdd('CEO', 'Vente', $l['nom'], 'Bats ton record ' . $m . ' — ' . $pr['prime'] . ' €'
            . ' (record ' . ($rec !== null ? number_format($rec, 2, ',', ' ') : 'aucun')
            . ' → ' . number_format((float) $l['lignesTicket'], 2, ',', ' ') . ', ' . $pr['tranches'] . ' dixième(s)) — ' . $l['magasin']);
    }
    $enr['magasinsGagnants'] = [];
    if ($enr['gagnantes'] === []) {
        http_response_code(422); return ['error' => 'aucun record battu sur ' . $m . ' — rien à primer'];
    }
    $hist[$m] = $enr;
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['ventePrimesCrossHist', json_encode($hist, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'primes' => $enr];
}

/**
 * GET /ventes/affiche.pdf[?m=2026-08] — l'affiche des primes, une page par
 * magasin, à imprimer et épingler en réserve.
 *
 * Elle ne s'adresse pas au gérant mais à la VENDEUSE : ce qu'il y a à gagner
 * ce mois-ci, en grand, avec le geste qui y mène — pas la méthode complète,
 * qui a son propre document. Les montants et la cible sont ceux du mois du
 * magasin : l'affiche de Corbais n'est pas celle de Halle.
 */
/** L'affiche en PDF, réutilisable : ?shop= la réduit à un magasin. */
function venteAffichePdf(string $m = '', string $seulShop = ''): ?array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m'); }
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $n1 = static fn ($v) => number_format((float) $v, 1, ',', ' ');
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $logo = rapLogoDataUri();
    $libMois = strftime_fr(strtotime($m . '-01'), 'M Y');

    $primes = ventePrimesConfig();
    $regAff = venteRecordReglages();
    $nomDe = [];
    foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) {
        $nomDe[(string) $s['id']] = (string) $s['name'];
    }
    $maxTotal = $primes['reseau'] + $regAff['maxDixiemes'] * $regAff['eurDixieme'];

    // Le classement du MOIS PRÉCÉDENT : le podium du magasin et la gagnante
    // réseau — c'est ce qui donne envie de détrôner. Calculé une fois pour
    // toutes les pages.
    $mPrec = date('Y-m', strtotime($m . '-01 -1 month'));
    $libPrec = strftime_fr(strtotime($mPrec . '-01'), 'M');
    $rPrec = venteMois($mPrec, $nomDe);
    // La fenêtre des RECORDS du mois affiché : les barres à battre. Les
    // intensités de créneau de chaque mois voyagent avec — la vignette
    // « comment on calcule » de la page détail les montre en vrai.
    $parMoisAff = [$mPrec => ($rPrec['motif'] === null ? $rPrec['lignes'] : null)];
    $crenParMois = [$mPrec => ($rPrec['motif'] === null ? ($rPrec['creneaux'] ?? null) : null)];
    foreach (venteFenetreRecord($m) as $mF) {
        if (isset($parMoisAff[$mF])) { continue; }
        $rF = venteMois($mF, $nomDe);
        $parMoisAff[$mF] = $rF['motif'] === null ? $rF['lignes'] : null;
        $crenParMois[$mF] = $rF['motif'] === null ? ($rF['creneaux'] ?? null) : null;
    }
    // Les vendeuses ACTIVES de chaque magasin et leur record — la barre
    // nominative de chacune. Le « mois dernier » peut ne pas être servi par
    // la table des transactions : on recule jusqu'au dernier mois qui l'est.
    $barres = [];
    for ($recB = 1; $recB <= 3 && $barres === []; $recB++) {
        $mB = date('Y-m', strtotime($m . '-01 -' . $recB . ' month'));
        $lB = $parMoisAff[$mB] ?? null;
        if ($lB === null) { continue; }
        foreach ($lB as $l) {
            if (($l['tickets'] ?? 0) < VENTE_CROSS_MIN_TICKETS) { continue; }
            $barres[(string) $l['shopId']][] = ['nom' => (string) $l['nom'],
                'record' => venteRecordVendeuse($parMoisAff, (string) $l['id'], $m)];
        }
    }
    foreach ($barres as $sidB => $liste) {
        usort($liste, static fn ($a, $b) => ($b['record'] ?? 0) <=> ($a['record'] ?? 0));
        $barres[$sidB] = $liste;
    }
    $podiums = []; $reseauPrec = null; $gestePrec = [];
    if ($rPrec['motif'] === null) {
        foreach ($rPrec['lignes'] as $l) {
            if (!$l['classable']) { continue; }
            if ($reseauPrec === null) { $reseauPrec = $l; }
            $sid2 = (string) $l['shopId'];
            if (count($podiums[$sid2] ?? []) < 3) { $podiums[$sid2][] = $l; }
            // Les records battus du mois passé se lisent dans l'historique
            // enregistré — le calcul complet vit dans l'enregistrement.

        }
    }

    // LA 2e PAGE — le détail : le dernier mois SERVI par la table des
    // transactions (le mois dernier peut être vide), son top réseau et le
    // classement complet de chaque magasin. Même recul que les barres.
    $mDet = null; $lignesDet = null;
    for ($recD = 1; $recD <= 3; $recD++) {
        $mD = date('Y-m', strtotime($m . '-01 -' . $recD . ' month'));
        if (($parMoisAff[$mD] ?? null) !== null && $parMoisAff[$mD] !== []) { $mDet = $mD; $lignesDet = $parMoisAff[$mD]; break; }
    }
    $creneauxDet = $mDet !== null ? ($crenParMois[$mDet] ?? null) : null;
    $topReseau = $lignesDet === null ? []
        : array_slice(array_values(array_filter($lignesDet, static fn ($l5) => $l5['classable'])), 0, 5);

    // LA 3e PAGE — qui a TOUCHÉ quelle prime : les enregistrements réels
    // (pas un calcul du moment), au dernier mois enregistré de chaque
    // famille. Les montants affichés sont ceux payés à l'enregistrement.
    $histSco = setting('ventePrimesHist');
    $dernSco = is_array($histSco) && $histSco !== [] ? $histSco[max(array_keys($histSco))] : null;
    $histRec = setting('ventePrimesCrossHist');
    $dernRec = is_array($histRec) && $histRec !== [] ? $histRec[max(array_keys($histRec))] : null;

    if ($seulShop !== '') {
        $nomDe = array_filter($nomDe, fn ($sid2) => (string) $sid2 === $seulShop, ARRAY_FILTER_USE_KEY);
    }
    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .or{color:#8a5a1c}.acc{color:#8D1D2C}.mut{color:#7a736a}
      .carte{border:1.5px solid #E8C9A0;background:#FFF9EC;border-radius:12px;padding:6mm 7mm;text-align:center}
      .carte .gros{font-family:Georgia,"DejaVu Serif",serif;font-size:30pt;color:#8D1D2C;line-height:1.1}
      .marche{border:1.5px solid #E8C9A0;background:#FFF9EC;border-radius:10px;text-align:center;padding:4mm 3mm}
      .marche .v{font-family:Georgia,"DejaVu Serif",serif;font-size:19pt;color:#8D1D2C}
      .regle{font-size:9pt;color:#7a736a;line-height:1.7}
    </style>';

    $h = $css;
    $premier = true;
    foreach ($nomDe as $sid => $nom) {
        $h .= '<div class="doc"' . ($premier ? '' : ' style="page-break-before:always"') . '>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2.5px solid #8D1D2C;padding-bottom:3mm"><tr>'
            . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:38px">' : '<b>L’Atelier by</b>') . '</td>'
            . '<td align="right" style="font-size:9pt;color:#7a736a;line-height:1.6"><b style="color:#221E1A">' . $e($court($nom)) . '</b><br>' . $e($libMois) . '</td></tr></table>'

            . '<div class="serif" style="font-size:26pt;margin:7mm 0 1mm;letter-spacing:-.01em">Ce qu’il y a à gagner ce mois-ci</div>'
            . '<div style="font-size:11pt;color:#5d564e;margin-bottom:7mm">Jusqu’à <b class="acc">' . $maxTotal . ' €</b> de primes — payées en bons par la marque, jamais par le magasin, et cumulables.</div>'

            . '<table width="100%" cellpadding="0" cellspacing="6" style="margin:0 -1.5mm 6mm"><tr>'
            . '<td width="50%" class="carte"><div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="or">🏆 Meilleure vendeuse du réseau</div>'
            . '<div class="gros">' . (int) $primes['reseau'] . ' €</div>'
            . '<div class="regle">Le meilleur score du mois, tous magasins confondus.</div></td>'
            . '<td width="50%" class="carte"><div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="or">🥇 Meilleure vendeuse du magasin</div>'
            . '<div class="gros">' . (int) $primes['magasin'] . ' €</div>'
            . '<div class="regle">Le meilleur score de ' . $e($court($nom)) . ' ce mois-ci.</div></td>'
            . '</tr></table>'
            . '<div class="regle" style="margin-bottom:7mm">Le score est <b>juste</b> : votre chiffre rapporté à vos heures du planning, et vendre l’après-midi ou en semaine — quand c’est difficile — compte davantage que le rush du samedi matin. Peu d’heures ou beaucoup, chacun a sa chance.</div>'

            . '<div class="serif" style="font-size:15pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:3mm">Le geste qui paie : proposez ! La boisson, le dessert, le cookie…</div>'
            . '<div style="font-size:10pt;color:#5d564e;margin-bottom:4mm"><b>Bats ton record</b> — ta meilleure moyenne des 12 derniers mois est ta barre. Le premier dixi&#232;me au-dessus pose ton nouveau record ; <b class="acc">&#224; partir du deuxi&#232;me, chaque dixi&#232;me = ' . $regAff['eurDixieme'] . ' €</b> pour toi, en bons.</div>'
            . '<table width="100%" cellpadding="0" cellspacing="6" style="margin:0 -1.5mm 5mm"><tr>'
            . '<td width="25%" class="marche"><div style="font-size:9pt" class="mut">+ 0,1</div><div class="v" style="color:#7a736a">nouveau record</div></td>';
        for ($t4 = 1; $t4 <= 3; $t4++) {
            $paye4 = min($t4, $regAff['maxDixiemes']);
            $h .= '<td width="25%" class="marche"><div style="font-size:9pt" class="mut">+ 0,' . ($t4 + 1) . '</div>'
                . '<div class="v">' . ($paye4 * $regAff['eurDixieme']) . ' €'
                . ($t4 > $regAff['maxDixiemes'] ? ' <span style="font-size:7pt;color:#7a736a">(plafond)</span>' : '') . '</div></td>';
        }
        $h .= ''
            . '</tr></table>'
            . '<div class="regle" style="margin-bottom:5mm">Payé jusqu&#8217;&#224; ' . $regAff['maxDixiemes'] . ' dixi&#232;mes par mois — et ton nouveau record devient ta barre : on ne paie jamais deux fois le m&#234;me progr&#232;s.</div>';

        // LES BARRES À BATTRE — la barre nominative de chaque vendeuse active.
        $n2f = static fn ($v) => number_format((float) $v, 2, ',', ' ');
        $h .= '<div class="serif" style="font-size:15pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:3mm">Les barres &#224; battre ce mois-ci</div>'
            . '<table width="100%" cellpadding="0" cellspacing="3" style="margin:0 0 4mm">';
        $listeB = $barres[(string) $sid] ?? [];
        if ($listeB === []) {
            $h .= '<tr><td class="regle">Les barres nominatives appara&#238;tront d&#232;s un mois d&#8217;historique (30 tickets au moins).</td></tr>';
        } else {
            foreach (array_chunk($listeB, 3) as $rangee) {
                $h .= '<tr>';
                foreach ($rangee as $b2) {
                    $h .= '<td width="33%" style="border:1px solid #e6e0d8;background:#fbf9f5;border-radius:8px;padding:2.2mm 3mm">'
                        . '<span style="font-size:9.5pt"><b>' . $e($b2['nom']) . '</b></span>'
                        . '<span style="float:right;font-family:Georgia,serif;font-size:10.5pt">'
                        . ($b2['record'] !== null
                            ? '<span style="color:#7a736a">' . $n2f($b2['record']) . '</span> &#8594; <b style="color:#8D1D2C">prime d&#232;s ' . $n2f($b2['record'] + 0.2) . '</b>'
                            : '<b style="color:#8D1D2C">pose ta barre ce mois-ci</b>') . '</span></td>';
                }
                for ($v4 = count($rangee); $v4 < 3; $v4++) { $h .= '<td width="33%"></td>'; }
                $h .= '</tr>';
            }
        }
        $h .= '</table>'
            . '<div class="regle" style="margin-bottom:6mm">Ta barre = ta meilleure moyenne des 12 derniers mois. Personne d&#8217;autre &#224; battre que toi-m&#234;me.</div>'

            . (($podiums[(string) $sid] ?? []) !== [] ? '<div style="border:1px solid #e6e0d8;background:#fbf9f5;border-radius:10px;padding:4mm 5mm;margin-bottom:6mm">'
                . '<div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="mut">📋 Le classement de ' . $e($libPrec) . ' — à détrôner</div>'
                . '<div style="font-size:10.5pt;margin-top:2mm;line-height:1.8">'
                . implode('<br>', array_map(static function ($i2, $l2) {
                    return ($i2 + 1) . '. <b>' . htmlspecialchars((string) $l2['nom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '</b> — score ' . (int) $l2['score']
                        . ' <span style="color:#7a736a;font-size:8.5pt">(' . number_format((float) $l2['lignesTicket'], 1, ',', ' ') . ' lignes/ticket)</span>';
                }, array_keys($podiums[(string) $sid]), $podiums[(string) $sid]))
                . '</div>'
                . ($reseauPrec !== null ? '<div style="font-size:9pt;margin-top:2mm" class="mut">🏆 Meilleure du réseau en ' . $e($libPrec) . ' : <b style="color:#221E1A">'
                    . $e($reseauPrec['nom']) . '</b> (' . $e($court($reseauPrec['magasin'])) . ', score ' . (int) $reseauPrec['score'] . ')'
                    . (($gestePrec[(string) $sid] ?? 0) > 0 ? ' · ' . $gestePrec[(string) $sid] . ' prime(s) du geste décrochée(s) ici' : '') . '</div>' : '')
                . '</div>' : '')
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e6e0d8;margin-top:1mm"><tr>'
            . '<td style="padding-top:3mm"><div class="regle">Les règles, simplement : au moins 30 tickets dans le mois · les primes se versent une fois le mois fini, <b>en bons payés par la marque</b> — jamais par le magasin · la meilleure du réseau ne cumule pas la prime magasin, mais les primes du record s’ajoutent toujours · tout est vérifiable dans le cockpit, la formule est affichée. Bonne chasse ! — L’Atelier by, ' . $e($libMois) . '</div></td>'
            . '<td width="150" align="right" style="padding-top:3mm;padding-left:4mm">'
            . '<table cellpadding="0" cellspacing="0"><tr>'
            . '<td style="text-align:right;padding-right:2.5mm;font-size:8pt;color:#5d564e;line-height:1.5"><b>Pour suivre vos objectifs,</b><br>rendez-vous sur votre compte<br>sur l&#8217;appli du personnel &#8594;</td>'
            . '<td><img src="data:image/png;base64,' . VENTE_APPLI_QR . '" style="width:19mm;height:19mm"></td>'
            . '</tr></table></td>'
            . '</tr></table>'
            . '</div>';

        // LA 2e A4 DU MAGASIN — le détail : qui a gagné quoi le mois dernier
        // servi, avec les chiffres derrière chaque prime, et le classement
        // complet du magasin. La page qui répond à « et moi, j'en suis où ? ».
        if ($lignesDet !== null) {
            $libDet = strftime_fr(strtotime($mDet . '-01'), 'M Y');
            $n2d = static fn ($v) => number_format((float) $v, 2, ',', ' ');
            $eur0f = static fn ($v) => number_format((float) $v, 0, ',', ' ');
            // Sans vente à son nom, pas de ligne : la page parle de ce qui
            // s'est vendu, pas du planning de la production.
            $duShop = array_values(array_filter($lignesDet,
                fn ($l5) => (string) $l5['shopId'] === (string) $sid && (float) $l5['ca'] > 0));
            $meilleure = null;
            foreach ($duShop as $l5) { if ($l5['classable']) { $meilleure = $l5; break; } }
            $reseau1 = $topReseau[0] ?? null;
            $ficheDe = static function (?array $l5, string $titre, int $prime) use ($e, $eur0f, $n1, $court) {
                return '<td width="50%" class="carte" style="text-align:left">'
                    . '<div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="or">' . $titre . '</div>'
                    . ($l5 === null ? '<div class="regle" style="margin-top:2mm">pas de classement ce mois-l&#224;</div>'
                        : '<div class="serif" style="font-size:18pt;color:#8D1D2C;margin:1.5mm 0 1mm">' . $e($l5['nom']) . '</div>'
                        . '<div style="font-size:10.5pt;margin-bottom:1.5mm"><b class="acc">score ' . (int) $l5['score'] . '</b> &#183; '
                        . $e($court((string) $l5['magasin'])) . ' &#183; <b>' . $prime . ' € en bons</b></div>'
                        . '<div class="regle">' . $eur0f($l5['ca']) . ' € de CA &#183; ' . $n1($l5['heures']) . ' h au planning &#183; '
                        . $eur0f($l5['caHeure']) . ' €/h'
                        . ($l5['lignesTicket'] !== null ? ' &#183; ' . $n1($l5['lignesTicket']) . ' lignes/ticket' : '')
                        . ($l5['coefCreneau'] !== null ? ' &#183; coef cr&#233;neau &#215; ' . number_format((float) $l5['coefCreneau'], 2, ',', ' ') : '') . '</div>')
                    . '</td>';
            };
            $thD = 'font-size:8pt;color:#7a736a;text-transform:uppercase;letter-spacing:.06em;padding:1.5mm 2mm;border-bottom:1pt solid #e6e0d8';
            $tdD = 'padding:1.6mm 2mm;border-bottom:0.5pt solid #efe9e0;font-size:9.5pt';
            $h .= '<div class="doc" style="page-break-before:always">'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2.5px solid #8D1D2C;padding-bottom:3mm"><tr>'
                . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:38px">' : '<b>L’Atelier by</b>') . '</td>'
                . '<td align="right" style="font-size:9pt;color:#7a736a;line-height:1.6"><b style="color:#221E1A">' . $e($court($nom)) . '</b><br>' . $e($libMois) . '</td></tr></table>'
                . '<div class="serif" style="font-size:22pt;margin:6mm 0 1mm;letter-spacing:-.01em">Le d&#233;tail de ' . $e($libDet) . '</div>'
                . '<div style="font-size:10.5pt;color:#5d564e;margin-bottom:5mm">Qui a gagn&#233; quoi, avec quels chiffres — pour savoir exactement quoi battre ce mois-ci.</div>'
                . '<table width="100%" cellpadding="0" cellspacing="6" style="margin:0 -1.5mm 5mm"><tr>'
                . $ficheDe($reseau1, '🏆 Meilleure vendeuse du r&#233;seau', (int) $primes['reseau'])
                . $ficheDe($meilleure, '🥇 Meilleure vendeuse du magasin', (int) $primes['magasin'])
                . '</tr></table>'

                . '<div class="serif" style="font-size:14pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:2mm">Le classement de ' . $e($court($nom)) . ' — ' . $e($libDet) . '</div>'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:5mm">'
                . '<tr><td style="' . $thD . '" width="8%">&nbsp;</td><td style="' . $thD . '">Vendeuse</td>'
                . '<td style="' . $thD . '" align="right">Score</td><td style="' . $thD . '" align="right">CA</td>'
                . '<td style="' . $thD . '" align="right">Heures</td><td style="' . $thD . '" align="right">CA/h</td>'
                . '<td style="' . $thD . '" align="right">Lignes/ticket</td></tr>';
            if ($duShop === []) {
                $h .= '<tr><td colspan="7" class="regle" style="padding:2mm">aucune vendeuse class&#233;e ce mois-l&#224;</td></tr>';
            }
            foreach ($duShop as $l5) {
                $prem5 = ($l5['rang'] ?? 0) === 1;
                $h .= '<tr' . ($prem5 ? ' style="background:#FFF9EC"' : '') . '>'
                    . '<td style="' . $tdD . ';color:#7a736a">' . ($l5['rang'] !== null ? $l5['rang'] . '.' : '&#8212;') . '</td>'
                    . '<td style="' . $tdD . '"><b>' . $e($l5['nom']) . '</b>'
                    . ($l5['classable'] ? '' : ' <span style="font-size:8pt;color:#7a736a">' . $e((string) $l5['motifHorsClassement']) . '</span>') . '</td>'
                    . '<td style="' . $tdD . '" align="right">' . ($l5['score'] !== null ? '<b' . ($prem5 ? ' class="acc"' : '') . '>' . (int) $l5['score'] . '</b>' : '&#8212;') . '</td>'
                    . '<td style="' . $tdD . '" align="right">' . $eur0f($l5['ca']) . ' €</td>'
                    . '<td style="' . $tdD . '" align="right">' . $n1($l5['heures']) . ' h</td>'
                    . '<td style="' . $tdD . '" align="right">' . ($l5['caHeure'] !== null ? $eur0f($l5['caHeure']) . ' €' : '&#8212;') . '</td>'
                    . '<td style="' . $tdD . '" align="right">' . ($l5['lignesTicket'] !== null ? $n1($l5['lignesTicket']) : '&#8212;') . '</td></tr>';
            }
            $h .= '</table>'

                . '<div class="serif" style="font-size:14pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:2mm">Les meilleures du r&#233;seau — ' . $e($libDet) . '</div>'
                . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:5mm">';
            foreach ($topReseau as $i5 => $l5) {
                $h .= '<tr' . ($i5 === 0 ? ' style="background:#FFF9EC"' : '') . '>'
                    . '<td style="' . $tdD . ';color:#7a736a" width="8%">' . ($i5 + 1) . '.</td>'
                    . '<td style="' . $tdD . '"><b>' . $e($l5['nom']) . '</b></td>'
                    . '<td style="' . $tdD . '">' . $e($court((string) $l5['magasin'])) . '</td>'
                    . '<td style="' . $tdD . '" align="right"><b' . ($i5 === 0 ? ' class="acc"' : '') . '>score ' . (int) $l5['score'] . '</b></td>'
                    . '<td style="' . $tdD . '" align="right">' . ($l5['lignesTicket'] !== null ? $n1($l5['lignesTicket']) . ' lignes/ticket' : '') . '</td></tr>';
            }
            // LA VIGNETTE « comment on calcule » — la formule et le
            // coefficient de créneau expliqués avec les intensités MESURÉES
            // du mois détaillé : rien de magique, tout se vérifie.
            // Les cases montrent le COEFFICIENT de chaque créneau (× 1,xx),
            // pas les €/h bruts : la moyenne du mois divisée par l'intensité
            // du créneau, bornée comme dans le calcul du score.
            $glDet = ($creneauxDet['global'] ?? 0) > 0 ? (float) $creneauxDet['global'] : null;
            $celC = static function (string $lib, ?int $v) use ($glDet) {
                $coef = ($glDet !== null && $v !== null && $v > 0)
                    ? max(VENTE_COEF_CRENEAU_MIN, min(VENTE_COEF_CRENEAU_MAX, $glDet / $v)) : null;
                return '<td width="25%" style="border:1px solid #e6e0d8;background:#fff;border-radius:6px;padding:1.8mm 2mm;text-align:center">'
                    . '<div style="font-size:7.5pt;color:#7a736a">' . $lib . '</div>'
                    . '<div style="font-family:Georgia,serif;font-size:11pt;color:#221E1A">'
                    . ($coef !== null ? '&#215; ' . number_format($coef, 2, ',', ' ') : '&#8211;') . '</div></td>';
            };
            $h .= '</table>'
                . '<div style="border:1.5px solid #E8C9A0;background:#FFF9EC;border-radius:10px;padding:4mm 5mm;margin-bottom:4mm">'
                . '<div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="or">🧮 Comment on calcule le score et le coefficient</div>'
                . '<div class="serif" style="font-size:13pt;margin:2mm 0 1.5mm">score = CA &#247; (heures du planning + ' . VENTE_LISSAGE_HEURES . ') &#215; coefficient de cr&#233;neau</div>'
                . '<div class="regle" style="margin-bottom:2mm">Le &#171;&#8202;+ ' . VENTE_LISSAGE_HEURES . '&#8202;&#187; lisse les heures : peu d&#8217;heures ou beaucoup, chacun a sa chance, personne ne gagne juste parce qu&#8217;il a un petit contrat. '
                . 'Le <b>coefficient de cr&#233;neau</b> compare la difficult&#233; de VOS heures &#224; la moyenne du r&#233;seau, cr&#233;neau par cr&#233;neau. Votre score est multipli&#233; par le coefficient de vos cr&#233;neaux'
                . ($creneauxDet !== null ? ', mesur&#233;s en ' . $e($libDet) . ' :' : '.') . '</div>'
                . ($creneauxDet !== null
                    ? '<table width="100%" cellpadding="0" cellspacing="4" style="margin-bottom:2mm"><tr>'
                        . $celC('Semaine matin', $creneauxDet['matSem'] ?? null)
                        . $celC('Semaine apr&#232;s-midi', $creneauxDet['amSem'] ?? null)
                        . $celC('Week-end matin', $creneauxDet['matWe'] ?? null)
                        . $celC('Week-end apr&#232;s-midi', $creneauxDet['amWe'] ?? null)
                        . '</tr></table>' : '')
                . '<div class="regle">Vendre sur les cr&#233;neaux creux (la semaine, l&#8217;apr&#232;s-midi) <b>multiplie</b> votre score ; le rush du samedi matin compte un peu moins. Le coefficient reste born&#233; entre &#215; ' . number_format(VENTE_COEF_CRENEAU_MIN, 1, ',', '') . ' et &#215; ' . number_format(VENTE_COEF_CRENEAU_MAX, 1, ',', '') . ' : les cr&#233;neaux ne font jamais tout, vendre reste l&#8217;essentiel.</div>'
                . '</div>'
                . '<div class="regle">Tout est v&#233;rifiable dans le cockpit : la formule, les coefficients du mois et le d&#233;tail de chacune. L&#8217;Atelier by, ' . $e($libMois) . '</div>'
                . '</div>';
        }

        // LA 3e A4 DU MAGASIN — qui a touché quelle prime : la preuve que ça
        // paie vraiment. Uniquement les primes ENREGISTRÉES, aux montants de
        // l'enregistrement ; sans enregistrement, la page le dit simplement.
        $thP = 'font-size:8pt;color:#7a736a;text-transform:uppercase;letter-spacing:.06em;padding:1.5mm 2mm;border-bottom:1pt solid #e6e0d8';
        $tdP = 'padding:1.8mm 2mm;border-bottom:0.5pt solid #efe9e0;font-size:10pt';
        $n2p = static fn ($v) => number_format((float) $v, 2, ',', ' ');
        $h .= '<div class="doc" style="page-break-before:always">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2.5px solid #8D1D2C;padding-bottom:3mm"><tr>'
            . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" style="height:38px">' : '<b>L’Atelier by</b>') . '</td>'
            . '<td align="right" style="font-size:9pt;color:#7a736a;line-height:1.6"><b style="color:#221E1A">' . $e($court($nom)) . '</b><br>' . $e($libMois) . '</td></tr></table>'
            . '<div class="serif" style="font-size:22pt;margin:6mm 0 1mm;letter-spacing:-.01em">Qui a touch&#233; quelle prime</div>'
            . '<div style="font-size:10.5pt;color:#5d564e;margin-bottom:5mm">Les primes enregistr&#233;es, noir sur blanc : &#231;a paie vraiment, en bons de la marque.</div>';

        // Les primes au score du dernier mois enregistré.
        if ($dernSco !== null) {
            $libSco = strftime_fr(strtotime((string) $dernSco['m'] . '-01'), 'M Y');
            $mntSco = (array) ($dernSco['montants'] ?? []);
            $res3 = (array) ($dernSco['reseau'] ?? []);
            $gagM3 = null;
            foreach ((array) ($dernSco['magasins'] ?? []) as $g3) {
                if ((string) ($g3['magasin'] ?? '') === (string) $nom) { $gagM3 = $g3; break; }
            }
            $resIci = (string) ($res3['magasin'] ?? '') === (string) $nom;
            $h .= '<div class="serif" style="font-size:14pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:2.5mm">Les primes au score, ' . $e($libSco) . '</div>'
                . '<table width="100%" cellpadding="0" cellspacing="6" style="margin:0 -1.5mm 5mm"><tr>'
                . '<td width="50%" class="carte" style="text-align:left">'
                . '<div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="or">🏆 Meilleure vendeuse du r&#233;seau</div>'
                . '<div class="serif" style="font-size:18pt;color:#8D1D2C;margin:1.5mm 0 1mm">' . $e((string) ($res3['nom'] ?? '')) . '</div>'
                . '<div style="font-size:10.5pt"><b>' . (int) ($mntSco['reseau'] ?? 0) . ' € en bons</b> &#183; ' . $e($court((string) ($res3['magasin'] ?? ''))) . ' &#183; ' . (int) ($res3['caHeure'] ?? 0) . ' €/h</div></td>'
                . '<td width="50%" class="carte" style="text-align:left">'
                . '<div style="font-size:9pt;letter-spacing:.09em;text-transform:uppercase" class="or">🥇 Meilleure vendeuse de ' . $e($court($nom)) . '</div>'
                . ($gagM3 !== null
                    ? '<div class="serif" style="font-size:18pt;color:#8D1D2C;margin:1.5mm 0 1mm">' . $e((string) $gagM3['nom']) . '</div>'
                        . '<div style="font-size:10.5pt"><b>' . (int) ($mntSco['magasin'] ?? 0) . ' € en bons</b> &#183; ' . (int) ($gagM3['caHeure'] ?? 0) . ' €/h</div>'
                    : ($resIci
                        ? '<div class="serif" style="font-size:18pt;color:#8D1D2C;margin:1.5mm 0 1mm">' . $e((string) ($res3['nom'] ?? '')) . '</div>'
                            . '<div style="font-size:10.5pt">d&#233;j&#224; prim&#233;e au r&#233;seau : les primes du score ne se cumulent pas, la sienne est la grosse.</div>'
                        : '<div class="regle" style="margin-top:2mm">pas de gagnante class&#233;e ce mois-l&#224;</div>'))
                . '</td></tr></table>';
        } else {
            $h .= '<div class="regle" style="margin-bottom:5mm">Les primes au score s&#8217;afficheront ici d&#232;s le premier enregistrement d&#8217;un mois fini.</div>';
        }

        // Les primes « Bats ton record » du dernier mois enregistré.
        if ($dernRec !== null) {
            $libRec = strftime_fr(strtotime((string) $dernRec['m'] . '-01'), 'M Y');
            $gagR3 = array_values(array_filter((array) ($dernRec['gagnantes'] ?? []),
                fn ($g3) => (string) ($g3['magasin'] ?? '') === (string) $nom));
            $h .= '<div class="serif" style="font-size:14pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:2mm">Bats ton record, ' . $e($libRec) . '</div>';
            if ($gagR3 === []) {
                $h .= '<div class="regle" style="margin-bottom:5mm">Aucun record battu &#224; ' . $e($court($nom)) . ' ce mois-l&#224; : toutes les barres restent &#224; prendre.</div>';
            } else {
                $tot3 = 0;
                $h .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:2mm">'
                    . '<tr><td style="' . $thP . '">Vendeuse</td><td style="' . $thP . '" align="right">Son record</td>'
                    . '<td style="' . $thP . '" align="right">Sa moyenne du mois</td><td style="' . $thP . '" align="right">Dixi&#232;mes pay&#233;s</td>'
                    . '<td style="' . $thP . '" align="right">Prime</td></tr>';
                foreach ($gagR3 as $g3) {
                    $tot3 += (int) ($g3['prime'] ?? 0);
                    $h .= '<tr><td style="' . $tdP . '"><b>' . $e((string) $g3['nom']) . '</b></td>'
                        . '<td style="' . $tdP . '" align="right">' . ($g3['record'] !== null ? $n2p($g3['record']) : 'premi&#232;re barre') . '</td>'
                        . '<td style="' . $tdP . '" align="right"><b>' . $n2p($g3['lignesTicket'] ?? 0) . '</b></td>'
                        . '<td style="' . $tdP . '" align="right">' . (int) ($g3['tranches'] ?? 0) . '</td>'
                        . '<td style="' . $tdP . '" align="right"><b class="acc">' . (int) ($g3['prime'] ?? 0) . ' €</b></td></tr>';
                }
                $h .= '<tr><td style="' . $tdP . ';border-bottom:none" colspan="4"><b>Total ' . $e($court($nom)) . '</b></td>'
                    . '<td style="' . $tdP . ';border-bottom:none" align="right"><b class="acc">' . $tot3 . ' €</b></td></tr></table>';
            }
        } else {
            $h .= '<div class="serif" style="font-size:14pt;border-bottom:1.5pt solid #8D1D2C;padding-bottom:1.5mm;margin-bottom:2mm">Bats ton record</div>'
                . '<div class="regle" style="margin-bottom:5mm">Le syst&#232;me d&#233;marre : les premi&#232;res primes du record s&#8217;afficheront ici d&#232;s l&#8217;enregistrement du premier mois fini. Vos barres sont pos&#233;es (page pr&#233;c&#233;dente), &#224; vous de jouer.</div>';
        }

        $h .= '<div class="regle" style="border-top:1px solid #e6e0d8;padding-top:3mm">Toutes les primes sont pay&#233;es en bons par la marque, jamais par le magasin. Les enregistrements se font une fois le mois fini et se retrouvent au journal du cockpit. L&#8217;Atelier by, ' . $e($libMois) . '</div>'
            . '</div>';
        $premier = false;
    }

    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Primes — ' . $e($libMois) . '</title></head><body>' . $h . '</body></html>';
    $labelA = 'Réseau';
    $nomA = 'affiche-primes-' . $m;
    if ($seulShop !== '' && $nomDe !== []) { $labelA = $court((string) reset($nomDe)); $nomA .= '-' . mktSlug($labelA); }
    $pdf = rapPdfRendu($doc, ['magasin' => $labelA, 'rapport' => 'Affiche des primes ' . $libMois,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { return null; }
    return ['pdf' => $pdf, 'nom' => $nomA . '.pdf'];
}

/** GET /ventes/affiche.pdf?m=2026-09&shop=2 */
function ep_ventes_affiche(): array
{
    $r = venteAffichePdf(trim((string) ($_GET['m'] ?? date('Y-m'))), trim((string) ($_GET['shop'] ?? '')));
    if ($r === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $r['nom'] . '"');
    echo $r['pdf'];
    exit;
}

/**
 * POST /ventes/envoi-test {email, shop?, m?} — le colis mensuel d'un magasin,
 * envoyé à une adresse : l'affiche du mois demandé + le rapport de clôture du
 * mois précédent servi. Le test grandeur nature du reporting.
 */
function wr_ventes_envoi_test(): array
{
    $b = body();
    $email = trim((string) ($b['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); return ['error' => 'email invalide']; }
    if (!Smtp::configured()) { http_response_code(503); return ['error' => 'SMTP non configuré (Paramètres)']; }
    $shop = trim((string) ($b['shop'] ?? ''));
    $m = trim((string) ($b['m'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) { $m = date('Y-m'); }

    // UN SEUL fichier : « Ce qu'il y a à gagner ce mois-ci » — l'affiche,
    // avec les barres à battre nominatives. La clôture a son propre envoi.
    $aff = venteAffichePdf($m, $shop);
    if ($aff === null) { http_response_code(503); return ['error' => 'le PDF n’a pas pu être rendu']; }
    $pieces = [['nom' => $aff['nom'], 'type' => 'application/pdf', 'contenu' => $aff['pdf']]];
    $libM = strftime_fr(strtotime($m . '-01'), 'M Y');
    $corps = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#222;line-height:1.6">'
        . '<p>Bonjour,</p><p>Voici <b>« Ce qu’il y a à gagner ce mois-ci »</b> — ' . htmlspecialchars($libM)
        . ' : l’affiche à imprimer pour le vestiaire, avec la barre à battre de chaque vendeuse.</p>'
        . '<p style="color:#7a736a;font-size:11px">Envoyé depuis le cockpit.</p></div>';
    $ok = Smtp::envoyer($email, 'Ce qu’il y a à gagner ce mois-ci — ' . $libM, $corps, $pieces);
    journalAdd('CEO', 'Vente', 'Envoi test', ($ok ? 'Envoyé' : 'Échec') . ' à ' . $email . ' — ' . count($pieces) . ' PDF (' . $m . ($shop !== '' ? ', magasin ' . $shop : '') . ')');
    if (!$ok) { http_response_code(502); return ['error' => 'envoi refusé — ' . (string) Smtp::$lastError]; }
    return ['ok' => true, 'pieces' => array_column($pieces, 'nom')];
}
