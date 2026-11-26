<?php
declare(strict_types=1);

/**
 * COMMANDES — les commandes du magasin (webshop, comptoir, bureau), liées aux tournées et aux bureaux à livrer
 * (24/11/2026 ; maquettes panel/docs/maquettes/commandes-tournees/, versions A et B retenues sur un interrupteur abricot).
 *
 * - LES COMMANDES DU JOUR : lues au panel (`/shops/{id}/client-orders?date_from=&date_to=`, le jour et le lendemain),
 *   jamais gardées ici (elles portent le nom du client). Trois canaux : BUREAU (une offre ou un département de bureau, ou
 *   le mode « office »), WEBSHOP (`is_webshop`), sinon COMPTOIR (prise au magasin, au téléphone, par mail). Une commande
 *   annulée (`non_collection_id_reason`) ne compte pas.
 *   GET /dashboard/commandes/jour?shop=&date=
 * - LES TOURNÉES : le panel n'en a pas (mesuré le 24/11/2026 : aucune route de tournée, de bureau ni de département ;
 *   `office_id`, `department_id` vides sur toutes les fiches clients). Elles se tiennent ici, magasin par magasin
 *   (panel_tournee) : un nom, un départ, un retour, un livreur, un véhicule, des jours ; et leurs bureaux dans l'ordre de
 *   passage (panel_tournee_bureau : un client pro du panel, une consigne). Une commande d'un bureau va d'elle-même dans
 *   la tournée de ce bureau qui roule ce jour-là ; le magasin peut la mettre dans une autre, ou la garder au magasin
 *   (panel_tournee_commande, le choix d'une commande).
 *   POST /dashboard/commandes/tournees {shop, nom, depart, retour, livreur, vehicule, jours} ; PUT {shop, id, …} ;
 *   DELETE {shop, id} ; POST /dashboard/commandes/tournees/bureaux {shop, tournee, bureaux: [{client, nom, consigne}]} ;
 *   POST /dashboard/commandes/affecter {shop, commande, date, tournee: id | 0 (au magasin) | null (automatique)} ;
 *   GET /dashboard/commandes/bureaux?shop=&q= (les clients pro du magasin, pour composer une tournée).
 * - LA SEMAINE ET LE MOIS (24/11/2026, « faire une vue semaine et mois (en plus) ») : les mêmes commandes, jour par jour,
 *   par tournée et par bureau, face à la période d'avant (la même longueur, juste avant).
 *   GET /dashboard/commandes/periode?shop=&du=&au= (31 jours au plus)
 */

const PCO_COUPURE = '16:00';        // commander jusqu'à la veille 16 h (les modèles du portail Commercial)
const PCO_BUREAUX_CACHE_H = 6;      // les clients pro d'un magasin : six heures (nom de société, ville ; rien d'autre)
const PCO_TOURNEES_MAX = 30;        // tournées par magasin
const PCO_BUREAUX_MAX = 60;         // bureaux par tournée
const PCO_PERIODE_MAX = 31;         // une période lue : un mois au plus

function ensureCommandesTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_tournee ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL, nom VARCHAR(80) NOT NULL,'
        . 'depart CHAR(5) NOT NULL, retour CHAR(5) NOT NULL, livreur VARCHAR(60) NOT NULL DEFAULT "", vehicule VARCHAR(40) NOT NULL DEFAULT "",'
        . 'jours VARCHAR(7) NOT NULL DEFAULT "12345",'          // jours ISO : 1 lundi … 7 dimanche
        . 'cree_le DATETIME NOT NULL, maj_le DATETIME NOT NULL, INDEX (shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_tournee_bureau ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, tournee_id INT NOT NULL, shop_id VARCHAR(16) NOT NULL, client_id INT NOT NULL,'
        . 'nom VARCHAR(200) NOT NULL, consigne VARCHAR(200) NOT NULL DEFAULT "", ordre INT NOT NULL DEFAULT 0,'
        . 'INDEX (tournee_id), INDEX (shop_id, client_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS panel_tournee_commande ('
        . 'shop_id VARCHAR(16) NOT NULL, commande_id INT NOT NULL, jour DATE NOT NULL,'
        . 'tournee_id INT NOT NULL,'                              // 0 : gardée au magasin (retrait)
        . 'qui VARCHAR(80) NOT NULL DEFAULT "", maj_le DATETIME NOT NULL, PRIMARY KEY (shop_id, commande_id), INDEX (shop_id, jour)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/* ── Les commandes, normalisées (fonctions pures) ───────────────────────────────────────────────────────────── */

/** Le canal : bureau (offre ou département d'un bureau, mode « office »), webshop, sinon comptoir. */
function pcoCanal(array $c): string
{
    $fm = strtolower(trim((string) ($c['fulfilment_mode'] ?? $c['fulfillment_mode'] ?? '')));
    if (!empty($c['office_offer_id']) || !empty($c['id_client_department']) || str_contains($fm, 'office')) { return 'bureau'; }
    return !empty($c['is_webshop']) ? 'webshop' : 'comptoir';
}

/** Le panel dit-il que la commande se livre ? (`fulfilment_mode` : delivery, office, shipping…) */
function pcoLivree(array $c): bool
{
    $fm = strtolower(trim((string) ($c['fulfilment_mode'] ?? $c['fulfillment_mode'] ?? '')));
    return preg_match('/deliv|livr|ship|office/', $fm) === 1;
}

/**
 * L'état d'une commande : fait (remise ou livrée), prete, prep, afaire, nonretiree, annulee. `id_order_status` du panel :
 * 1 new, 2 completed (prête), 3 picked_up (remise), 4 unpicked, 5 in_production. Une commande encaissée en caisse
 * (`id_transaction`) dont l'heure est passée compte remise (mesuré à Halle : le panel la laisse « new ») ; encaissée
 * d'avance pour plus tard, elle garde son état.
 */
function pcoStatut(array $c, string $quand, string $now): string
{
    if (($c['non_collection_id_reason'] ?? null) !== null) { return 'annulee'; }
    $st = strtolower(trim((string) ($c['order_status'] ?? '')));
    $id = (int) ($c['id_order_status'] ?? 0);
    if (($c['issuing_timestamp'] ?? null) !== null || $st === 'picked_up' || $st === 'delivered' || $id === 3) { return 'fait'; }
    if ($st === 'unpicked' || $id === 4) { return 'nonretiree'; }
    if ((int) ($c['id_transaction'] ?? 0) > 0 && $quand <= $now) { return 'fait'; }
    if ($st === 'completed' || $id === 2 || ($c['completion_timestamp'] ?? null) !== null) { return 'prete'; }
    if ($st === 'in_production' || $id === 5) { return 'prep'; }
    return 'afaire';
}

