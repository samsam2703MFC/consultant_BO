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
function ep_ventes_pdf(): array
{
    $d = ep_ventes_classement();
    if (($d['motif'] ?? null) !== null) { http_response_code(422); return ['error' => $d['motif']]; }
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
        . 'Panier = CA ÷ tickets · cross-selling = lignes par ticket (30 tickets au moins pour le top 10). '
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
        . $colonne('Top 10 — Lignes / ticket', 'le cross-selling — 30 tickets au moins', $parLt,
            static fn ($l) => $n1($l['lignesTicket']), static fn ($l) => $l['tickets'] . ' tkt')
        . '</tr></table>' . $methode;

    // --- Page 2 : le classement complet.
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
                . '<td class="l">' . ($l['rang'] !== null ? (int) $l['rang'] : '—') . '</td>'
                . '<td class="l"><b>' . $e($l['nom']) . '</b>'
                . ($l['classable'] ? '' : ' <span style="font-size:7pt;color:#9a9186">· ' . $e($l['motifHorsClassement']) . '</span>') . '</td>'
                . ($avecMagasin ? '<td class="l mut">' . $e($court($l['magasin'])) . '</td>' : '')
                . '<td>' . $n1($l['heures']) . ' h</td>'
                . '<td>' . $eur($l['ca']) . '</td>'
                . '<td>' . ($l['caHeure'] !== null ? $eur($l['caHeure']) : '—') . '</td>'
                . '<td class="mut">' . ($l['coef'] !== null ? number_format((float) $l['coef'], 2, ',', ' ') : '—') . '</td>'
                . '<td class="mut">' . ($l['coefCreneau'] !== null ? number_format((float) $l['coefCreneau'], 2, ',', ' ') : '—') . '</td>'
                . '<td class="acc"><b>' . ($l['score'] !== null ? $eur($l['score']) : '—') . '</b></td>'
                . '<td>' . ($l['panier'] !== null ? number_format((float) $l['panier'], 2, ',', ' ') . ' €' : '—') . '</td>'
                . '<td>' . ($l['lignesTicket'] !== null ? $n1($l['lignesTicket']) : '—') . '</td>'
                . '<td>' . number_format((float) $l['tickets'], 0, ',', ' ') . '</td>'
                . '<td class="l or" style="font-size:7.4pt"><b>' . $e($prime) . '</b></td></tr>';
        }
        return $h2 . '</table>';
    };

    // Le classement complet ne liste QUE les classées : les grisés y faisaient
    // du bruit sans rien classer. Personne ne disparaît pour autant — chacun
    // reste sur la page de SON magasin, avec son motif.
    $h .= '<div style="page-break-before:always">' . $entete($e($libMois))
        . '<div class="serif h1">Le classement complet</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">' . count($classables)
        . ' classé(e)s au score. Les personnes sans heures au planning ou sans vente à leur nom ne figurent que sur la page de leur magasin.</div>'
        . $tableau($classables, true) . $methode . '</div>';

    // --- La feuille à part : le croisement Flip & Flap × boissons.
    [$duX, $auX] = venteBornes($d['m']);
    $x = venteCroisementFF($duX, $auX, array_column($d['magasins'], 'nom', 'id'));
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
                . '<td>' . ($petits['ff'] > 0 ? number_format(100 * $petits['avec'] / $petits['ff'], 1, ',', ' ') : '—') . ' %</td>'
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
        $siens = array_values(array_filter($d['lignes'], static fn ($l) => (string) $l['shopId'] === (string) $mag['id']));
        if ($siens === []) { continue; }
        $h .= '<div style="page-break-before:always">' . $entete($e($court($mag['nom'])) . ' · ' . $e($libMois))
            . '<div class="serif h1">' . $e($court($mag['nom'])) . ' — l’équipe de vente</div>'
            . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Les mêmes mesures que la page réseau, resserrées sur l’équipe — la feuille du brief du mois.</div>'
            . $tableau($siens, false) . $methode . '</div>';
    }

    $h .= '</div>';


    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Target de vente — ' . $e($libMois) . '</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => 'Réseau', 'rapport' => 'Target de vente — ' . $libMois,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="target-vente-' . $d['m'] . '.pdf"');
    echo $pdf;
    exit;
}