/** Le paiement : en ligne, encaissée, sur facture (client à paiement différé), en attente, à encaisser. */
function pcoPaiement(array $c): array
{
    $cl = is_array($c['client'] ?? null) ? $c['client'] : [];
    $ps = strtolower((string) ($c['payment_status'] ?? ''));
    if ((int) ($c['id_transaction'] ?? 0) > 0) { return ['code' => 'caisse', 'lib' => 'Encaissée']; }
    if ($ps === 'paid') { return ['code' => 'enligne', 'lib' => 'Payée en ligne']; }
    if (!empty($cl['can_deferral'])) {
        $j = (int) ($cl['payment_terms'] ?? 0);
        return ['code' => 'facture', 'lib' => 'Sur facture' . ($j > 0 ? ' · ' . $j . ' j' : '')];
    }
    if ($ps === 'pending') { return ['code' => 'attente', 'lib' => 'Paiement en attente']; }
    return ['code' => 'aencaisser', 'lib' => 'À encaisser'];
}

/** Le nom à l'écran : la société d'un client pro ; pour un particulier, le prénom et l'initiale du nom. */
function pcoClientNom(array $cl): string
{
    $soc = trim((string) ($cl['company_name'] ?? ''));
    if ($soc !== '') { return $soc; }
    $p = trim((string) ($cl['name'] ?? ''));
    $n = trim((string) ($cl['surname'] ?? ''));
    $ini = $n !== '' ? mb_strtoupper(mb_substr($n, 0, 1)) . '.' : '';
    return trim($p . ' ' . $ini) ?: 'Client';
}

/** Une commande du panel, pour l'écran ; null si elle n'a pas d'heure ou est annulée. `$now` : « AAAA-MM-JJ HH:MM ». */
function pcoNormaliser(array $c, string $now): ?array
{
    $quand = (string) ($c['pick_up_datetime'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $quand)) { return null; }
    $jour = substr($quand, 0, 10);
    $heure = substr($quand, 11, 5);
    $statut = pcoStatut($c, $jour . ' ' . $heure, $now);
    if ($statut === 'annulee') { return null; }
    $cl = is_array($c['client'] ?? null) ? $c['client'] : [];
    $note = trim(preg_replace('/\s+/u', ' ', (string) ($c['comment'] ?? '')) ?? '');
    $fait = (string) ($c['issuing_timestamp'] ?? '');
    return ['id' => (int) ($c['id'] ?? 0), 'jour' => $jour, 'heure' => $heure, 'canal' => pcoCanal($c), 'livraison' => pcoLivree($c),
        'client' => ['id' => (int) ($c['id_client'] ?? $cl['id'] ?? 0), 'nom' => pcoClientNom($cl), 'pro' => !empty($cl['is_b2b']) || trim((string) ($cl['company_name'] ?? '')) !== ''],
        'note' => mb_strlen($note) > 160 ? mb_substr($note, 0, 157) . '…' : $note,
        'montant' => round((float) ($c['total_value'] ?? 0), 2), 'statut' => $statut,
        // L'heure de la remise, si le panel l'a notée près de l'heure prévue (± 90 min) : une commande marquée remise à
        // l'encaissement, des heures après la livraison, ne dit pas quand elle est partie (Gosselies, 13/11 : 07:30 → 11:41).
        'fait' => $statut === 'fait' && preg_match('/^\d{2}:\d{2}$/', substr($fait, 11, 5)) && substr($fait, 0, 10) === $jour
            && abs(pcoMin(substr($fait, 11, 5)) - pcoMin($heure)) <= 90 ? substr($fait, 11, 5) : null,
        'paiement' => pcoPaiement($c)];
}

/** La tournée roule-t-elle ce jour-là ? (`jours` : les jours ISO, « 12345 » du lundi au vendredi) */
function pcoTourneeRoule(array $t, string $date): bool
{
    return str_contains((string) ($t['jours'] ?? ''), (string) date('N', strtotime($date . ' 12:00:00')));
}

function pcoMin(string $h): int { return (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2); }

/**
 * La tournée de chaque commande : [id commande => ['t' => id tournée | null, 'mode' => 'manuel' | 'auto' | 'hors' | null]].
 * Le choix du magasin d'abord (`$manuels` : [id commande => id tournée, 0 = au magasin]) ; sinon la tournée du bureau
 * (le client) qui roule ce jour et dont la fenêtre (départ − 60 min → retour + 30 min) couvre l'heure, la plus proche de
 * son départ s'il y en a plusieurs. Un bureau qui commande hors de la fenêtre de ses tournées : `hors` (à placer, ou à
 * garder au magasin). `$tournees` : celles qui roulent ce jour, chacune avec `clients` (les id des bureaux).
 */
function pcoAffecter(array $cmds, array $tournees, array $manuels): array
{
    $ids = array_map(static fn ($t) => (int) $t['id'], $tournees);
    $out = [];
    foreach ($cmds as $c) {
        $id = (int) $c['id'];
        if (array_key_exists($id, $manuels)) {
            $m = (int) $manuels[$id];
            if ($m === 0) { $out[$id] = ['t' => null, 'mode' => 'manuel']; continue; }
            if (in_array($m, $ids, true)) { $out[$id] = ['t' => $m, 'mode' => 'manuel']; continue; }
        }
        $cli = (int) ($c['client']['id'] ?? 0);
        $cands = $cli > 0 ? array_values(array_filter($tournees, static fn ($t) => in_array($cli, array_map('intval', $t['clients'] ?? []), true))) : [];
        if ($cands === []) { $out[$id] = ['t' => null, 'mode' => null]; continue; }
        $h = pcoMin((string) $c['heure']);
        $dans = array_values(array_filter($cands, static fn ($t) => $h >= pcoMin((string) $t['depart']) - 60 && $h <= pcoMin((string) $t['retour']) + 30));
        if ($dans === []) { $out[$id] = ['t' => null, 'mode' => 'hors']; continue; }
        usort($dans, static fn ($a, $b) => abs(pcoMin((string) $a['depart']) - $h) <=> abs(pcoMin((string) $b['depart']) - $h));
        $out[$id] = ['t' => (int) $dans[0]['id'], 'mode' => 'auto'];
    }
    return $out;
}

/** L'état d'une tournée, le jour regardé : terminee (tout est livré), route (partie), prep (une commande prête ou en
 *  préparation), afaire ; vide sans commande. */
function pcoEtatTournee(array $t, array $cmds, string $date, string $auj, ?string $maintenant): string
{
    if ($cmds === []) { return 'vide'; }
    $faites = count(array_filter($cmds, static fn ($c) => $c['statut'] === 'fait'));
    if ($faites === count($cmds)) { return 'terminee'; }
    if ($date < $auj) { return 'terminee'; }
    if ($date === $auj && $maintenant !== null && $maintenant >= (string) $t['depart']) { return 'route'; }
    return array_filter($cmds, static fn ($c) => in_array($c['statut'], ['prete', 'prep'], true)) ? 'prep' : 'afaire';
}

/**
 * La journée assemblée (pure) : les commandes du jour avec leur tournée et leur arrêt, les tournées qui roulent avec
 * leurs arrêts et leurs bureaux sans commande, les totaux, et demain (les bureaux des tournées du lendemain : ont-ils
 * commandé ?). `$brut` : les commandes du panel (le jour et le lendemain) ; `$tournees` : toutes celles du magasin, avec
 * `bureaux` [{client, nom, consigne, ordre}] ; `$now` : l'instant, « AAAA-MM-JJ HH:MM ».
 */
function pcoJournee(array $brut, array $tournees, array $manuels, string $date, string $now): array
{
    $auj = substr($now, 0, 10);
    $maintenant = $date === $auj ? substr($now, 11, 5) : null;
    $demain = date('Y-m-d', strtotime($date . ' 12:00:00 +1 day'));
    $jour = []; $lendemain = [];
    foreach ($brut as $c) {
        if (!is_array($c)) { continue; }
        $n = pcoNormaliser($c, $now);
        if ($n === null) { continue; }
        if ($n['jour'] === $date) { $jour[] = $n; } elseif ($n['jour'] === $demain) { $lendemain[] = $n; }
    }
    usort($jour, static fn ($a, $b) => [$a['heure'], $a['id']] <=> [$b['heure'], $b['id']]);
    $avecClients = static fn ($t) => $t + ['clients' => array_map(static fn ($b) => (int) $b['client'], $t['bureaux'] ?? [])];
    $roulent = array_values(array_map($avecClients, array_filter($tournees, static fn ($t) => pcoTourneeRoule($t, $date))));
    usort($roulent, static fn ($a, $b) => [$a['depart'], $a['id']] <=> [$b['depart'], $b['id']]);
    $aff = pcoAffecter($jour, $roulent, $manuels);

    // Chaque commande : sa tournée, et son arrêt (l'ordre des heures demandées : la liste est déjà triée par heure).
    $rang = [];
    foreach ($jour as $i => $c) {
        $a = $aff[$c['id']] ?? ['t' => null, 'mode' => null];
        $jour[$i]['tournee'] = $a['t']; $jour[$i]['mode'] = $a['mode'];
        $jour[$i]['arret'] = $a['t'] !== null ? ($rang[$a['t']] = ($rang[$a['t']] ?? 0) + 1) : null;
        // À placer : le panel dit « livraison » sans tournée, ou un bureau commande hors de ses tournées.
        $jour[$i]['sansTournee'] = $a['t'] === null && $a['mode'] !== 'manuel' && ($c['livraison'] || $a['mode'] === 'hors');
    }
    $somme = static fn (array $L) => ['n' => count($L), 'ca' => round(array_sum(array_column($L, 'montant')), 2)];
    $tOut = [];
    foreach ($roulent as $t) {
        $L = array_values(array_filter($jour, static fn ($c) => $c['tournee'] === (int) $t['id']));
        $servis = array_map(static fn ($c) => (int) $c['client']['id'], $L);
        $bureaux = [];
        foreach ($t['bureaux'] ?? [] as $b) {
            $cmd = null;
            foreach ($L as $c) { if ((int) $c['client']['id'] === (int) $b['client']) { $cmd = $c['id']; break; } }
            $bureaux[] = ['client' => (int) $b['client'], 'nom' => (string) $b['nom'], 'consigne' => (string) ($b['consigne'] ?? ''), 'commande' => $cmd];
        }
        $tOut[] = ['id' => (int) $t['id'], 'nom' => $t['nom'], 'depart' => $t['depart'], 'retour' => $t['retour'], 'livreur' => $t['livreur'],
            'vehicule' => $t['vehicule'], 'jours' => $t['jours'], 'etat' => pcoEtatTournee($t, $L, $date, $auj, $maintenant),
            'commandes' => array_map(static fn ($c) => $c['id'], $L), 'bureaux' => $bureaux,
            'sansCommande' => count(array_filter($bureaux, static fn ($b) => $b['commande'] === null && !in_array($b['client'], $servis, true))), 'total' => $somme($L)];
    }
    $livrer = array_values(array_filter($jour, static fn ($c) => $c['tournee'] !== null || $c['sansTournee']));
    $retirer = array_values(array_filter($jour, static fn ($c) => $c['tournee'] === null && !$c['sansTournee']));
    $par = static fn (string $k) => array_values(array_filter($jour, static fn ($c) => $c['canal'] === $k));
    $totaux = ['total' => $somme($jour), 'webshop' => $somme($par('webshop')), 'comptoir' => $somme($par('comptoir')), 'bureau' => $somme($par('bureau')),
        'livrer' => $somme($livrer), 'retirer' => $somme($retirer),
        'aPreparer' => $date < $auj ? 0 : count(array_filter($jour, static fn ($c) => in_array($c['statut'], ['afaire', 'prep'], true))),
        'sansTournee' => count(array_filter($jour, static fn ($c) => $c['sansTournee']))];

    // Demain : les bureaux des tournées qui roulent le lendemain, et ceux qui ont déjà commandé pour ce jour-là.
    $dem = null;
    if ($date === $auj) {
        $clientsDemain = array_flip(array_map(static fn ($c) => (int) $c['client']['id'], $lendemain));
        $tD = [];
        foreach (array_filter($tournees, static fn ($t) => pcoTourneeRoule($t, $demain)) as $t) {
            $B = array_map(static fn ($b) => ['client' => (int) $b['client'], 'nom' => (string) $b['nom'], 'commande' => isset($clientsDemain[(int) $b['client']])], $t['bureaux'] ?? []);
            if ($B !== []) { $tD[] = ['id' => (int) $t['id'], 'nom' => $t['nom'], 'depart' => $t['depart'], 'bureaux' => $B]; }
        }
        usort($tD, static fn ($a, $b) => strcmp($a['depart'], $b['depart']));
        $dem = ['date' => $demain, 'coupure' => PCO_COUPURE, 'ouvert' => $maintenant === null || $maintenant < PCO_COUPURE,
            'commandes' => $somme($lendemain), 'tournees' => $tD];
    }
    return ['date' => $date, 'maintenant' => $date === $auj ? $maintenant : null, 'commandes' => $jour, 'tournees' => $tOut,
        'totaux' => $totaux, 'demain' => $dem];
}

/**
 * Une période assemblée (pure) : la semaine ou le mois, jour par jour, par tournée et par bureau. `$brut` : les commandes
 * du panel de la période d'avant et de la période ; `$manuels` : [jour => [id commande => id tournée]] ; `$now` :
 * « AAAA-MM-JJ HH:MM ». Chaque jour se range comme la journée (pcoAffecter) : une tournée ne compte que les jours où elle
 * roule ; un bureau, ce sont les bureaux des tournées et les clients qui commandent par le canal bureau.
 */
function pcoPeriode(array $brut, array $tournees, array $manuels, string $du, string $au, string $now): array
{
    $n = (int) round((strtotime($au . ' 12:00:00') - strtotime($du . ' 12:00:00')) / 86400) + 1;
    $avDu = date('Y-m-d', strtotime($du . ' 12:00:00 -' . $n . ' days'));
    $vide = static fn () => ['n' => 0, 'ca' => 0.0];
    $ajoute = static function (array &$x, float $m): void { $x['n']++; $x['ca'] = round($x['ca'] + $m, 2); };
    $parJour = []; $avant = ['total' => $vide(), 'webshop' => $vide(), 'comptoir' => $vide(), 'bureau' => $vide()];
    foreach ($brut as $c) {
        if (!is_array($c)) { continue; }
        $x = pcoNormaliser($c, $now);
        if ($x === null) { continue; }
        if ($x['jour'] >= $du && $x['jour'] <= $au) { $parJour[$x['jour']][] = $x; }
        elseif ($x['jour'] >= $avDu && $x['jour'] < $du) { $ajoute($avant['total'], $x['montant']); $ajoute($avant[$x['canal']], $x['montant']); }
    }
    $T = []; $B = [];
    foreach ($tournees as $t) {
        $T[(int) $t['id']] = ['id' => (int) $t['id'], 'nom' => $t['nom'], 'depart' => $t['depart'], 'retour' => $t['retour'], 'livreur' => $t['livreur'],
            'vehicule' => $t['vehicule'], 'jours' => $t['jours'], 'bureaux' => count($t['bureaux'] ?? []), 'roule' => 0, 'parJour' => [], 'total' => $vide()];
        foreach ($t['bureaux'] ?? [] as $b) {
            $k = (int) $b['client'];
            $B[$k] ??= ['client' => $k, 'nom' => (string) $b['nom'], 'tournees' => [], 'parJour' => [], 'total' => $vide(), 'dernier' => null];
            $B[$k]['tournees'][] = (int) $t['id'];
        }
    }
    $jours = []; $tot = ['total' => $vide(), 'webshop' => $vide(), 'comptoir' => $vide(), 'bureau' => $vide(), 'livrer' => $vide(), 'retirer' => $vide()];
    $sansT = 0;
    for ($i = 0; $i < $n; $i++) {
        $d = date('Y-m-d', strtotime($du . ' 12:00:00 +' . $i . ' days'));
        $L = $parJour[$d] ?? [];
        usort($L, static fn ($a, $b) => [$a['heure'], $a['id']] <=> [$b['heure'], $b['id']]);
        $roulent = array_values(array_map(static fn ($t) => $t + ['clients' => array_map(static fn ($b) => (int) $b['client'], $t['bureaux'] ?? [])],
            array_filter($tournees, static fn ($t) => pcoTourneeRoule($t, $d))));
        foreach ($roulent as $t) { $T[(int) $t['id']]['roule']++; $T[(int) $t['id']]['parJour'][$d] = ['n' => 0, 'ca' => 0.0, 'servis' => 0]; }
        $aff = pcoAffecter($L, $roulent, $manuels[$d] ?? []);
        $j = ['date' => $d, 'total' => $vide(), 'webshop' => $vide(), 'comptoir' => $vide(), 'bureau' => $vide(), 'livrer' => $vide(), 'retirer' => $vide(), 'sansTournee' => 0];
        $servis = [];
        foreach ($L as $c) {
            $a = $aff[$c['id']] ?? ['t' => null, 'mode' => null];
            $sans = $a['t'] === null && $a['mode'] !== 'manuel' && ($c['livraison'] || $a['mode'] === 'hors');
            foreach (['total', $c['canal'], $a['t'] !== null || $sans ? 'livrer' : 'retirer'] as $k) { $ajoute($j[$k], $c['montant']); $ajoute($tot[$k], $c['montant']); }
            if ($sans) { $j['sansTournee']++; $sansT++; }
            if ($a['t'] !== null) {
                $ajoute($T[$a['t']]['total'], $c['montant']);
                $pj = &$T[$a['t']]['parJour'][$d]; $pj['n']++; $pj['ca'] = round($pj['ca'] + $c['montant'], 2); unset($pj);
                $servis[$a['t']][(int) $c['client']['id']] = true;
            }
            $k = (int) $c['client']['id'];
            if ($k > 0 && (isset($B[$k]) || $c['canal'] === 'bureau')) {
                $B[$k] ??= ['client' => $k, 'nom' => $c['client']['nom'], 'tournees' => [], 'parJour' => [], 'total' => $vide(), 'dernier' => null];
                $B[$k]['parJour'][$d] ??= $vide();
                $ajoute($B[$k]['parJour'][$d], $c['montant']); $ajoute($B[$k]['total'], $c['montant']);
                $B[$k]['dernier'] = $d;
            }
        }
        foreach ($servis as $t => $cl) { $T[$t]['parJour'][$d]['servis'] = count($cl); }
        $jours[] = $j;
    }
    $B = array_values($B);
    usort($B, static fn ($a, $b) => [-$a['total']['ca'], $a['nom']] <=> [-$b['total']['ca'], $b['nom']]);
    $T = array_values($T);
    usort($T, static fn ($a, $b) => [$a['depart'], $a['id']] <=> [$b['depart'], $b['id']]);
    return ['du' => $du, 'au' => $au, 'jours' => $jours, 'totaux' => $tot + ['sansTournee' => $sansT], 'avant' => ['du' => $avDu] + $avant,
        'tournees' => $T, 'bureaux' => $B, 'aujourdhui' => substr($now, 0, 10)];
}

/* ── Lectures et écritures ──────────────────────────────────────────────────────────────────────────────────── */

function pcoErreur(int $code, string $msg, array $plus = []): array { http_response_code($code); return ['error' => $msg] + $plus; }

function pcoShop(?array $b = null): int { return (int) (($b ?? $_GET)['shop'] ?? 0); }

/**
 * Les commandes du panel entre deux dates de retrait : [liste, lue ?, motif]. Un jour sans commande, le panel répond
 * 404 `NO_ORDERS_FOR_PICKUP_DATE` (mesuré le 24/11/2026 à Gosselies) : c'est une liste vide, pas une panne.
 */
function pcoLireCommandes(int $shop, string $du, string $au, int $timeout): array
{
    if (!PanelApi::configured()) { return [[], false, 'le compte du panel n’est pas configuré']; }
    $r = PanelApi::sondeGet('/shops/' . $shop . '/client-orders?date_from=' . $du . '&date_to=' . $au, $timeout);
    $code = (int) ($r['code'] ?? 0);
    if ($code === 200) { return [analyseListe($r['corps'] ?? null), true, null]; }
    if (pcoAucuneCommande($code, $r['corps'] ?? null)) { return [[], true, null]; }
    return [[], false, 'le panel ne rend pas les commandes (' . $code . ')'];
}

/** Le 404 du panel qui veut dire « aucune commande ce jour-là ». Fonction pure. */
function pcoAucuneCommande(int $code, mixed $corps): bool
{
    return $code === 404 && is_array($corps) && str_contains(strtoupper((string) ($corps['description'] ?? $corps['code'] ?? $corps['error'] ?? '')), 'NO_ORDERS');
}

/** Les tournées d'un magasin, avec leurs bureaux dans l'ordre. */
function pcoTournees(int $shop): array
{
    ensureCommandesTables();
    $T = Db::rows('SELECT id, nom, depart, retour, livreur, vehicule, jours FROM panel_tournee WHERE shop_id = ? ORDER BY depart, id', [(string) $shop]);
    if ($T === []) { return []; }
    $B = Db::rows('SELECT tournee_id, client_id, nom, consigne, ordre FROM panel_tournee_bureau WHERE shop_id = ? ORDER BY tournee_id, ordre, id', [(string) $shop]);
    $par = [];
    foreach ($B as $b) { $par[(int) $b['tournee_id']][] = ['client' => (int) $b['client_id'], 'nom' => (string) $b['nom'], 'consigne' => (string) $b['consigne'], 'ordre' => (int) $b['ordre']]; }
    return array_map(static fn ($t) => ['id' => (int) $t['id'], 'nom' => (string) $t['nom'], 'depart' => (string) $t['depart'], 'retour' => (string) $t['retour'],
        'livreur' => (string) $t['livreur'], 'vehicule' => (string) $t['vehicule'], 'jours' => (string) $t['jours'], 'bureaux' => $par[(int) $t['id']] ?? []], $T);
}

/** GET /dashboard/commandes/jour?shop=&date= — les commandes du jour, leurs canaux, les tournées et demain. */
function ep_commandes_jour(): array
{
    $shop = pcoShop();
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    $auj = date('Y-m-d');
    $date = (string) ($_GET['date'] ?? $auj);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = $auj; }
    $demain = date('Y-m-d', strtotime($date . ' 12:00:00 +1 day'));
    try { $tournees = pcoTournees($shop); }
    catch (Throwable $e) { return pcoErreur(500, 'les tournées ne se lisent pas'); }
    $manuels = [];
    try {
        foreach (Db::rows('SELECT commande_id, tournee_id FROM panel_tournee_commande WHERE shop_id = ? AND jour = ?', [(string) $shop, $date]) as $r) {
            $manuels[(int) $r['commande_id']] = (int) $r['tournee_id'];
        }
    } catch (Throwable $e) { /* sans choix du magasin */ }
    @set_time_limit(60);
    [$brut, $panel, $motif] = pcoLireCommandes($shop, $date, $demain, 25);
    $j = pcoJournee($brut, $tournees, $manuels, $date, date('Y-m-d H:i'));
    return ['shop' => $shop, 'panel' => $panel, 'motif' => $motif, 'coupure' => PCO_COUPURE]
        + $j + ['toutes' => $tournees];
}

/** GET /dashboard/commandes/periode?shop=&du=&au= — la semaine ou le mois : jour par jour, par tournée, par bureau, face à
 *  la période d'avant. */
function ep_commandes_periode(): array
{
    $shop = pcoShop();
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    $du = (string) ($_GET['du'] ?? ''); $au = (string) ($_GET['au'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $au) || $au < $du) { return pcoErreur(422, 'du et au : AAAA-MM-JJ, au après du'); }
    $n = (int) round((strtotime($au . ' 12:00:00') - strtotime($du . ' 12:00:00')) / 86400) + 1;
    if ($n > PCO_PERIODE_MAX) { return pcoErreur(422, PCO_PERIODE_MAX . ' jours au plus'); }
    $avDu = date('Y-m-d', strtotime($du . ' 12:00:00 -' . $n . ' days'));
    try { $tournees = pcoTournees($shop); }
    catch (Throwable $e) { return pcoErreur(500, 'les tournées ne se lisent pas'); }
    $manuels = [];
    try {
        foreach (Db::rows('SELECT commande_id, jour, tournee_id FROM panel_tournee_commande WHERE shop_id = ? AND jour BETWEEN ? AND ?', [(string) $shop, $du, $au]) as $r) {
            $manuels[(string) $r['jour']][(int) $r['commande_id']] = (int) $r['tournee_id'];
        }
    } catch (Throwable $e) { /* sans choix du magasin */ }
    @set_time_limit(90);
    [$brut, $panel, $motif] = pcoLireCommandes($shop, $avDu, $au, 40);
    return ['shop' => $shop, 'panel' => $panel, 'motif' => $motif] + pcoPeriode($brut, $tournees, $manuels, $du, $au, date('Y-m-d H:i')) + ['toutes' => $tournees];
}

/** Une tournée lue dans un corps de requête, vérifiée : [champs, null] ou [null, message]. */
function pcoTourneeLue(array $b): array
{
    $nom = trim((string) ($b['nom'] ?? ''));
    $dep = trim((string) ($b['depart'] ?? ''));
    $ret = trim((string) ($b['retour'] ?? ''));
    $jours = implode('', array_unique(str_split(preg_replace('/[^1-7]/', '', (string) ($b['jours'] ?? '')) ?? '')));
    if ($nom === '' || mb_strlen($nom) > 80) { return [null, 'le nom de la tournée : 1 à 80 caractères']; }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dep) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ret)) { return [null, 'départ et retour : HH:MM']; }
    if ($ret <= $dep) { return [null, 'le retour vient après le départ']; }
    if ($jours === '') { return [null, 'au moins un jour']; }
    $chars = str_split($jours); sort($chars);
    $liv = trim((string) ($b['livreur'] ?? '')); $veh = trim((string) ($b['vehicule'] ?? ''));
    if (mb_strlen($liv) > 60 || mb_strlen($veh) > 40) { return [null, 'livreur : 60 caractères au plus ; véhicule : 40']; }
    return [['nom' => $nom, 'depart' => $dep, 'retour' => $ret, 'jours' => implode('', $chars), 'livreur' => $liv, 'vehicule' => $veh], null];
}

/** La tournée `id` est-elle bien de ce magasin ? */
function pcoTourneeDuMagasin(int $id, int $shop): bool
{
    ensureCommandesTables();
    return $id > 0 && Db::row('SELECT id FROM panel_tournee WHERE id = ? AND shop_id = ?', [$id, (string) $shop]) !== null;
}

function pcoJournal(int $shop, string $msg): void
{
    try { journalAdd('Magasin ' . $shop, 'Commandes', null, $msg); } catch (Throwable $e) { /* le journal ne bloque pas */ }
}

/** POST /dashboard/commandes/tournees {shop, nom, depart, retour, livreur, vehicule, jours} — crée une tournée. */
function wr_commandes_tournee_creer(): array
{
    $b = body(); $shop = pcoShop($b);
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    [$t, $err] = pcoTourneeLue($b);
    if ($t === null) { return pcoErreur(422, $err); }
    try {
        ensureCommandesTables();
        $n = (int) (Db::row('SELECT COUNT(*) n FROM panel_tournee WHERE shop_id = ?', [(string) $shop])['n'] ?? 0);
        if ($n >= PCO_TOURNEES_MAX) { return pcoErreur(422, PCO_TOURNEES_MAX . ' tournées au plus par magasin'); }
        Db::exec('INSERT INTO panel_tournee (shop_id, nom, depart, retour, livreur, vehicule, jours, cree_le, maj_le) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [(string) $shop, $t['nom'], $t['depart'], $t['retour'], $t['livreur'], $t['vehicule'], $t['jours']]);
        $id = (int) Db::pdo()->lastInsertId();
    } catch (Throwable $e) { return pcoErreur(500, 'la tournée n’a pas été créée'); }
    pcoJournal($shop, 'Commandes › tournée créée : ' . $t['nom'] . ' (' . $t['depart'] . ')');
    return ['ok' => true, 'id' => $id];
}

/** PUT /dashboard/commandes/tournees {shop, id, nom, depart, retour, livreur, vehicule, jours} — modifie une tournée. */
function wr_commandes_tournee_modifier(): array
{
    $b = body(); $shop = pcoShop($b); $id = (int) ($b['id'] ?? 0);
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    [$t, $err] = pcoTourneeLue($b);
    if ($t === null) { return pcoErreur(422, $err); }
    try {
        if (!pcoTourneeDuMagasin($id, $shop)) { return pcoErreur(404, 'tournée inconnue pour ce magasin'); }
        Db::exec('UPDATE panel_tournee SET nom = ?, depart = ?, retour = ?, livreur = ?, vehicule = ?, jours = ?, maj_le = NOW() WHERE id = ? AND shop_id = ?',
            [$t['nom'], $t['depart'], $t['retour'], $t['livreur'], $t['vehicule'], $t['jours'], $id, (string) $shop]);
    } catch (Throwable $e) { return pcoErreur(500, 'la tournée n’a pas été modifiée'); }
    pcoJournal($shop, 'Commandes › tournée modifiée : ' . $t['nom']);
    return ['ok' => true, 'id' => $id];
}

/** DELETE /dashboard/commandes/tournees {shop, id} — retire une tournée, ses bureaux et les choix qui la nomment. */
function wr_commandes_tournee_supprimer(): array
{
    $b = body(); $shop = pcoShop($b); $id = (int) ($b['id'] ?? 0);
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    try {
        if (!pcoTourneeDuMagasin($id, $shop)) { return pcoErreur(404, 'tournée inconnue pour ce magasin'); }
        $nom = (string) (Db::row('SELECT nom FROM panel_tournee WHERE id = ?', [$id])['nom'] ?? '');
        Db::exec('DELETE FROM panel_tournee_bureau WHERE tournee_id = ? AND shop_id = ?', [$id, (string) $shop]);
        Db::exec('DELETE FROM panel_tournee_commande WHERE tournee_id = ? AND shop_id = ?', [$id, (string) $shop]);
        Db::exec('DELETE FROM panel_tournee WHERE id = ? AND shop_id = ?', [$id, (string) $shop]);
    } catch (Throwable $e) { return pcoErreur(500, 'la tournée n’a pas été retirée'); }
    pcoJournal($shop, 'Commandes › tournée retirée : ' . $nom);
    return ['ok' => true];
}

/** POST /dashboard/commandes/tournees/bureaux {shop, tournee, bureaux: [{client, nom, consigne}]} — les bureaux de la
 *  tournée, dans l'ordre de passage (la liste remplace la précédente). */
function wr_commandes_tournee_bureaux(): array
{
    $b = body(); $shop = pcoShop($b); $id = (int) ($b['tournee'] ?? 0);
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    $L = $b['bureaux'] ?? null;
    if (!is_array($L) || !array_is_list($L)) { return pcoErreur(422, 'bureaux : une liste'); }
    if (count($L) > PCO_BUREAUX_MAX) { return pcoErreur(422, PCO_BUREAUX_MAX . ' bureaux au plus par tournée'); }
    $propres = []; $vus = [];
    foreach ($L as $x) {
        $cli = (int) ($x['client'] ?? 0); $nom = trim((string) ($x['nom'] ?? '')); $cons = trim((string) ($x['consigne'] ?? ''));
        if ($cli <= 0 || $nom === '') { return pcoErreur(422, 'chaque bureau : un client du panel et un nom'); }
        if (isset($vus[$cli])) { continue; }
        $vus[$cli] = true;
        $propres[] = [$cli, mb_substr($nom, 0, 200), mb_substr($cons, 0, 200)];
    }
    try {
        if (!pcoTourneeDuMagasin($id, $shop)) { return pcoErreur(404, 'tournée inconnue pour ce magasin'); }
        $pdo = Db::pdo(); $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM panel_tournee_bureau WHERE tournee_id = ? AND shop_id = ?', [$id, (string) $shop]);
            foreach ($propres as $i => [$cli, $nom, $cons]) {
                Db::exec('INSERT INTO panel_tournee_bureau (tournee_id, shop_id, client_id, nom, consigne, ordre) VALUES (?,?,?,?,?,?)', [$id, (string) $shop, $cli, $nom, $cons, $i + 1]);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    } catch (Throwable $e) { return pcoErreur(500, 'les bureaux n’ont pas été gardés'); }
    pcoJournal($shop, 'Commandes › ' . count($propres) . ' bureau(x) sur la tournée n° ' . $id);
    return ['ok' => true, 'n' => count($propres)];
}

/** POST /dashboard/commandes/affecter {shop, commande, date, tournee} — la tournée d'une commande : un numéro de tournée,
 *  0 pour la garder au magasin, null pour revenir au choix automatique (la tournée de son bureau). */
function wr_commandes_affecter(): array
{
    $b = body(); $shop = pcoShop($b); $cmd = (int) ($b['commande'] ?? 0); $date = (string) ($b['date'] ?? '');
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    if ($cmd <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return pcoErreur(422, 'commande et date (AAAA-MM-JJ)'); }
    $t = $b['tournee'] ?? null;
    try {
        ensureCommandesTables();
        if ($t === null) {
            Db::exec('DELETE FROM panel_tournee_commande WHERE shop_id = ? AND commande_id = ?', [(string) $shop, $cmd]);
            return ['ok' => true, 'tournee' => null];
        }
        $t = (int) $t;
        if ($t !== 0 && !pcoTourneeDuMagasin($t, $shop)) { return pcoErreur(404, 'tournée inconnue pour ce magasin'); }
        Db::exec('INSERT INTO panel_tournee_commande (shop_id, commande_id, jour, tournee_id, qui, maj_le) VALUES (?,?,?,?,?,NOW())'
            . ' ON DUPLICATE KEY UPDATE jour = VALUES(jour), tournee_id = VALUES(tournee_id), qui = VALUES(qui), maj_le = NOW()',
            [(string) $shop, $cmd, $date, $t, 'Magasin ' . $shop]);
    } catch (Throwable $e) { return pcoErreur(500, 'le choix n’a pas été gardé'); }
    pcoJournal($shop, 'Commandes › commande n° ' . $cmd . ($t === 0 ? ' gardée au magasin' : ' dans la tournée n° ' . $t));
    return ['ok' => true, 'tournee' => $t];
}

/** Les clients pro d'un magasin (fiche pro avec un nom de société), mis à plat : id, société, ville. Fonction pure. */
function pcoBureauxDe(array $clients): array
{
    $out = [];
    foreach ($clients as $c) {
        if (!is_array($c) || empty($c['is_b2b']) || (isset($c['active']) && !(int) $c['active']) || !empty($c['merged_into_id'])) { continue; }
        $soc = trim((string) ($c['company_name'] ?? ''));
        if ($soc === '' || (int) ($c['id'] ?? 0) <= 0) { continue; }
        $out[] = ['id' => (int) $c['id'], 'nom' => $soc, 'ville' => trim((string) ($c['city'] ?? $c['locality'] ?? '')), 'cp' => trim((string) ($c['zip'] ?? ''))];
    }
    usort($out, static fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
    return $out;
}

/** GET /dashboard/commandes/bureaux?shop=&q= — les clients pro du magasin (30 au plus), pour composer une tournée. */
function ep_commandes_bureaux(): array
{
    $shop = pcoShop();
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $cle = 'pcoBur:' . $shop;
    $memo = setting($cle);
    $L = is_array($memo) && isset($memo['le'], $memo['v']) && time() - (int) $memo['le'] < PCO_BUREAUX_CACHE_H * 3600 ? $memo['v'] : null;
    if ($L === null) {
        if (!PanelApi::configured()) { return pcoErreur(503, 'le compte du panel n’est pas configuré'); }
        @set_time_limit(60);
        $r = PanelApi::sondeGet('/shops/' . $shop . '/clients', 40);
        if ((int) ($r['code'] ?? 0) !== 200) { return is_array($memo['v'] ?? null) ? ['shop' => $shop, 'bureaux' => array_slice($memo['v'], 0, 30), 'ancien' => true] : pcoErreur(502, 'le panel ne rend pas les clients'); }
        $L = pcoBureauxDe(analyseListe($r['corps'] ?? null));
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['le' => time(), 'v' => $L], JSON_UNESCAPED_UNICODE)]); }
        catch (Throwable $e) { /* sans mémo */ }
    }
    if ($q !== '') { $L = array_values(array_filter($L, static fn ($b) => str_contains(mb_strtolower($b['nom'] . ' ' . $b['ville'] . ' ' . $b['cp']), $q))); }
    return ['shop' => $shop, 'n' => count($L), 'bureaux' => array_slice($L, 0, 30)];
}

/* ── Les livraisons de TF Buddy (24/11/2026, « le maximum sur TF Buddy ») ─────────────────────────────────────
 * TF Buddy a les tournées (`/panel/shops/{shop}/franchise/delivery-routes`), les bureaux et leurs horaires avec l'heure
 * limite (`…/delivery-hub`), l'heure limite de préparation (`…/preparation-settings`). Le cockpit les lit avec ses
 * comptes : celui du cockpit (PanelApi), puis le compte admin de l'ERP (ErpApi) s'il est refusé — comme Mon équipe. */

/** Un GET à TF Buddy : le compte du cockpit, puis l'admin de l'ERP sur un refus. [code, corps, compte]. */
function pcoTfGet(string $chemin): array
{
    // Le jeton du magasin d'abord (25/11/2026, tfb_magasin.php) : lui seul a le droit des routes /panel/shops/{s}/franchise/….
    if (function_exists('tfbmAppel') && ($m = tfbmAppel('GET', $chemin)) !== null && $m['code'] >= 200 && $m['code'] < 300) { return [$m['code'], $m['corps'], 'magasin']; }
    $r = class_exists('PanelApi') && PanelApi::configured() ? PanelApi::sondeGet($chemin, 25) : ['code' => 0, 'corps' => null];
    $code = (int) ($r['code'] ?? 0);
    if ($code >= 200 && $code < 300) { return [$code, $r['corps'] ?? null, 'cockpit']; }
    if (class_exists('ErpApi') && ErpApi::disponible()) {
        $e = ErpApi::sondeGet($chemin);
        $ce = (int) ($e['code'] ?? 0);
        if ($ce >= 200 && $ce < 300 || $code === 0) { return [$ce, $e['corps'] ?? null, 'admin']; }
        return [$code ?: $ce, $r['corps'] ?? $e['corps'] ?? null, 'cockpit puis admin (' . $ce . ')'];
    }
    return [$code, $r['corps'] ?? null, 'cockpit'];
}

/** Le résumé d'une réponse de TF Buddy (fonction pure) : les listes comptées, les noms des champs de leur premier
 *  élément (pas leurs valeurs), et les tournées et horaires à plat. Rien de nominatif d'un client. */
function pcoTfResume(mixed $c): array
{
    if (is_array($c) && isset($c['data']) && is_array($c['data']) && !array_is_list($c['data'])) { $c = $c['data']; }
    if (!is_array($c)) { return ['vide' => true]; }
    $out = ['champs' => [], 'nombres' => []];
    foreach ($c as $k => $v) {
        if (is_array($v) && array_is_list($v)) {
            $out['nombres'][$k] = count($v);
            $out['champs'][$k] = is_array($v[0] ?? null) ? array_keys($v[0]) : [];
        } elseif (!is_array($v)) { $out['valeurs'][$k] = is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : $v; }
    }
    $garde = static fn (array $x, array $cles) => array_intersect_key($x, array_flip($cles));
    if (is_array($c['routes'] ?? null)) {
        $out['tournees'] = array_map(static fn ($r) => is_array($r) ? $garde($r, ['id', 'name', 'short_name', 'vehicle', 'start_time', 'weekdays', 'driver', 'active', 'max_orders', 'max_offices', 'color']) + ['sites' => is_array($r['site_ids'] ?? null) ? count($r['site_ids']) : null] : [], array_slice($c['routes'], 0, 30));
    }
    if (is_array($c['schedules'] ?? null)) {
        $out['horaires'] = array_map(static fn ($r) => is_array($r) ? $garde($r, ['id', 'route_id', 'weekday', 'start_time', 'end_time', 'cutoff_time', 'cutoff_days_before', 'capacity']) : [], array_slice($c['schedules'], 0, 60));
    }
    foreach (['cutoff_time', 'timezone', 'configured', 'depot_address'] as $k) { if (isset($c[$k]) && !is_array($c[$k])) { $out['valeurs'][$k] = $c[$k]; } }
    return $out;
}

/** GET /dashboard/commandes/tfb?shop=&date= — les tournées de TF Buddy, leurs horaires et l'heure limite (lecture). */
function ep_commandes_tfb(): array
{
    $shop = pcoShop();
    if ($shop <= 0) { return pcoErreur(400, 'shop manquant'); }
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    @set_time_limit(90);
    $out = ['shop' => $shop, 'date' => $date];
    foreach (['tournees' => '/panel/shops/' . $shop . '/franchise/delivery-routes?date=' . $date, 'bureaux' => '/panel/shops/' . $shop . '/franchise/delivery-hub',
        'preparation' => '/panel/shops/' . $shop . '/franchise/preparation-settings'] as $k => $chemin) {
        [$code, $corps, $compte] = pcoTfGet($chemin);
        $out[$k] = ['code' => $code, 'compte' => $compte] + ($code >= 200 && $code < 300 ? pcoTfResume($corps)
            : ['erreur' => is_array($corps) ? (string) ($corps['description'] ?? $corps['code'] ?? $corps['message'] ?? '') : '']);
    }
    return $out;
}

/** Les routes de la page Commandes : la journée, la semaine et le mois, les clients pro, les tournées et leurs bureaux, la tournée d'une commande. */
function pcoRoute(string $method, string $path): ?array
{
    if ($path !== '/dashboard/commandes' && !str_starts_with($path, '/dashboard/commandes/')) { return null; }
    if ($method === 'GET' && $path === '/dashboard/commandes/jour') { return ep_commandes_jour(); }
    if ($method === 'GET' && $path === '/dashboard/commandes/bureaux') { return ep_commandes_bureaux(); }
    if ($method === 'GET' && $path === '/dashboard/commandes/periode') { return ep_commandes_periode(); }
    if ($method === 'GET' && $path === '/dashboard/commandes/tfb') { return ep_commandes_tfb(); }
    if ($path === '/dashboard/commandes/tournees') {
        if ($method === 'POST') { return wr_commandes_tournee_creer(); }
        if ($method === 'PUT') { return wr_commandes_tournee_modifier(); }
        if ($method === 'DELETE') { return wr_commandes_tournee_supprimer(); }
    }
    if ($method === 'POST' && $path === '/dashboard/commandes/tournees/bureaux') { return wr_commandes_tournee_bureaux(); }
    if ($method === 'POST' && $path === '/dashboard/commandes/affecter') { return wr_commandes_affecter(); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
