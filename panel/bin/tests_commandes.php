#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des Commandes et tournées (panel/src/commandes_tournees.php) — sans base, sans réseau.
 *
 *   php panel/bin/tests_commandes.php
 *
 * Le canal, l'état, le paiement et le nom d'une commande, la tournée qui la prend, la journée assemblée (arrêts,
 * livraisons sans tournée, bureaux sans commande, demain), la lecture d'une tournée et des clients pro : des fonctions
 * pures, nourries de commandes faites comme celles du panel (`/shops/{id}/client-orders`).
 */

// --- doublures -----------------------------------------------------------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): int { return 0; }
}
function setting(string $k, mixed $d = null): mixed { return $d; }
function analyseListe($r): array { return is_array($r) && array_is_list($r) ? $r : []; }
function body(): array { return []; }
function journalAdd(string $a, string $k, ?string $p, string $m): void {}

require __DIR__ . '/../src/commandes_tournees.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
/** Une commande comme le panel la rend (les champs utiles). */
function cmd(int $id, string $quand, array $plus = [], array $client = []): array
{
    return $plus + ['id' => $id, 'pick_up_datetime' => $quand . ':00', 'is_webshop' => false, 'fulfilment_mode' => 'collect', 'total_value' => 10.0,
        'order_status' => 'new', 'id_order_status' => 1, 'id_transaction' => null, 'issuing_timestamp' => null, 'completion_timestamp' => null,
        'non_collection_id_reason' => null, 'payment_status' => null, 'office_offer_id' => null, 'id_client_department' => null, 'comment' => '',
        'id_client' => $client['id'] ?? 0, 'client' => $client + ['is_b2b' => 0, 'company_name' => null, 'name' => 'Isabelle', 'surname' => 'Bernard', 'can_deferral' => 0, 'payment_terms' => 0]];
}

echo "Le canal et la livraison\n";
verifie('une offre de bureau : bureau', pcoCanal(cmd(1, '2026-11-24 12:00', ['office_offer_id' => 4, 'is_webshop' => true])) === 'bureau');
verifie('un département de client : bureau', pcoCanal(cmd(1, '2026-11-24 12:00', ['id_client_department' => 8])) === 'bureau');
verifie('le mode « office » : bureau, et livrée', pcoCanal(cmd(1, '2026-11-24 12:00', ['fulfilment_mode' => 'office'])) === 'bureau' && pcoLivree(cmd(1, '2026-11-24 12:00', ['fulfilment_mode' => 'office'])));
verifie('is_webshop : webshop', pcoCanal(cmd(1, '2026-11-24 12:00', ['is_webshop' => true])) === 'webshop');
verifie('le reste : comptoir', pcoCanal(cmd(1, '2026-11-24 12:00')) === 'comptoir');
verifie('delivery : livrée ; collect : non', pcoLivree(cmd(1, '2026-11-24 12:00', ['fulfilment_mode' => 'delivery'])) && !pcoLivree(cmd(1, '2026-11-24 12:00')));

echo "L'état\n";
$now = '2026-11-24 10:40';
verifie('picked_up : fait', pcoStatut(cmd(1, '2026-11-24 09:30', ['order_status' => 'picked_up', 'id_order_status' => 3]), '2026-11-24 09:30', $now) === 'fait');
verifie('completed : prête', pcoStatut(cmd(1, '2026-11-24 12:00', ['order_status' => 'completed', 'id_order_status' => 2]), '2026-11-24 12:00', $now) === 'prete');
verifie('in_production : en préparation', pcoStatut(cmd(1, '2026-11-24 12:00', ['order_status' => 'in_production', 'id_order_status' => 5]), '2026-11-24 12:00', $now) === 'prep');
verifie('new : à préparer', pcoStatut(cmd(1, '2026-11-24 12:00'), '2026-11-24 12:00', $now) === 'afaire');
verifie('unpicked : pas retirée', pcoStatut(cmd(1, '2026-11-24 09:00', ['order_status' => 'unpicked', 'id_order_status' => 4]), '2026-11-24 09:00', $now) === 'nonretiree');
verifie('encaissée, l’heure passée : faite (le panel la laisse « new »)', pcoStatut(cmd(1, '2026-11-24 09:30', ['id_transaction' => 55]), '2026-11-24 09:30', $now) === 'fait');
verifie('encaissée d’avance pour plus tard : pas encore faite', pcoStatut(cmd(1, '2026-11-24 16:00', ['id_transaction' => 55]), '2026-11-24 16:00', $now) === 'afaire');
verifie('encaissée d’avance pour demain : pas encore faite', pcoStatut(cmd(1, '2026-11-25 08:00', ['id_transaction' => 55]), '2026-11-25 08:00', $now) === 'afaire');
verifie('annulée', pcoStatut(cmd(1, '2026-11-24 12:00', ['non_collection_id_reason' => 2]), '2026-11-24 12:00', $now) === 'annulee');
verifie('une commande annulée ne se montre pas', pcoNormaliser(cmd(1, '2026-11-24 12:00', ['non_collection_id_reason' => 2]), $now) === null);
verifie('sans heure de retrait : ignorée', pcoNormaliser(['id' => 3, 'pick_up_datetime' => null], $now) === null);

echo "Le paiement et le nom\n";
verifie('encaissée', pcoPaiement(cmd(1, '2026-11-24 12:00', ['id_transaction' => 9]))['code'] === 'caisse');
verifie('payée en ligne', pcoPaiement(cmd(1, '2026-11-24 12:00', ['payment_status' => 'paid']))['lib'] === 'Payée en ligne');
$f = pcoPaiement(cmd(1, '2026-11-24 12:00', [], ['can_deferral' => 1, 'payment_terms' => 14]));
verifie('paiement différé : sur facture à 14 jours', $f['code'] === 'facture' && $f['lib'] === 'Sur facture · 14 j', json_encode($f, JSON_UNESCAPED_UNICODE));
verifie('sinon : à encaisser', pcoPaiement(cmd(1, '2026-11-24 12:00'))['code'] === 'aencaisser');
verifie('un client pro : sa société', pcoClientNom(['company_name' => ' Fiduciaire Delvaux ', 'name' => 'Claire', 'surname' => 'Mertens']) === 'Fiduciaire Delvaux');
verifie('un particulier : prénom et initiale', pcoClientNom(['name' => 'Isabelle', 'surname' => 'bernard']) === 'Isabelle B.');
verifie('sans nom : « Client »', pcoClientNom([]) === 'Client');

echo "Les tournées : les jours et le choix\n";
verifie('du lundi au vendredi : roule le mardi 24/11', pcoTourneeRoule(['jours' => '12345'], '2026-11-24'));
verifie('du lundi au vendredi : pas le samedi 28/11', !pcoTourneeRoule(['jours' => '12345'], '2026-11-28'));
$T = [['id' => 1, 'depart' => '07:15', 'retour' => '08:35', 'clients' => [100, 200]], ['id' => 2, 'depart' => '11:15', 'retour' => '12:40', 'clients' => [100, 300]]];
$C = [['id' => 10, 'heure' => '07:30', 'client' => ['id' => 100]], ['id' => 11, 'heure' => '12:00', 'client' => ['id' => 100]],
    ['id' => 12, 'heure' => '12:10', 'client' => ['id' => 300]], ['id' => 13, 'heure' => '09:00', 'client' => ['id' => 999]],
    ['id' => 14, 'heure' => '12:00', 'client' => ['id' => 200]], ['id' => 15, 'heure' => '10:00', 'client' => ['id' => 100]]];
$A = pcoAffecter($C, $T, [14 => 2, 15 => 0]);
verifie('un bureau sur deux tournées, 07:30 : celle du matin', $A[10] === ['t' => 1, 'mode' => 'auto'], json_encode($A[10]));
verifie('le même bureau, 12:00 : celle de midi', $A[11] === ['t' => 2, 'mode' => 'auto'], json_encode($A[11]));
verifie('un bureau d’une seule tournée', $A[12]['t'] === 2);
verifie('un client d’aucune tournée : aucune', $A[13] === ['t' => null, 'mode' => null]);
verifie('le choix du magasin passe avant le bureau', $A[14] === ['t' => 2, 'mode' => 'manuel']);
verifie('« au magasin » : sans tournée, choisi', $A[15] === ['t' => null, 'mode' => 'manuel']);
$A3 = pcoAffecter([['id' => 17, 'heure' => '16:00', 'client' => ['id' => 300]], ['id' => 18, 'heure' => '13:10', 'client' => ['id' => 300]]], $T, []);
verifie('un bureau qui commande hors de ses tournées (16:00 pour 11:15 → 12:40) : à placer', $A3[17] === ['t' => null, 'mode' => 'hors'], json_encode($A3[17]));
verifie('jusqu’à 30 min après le retour : encore la tournée', $A3[18] === ['t' => 2, 'mode' => 'auto'], json_encode($A3[18]));
$A2 = pcoAffecter([['id' => 16, 'heure' => '07:40', 'client' => ['id' => 200]]], $T, [16 => 77]);
verifie('un choix vers une tournée qui ne roule pas ce jour : la tournée du bureau', $A2[16] === ['t' => 1, 'mode' => 'auto'], json_encode($A2[16]));

echo "La journée assemblée (mardi 24/11 à 10:40)\n";
$tournees = [
    ['id' => 1, 'nom' => 'Matin · Aéropôle', 'depart' => '07:15', 'retour' => '08:35', 'livreur' => 'Thomas', 'vehicule' => 'Camionnette 1', 'jours' => '12345',
        'bureaux' => [['client' => 100, 'nom' => 'Fiduciaire', 'consigne' => 'Lunchroom', 'ordre' => 1], ['client' => 200, 'nom' => 'Aéro-Tech', 'consigne' => '', 'ordre' => 2]]],
    ['id' => 2, 'nom' => 'Midi · Charleroi', 'depart' => '11:15', 'retour' => '12:40', 'livreur' => 'Nadia', 'vehicule' => 'Camionnette 2', 'jours' => '12345',
        'bureaux' => [['client' => 300, 'nom' => 'Hainaut', 'consigne' => '', 'ordre' => 1], ['client' => 400, 'nom' => 'Lefèvre', 'consigne' => '', 'ordre' => 2], ['client' => 500, 'nom' => 'Horizon', 'consigne' => '', 'ordre' => 3]]],
    ['id' => 3, 'nom' => 'Samedi', 'depart' => '09:00', 'retour' => '10:00', 'livreur' => '', 'vehicule' => '', 'jours' => '6', 'bureaux' => [['client' => 100, 'nom' => 'Fiduciaire', 'consigne' => '', 'ordre' => 1]]],
];
$pro = static fn (int $id, string $soc) => ['id' => $id, 'is_b2b' => 1, 'company_name' => $soc, 'can_deferral' => 1, 'payment_terms' => 14];
$brut = [
    cmd(1, '2026-11-24 07:30', ['total_value' => 171.75, 'order_status' => 'picked_up', 'id_order_status' => 3, 'issuing_timestamp' => '2026-11-24 07:28:10', 'id_client_department' => 8], $pro(100, 'Fiduciaire')),
    cmd(2, '2026-11-24 07:45', ['total_value' => 64.80, 'issuing_timestamp' => '2026-11-24 07:41:00', 'order_status' => 'picked_up', 'id_order_status' => 3], $pro(200, 'Aéro-Tech')),
    cmd(3, '2026-11-24 09:30', ['total_value' => 19.90, 'id_transaction' => 4501]),
    cmd(4, '2026-11-24 12:10', ['total_value' => 118.30, 'order_status' => 'completed', 'id_order_status' => 2, 'office_offer_id' => 3], $pro(300, 'Hainaut')),
    cmd(5, '2026-11-24 11:55', ['total_value' => 151.20], $pro(500, 'Horizon')),
    cmd(6, '2026-11-24 12:00', ['total_value' => 24.50, 'is_webshop' => true, 'fulfilment_mode' => 'delivery', 'payment_status' => 'paid']),
    cmd(7, '2026-11-24 16:00', ['total_value' => 15.90]),
    cmd(8, '2026-11-24 12:30', ['total_value' => 23.80, 'is_webshop' => true, 'payment_status' => 'paid']),
    cmd(9, '2026-11-24 13:00', ['total_value' => 50.00, 'non_collection_id_reason' => 1]),
    cmd(20, '2026-11-25 07:30', ['total_value' => 160.00], $pro(100, 'Fiduciaire')),
    cmd(21, '2026-11-25 12:00', ['total_value' => 99.00], $pro(500, 'Horizon')),
    cmd(30, '2026-11-26 12:00', ['total_value' => 99.00], $pro(300, 'Hainaut')),
];
$J = pcoJournee($brut, $tournees, [], '2026-11-24', '2026-11-24 10:40');
$par = []; foreach ($J['commandes'] as $c) { $par[$c['id']] = $c; }
verifie('8 commandes du jour (l’annulée et les autres jours dehors)', count($J['commandes']) === 8, (string) count($J['commandes']));
verifie('rangées par heure', array_column($J['commandes'], 'heure') === ['07:30', '07:45', '09:30', '11:55', '12:00', '12:10', '12:30', '16:00'], implode(',', array_column($J['commandes'], 'heure')));
verifie('le total : 8 commandes, 590,15 €', $J['totaux']['total'] === ['n' => 8, 'ca' => 590.15], json_encode($J['totaux']['total']));
verifie('les canaux : bureau 2, webshop 2, comptoir 4', $J['totaux']['bureau']['n'] === 2 && $J['totaux']['webshop']['n'] === 2 && $J['totaux']['comptoir']['n'] === 4, json_encode($J['totaux']));
verifie('deux tournées roulent le mardi (pas celle du samedi)', array_column($J['tournees'], 'id') === [1, 2]);
verifie('la tournée du matin : arrêts 1 et 2, terminée', $par[1]['tournee'] === 1 && $par[1]['arret'] === 1 && $par[2]['arret'] === 2 && $J['tournees'][0]['etat'] === 'terminee', json_encode($J['tournees'][0]['etat']));
verifie('la tournée de midi : Horizon 11:55 arrêt 1, Hainaut 12:10 arrêt 2', $par[5]['tournee'] === 2 && $par[5]['arret'] === 1 && $par[4]['arret'] === 2);
verifie('midi : une commande prête, la tournée est en préparation', $J['tournees'][1]['etat'] === 'prep', $J['tournees'][1]['etat']);
verifie('midi : un bureau sans commande (Lefèvre)', $J['tournees'][1]['sansCommande'] === 1 && $J['tournees'][1]['bureaux'][1]['commande'] === null);
verifie('la livraison webshop à domicile : sans tournée, signalée', $par[6]['sansTournee'] === true && $J['totaux']['sansTournee'] === 1);
verifie('à livrer : 5 commandes (4 en tournée, 1 sans) ; à retirer : 3', $J['totaux']['livrer']['n'] === 5 && $J['totaux']['retirer']['n'] === 3, json_encode([$J['totaux']['livrer'], $J['totaux']['retirer']]));
verifie('à préparer : 4 (Horizon, la livraison webshop, le click & collect, la précommande de 16:00)', $J['totaux']['aPreparer'] === 4, (string) $J['totaux']['aPreparer']);
verifie('encaissée à 09:30 : remise', $par[3]['statut'] === 'fait');
verifie('livrée à 07:28 : l’heure de la remise', $par[1]['fait'] === '07:28', (string) $par[1]['fait']);
$tard = pcoNormaliser(cmd(40, '2026-11-24 07:30', ['issuing_timestamp' => '2026-11-24 11:41:00', 'order_status' => 'picked_up', 'id_order_status' => 3]), '2026-11-24 12:00');
verifie('marquée remise des heures après (11:41 pour 07:30) : faite, sans heure', $tard['statut'] === 'fait' && $tard['fait'] === null, json_encode($tard['fait']));
verifie('le paiement d’un bureau : sur facture', $par[5]['paiement']['code'] === 'facture');
verifie('demain : mercredi 25/11, commandes jusqu’à 16:00, encore ouvert', $J['demain']['date'] === '2026-11-25' && $J['demain']['coupure'] === '16:00' && $J['demain']['ouvert'] === true);
$dm = []; foreach ($J['demain']['tournees'] as $t) { foreach ($t['bureaux'] as $b) { $dm[$b['client']] = $b['commande']; } }
verifie('demain : Fiduciaire et Horizon ont commandé, Aéro-Tech, Hainaut et Lefèvre pas encore', $dm === [100 => true, 200 => false, 300 => false, 400 => false, 500 => true], json_encode($dm));
$J2 = pcoJournee($brut, $tournees, [6 => 2, 4 => 0], '2026-11-24', '2026-11-24 10:40');
$p2 = []; foreach ($J2['commandes'] as $c) { $p2[$c['id']] = $c; }
verifie('placée par le magasin : la livraison webshop entre dans la tournée de midi', $p2[6]['tournee'] === 2 && $p2[6]['mode'] === 'manuel' && !$p2[6]['sansTournee']);
verifie('gardée au magasin : Hainaut sort de la tournée', $p2[4]['tournee'] === null && $p2[4]['mode'] === 'manuel' && !$p2[4]['sansTournee']);
verifie('les arrêts suivent : Horizon 1, la livraison webshop 2', $p2[5]['arret'] === 1 && $p2[6]['arret'] === 2, json_encode([$p2[5]['arret'], $p2[6]['arret']]));
$J6 = pcoJournee(array_merge($brut, [cmd(41, '2026-11-24 15:30', ['total_value' => 30.0], $pro(300, 'Hainaut'))]), $tournees, [], '2026-11-24', '2026-11-24 10:40');
$p6 = []; foreach ($J6['commandes'] as $c) { $p6[$c['id']] = $c; }
verifie('Hainaut commande pour 15:30 : hors de sa tournée, signalée à placer', $p6[41]['tournee'] === null && $p6[41]['sansTournee'] === true && $J6['totaux']['sansTournee'] === 2);
$J3 = pcoJournee($brut, $tournees, [], '2026-11-24', '2026-11-24 16:30');
verifie('après 16:00 : demain est fermé', $J3['demain']['ouvert'] === false);
$J4 = pcoJournee($brut, $tournees, [], '2026-11-25', '2026-11-24 10:40');
verifie('un autre jour que aujourd’hui : pas de « demain », pas d’heure', $J4['demain'] === null && $J4['maintenant'] === null && count($J4['commandes']) === 2);
$J5 = pcoJournee($brut, $tournees, [], '2026-11-24', '2026-11-24 11:20');
verifie('midi partie à 11:15 : en route', $J5['tournees'][1]['etat'] === 'route', $J5['tournees'][1]['etat']);

echo "La semaine (lundi 23 au dimanche 29/11)\n";
$brutP = array_merge($brut, [cmd(50, '2026-11-17 08:00', ['total_value' => 40.0, 'is_webshop' => true]), cmd(51, '2026-11-20 12:00', ['total_value' => 60.0], $pro(300, 'Hainaut')),
    cmd(52, '2026-11-28 10:00', ['total_value' => 12.0])]);
$P = pcoPeriode($brutP, $tournees, ['2026-11-24' => [6 => 2]], '2026-11-23', '2026-11-29', '2026-11-24 10:40');
verifie('sept jours, du lundi au dimanche', count($P['jours']) === 7 && $P['jours'][0]['date'] === '2026-11-23' && $P['jours'][6]['date'] === '2026-11-29');
verifie('la semaine : 8 + 2 + 1 + 1 commandes (l’annulée dehors)', $P['totaux']['total']['n'] === 12, json_encode($P['totaux']['total']));
verifie('le mardi : 8 commandes, 590,15 €', $P['jours'][1]['total'] === ['n' => 8, 'ca' => 590.15], json_encode($P['jours'][1]['total']));
verifie('le mardi, la livraison webshop placée à la main : à livrer, pas « sans tournée »', $P['jours'][1]['livrer']['n'] === 5 && $P['jours'][1]['sansTournee'] === 0, json_encode($P['jours'][1]['livrer']));
$tm = array_values(array_filter($P['tournees'], static fn ($t) => $t['id'] === 1))[0];
verifie('la tournée du matin roule 5 jours sur 7', $tm['roule'] === 5 && !isset($tm['parJour']['2026-11-28']));
verifie('la tournée du matin : mardi 2 arrêts, 2 bureaux servis ; mercredi 1', $tm['parJour']['2026-11-24']['n'] === 2 && $tm['parJour']['2026-11-24']['servis'] === 2 && $tm['parJour']['2026-11-25']['n'] === 1, json_encode($tm['parJour']));
$ts = array_values(array_filter($P['tournees'], static fn ($t) => $t['id'] === 3))[0];
verifie('la tournée du samedi : un jour, sans commande (le client du samedi n’est pas un bureau)', $ts['roule'] === 1 && $ts['total']['n'] === 0);
$bx = []; foreach ($P['bureaux'] as $b) { $bx[$b['client']] = $b; }
verifie('Fiduciaire : 2 commandes (mardi, mercredi), la dernière mercredi', $bx[100]['total']['n'] === 2 && $bx[100]['dernier'] === '2026-11-25' && count($bx[100]['parJour']) === 2);
verifie('Lefèvre, sur une tournée, sans commande : listé à 0', isset($bx[400]) && $bx[400]['total']['n'] === 0);
verifie('les bureaux, le plus gros d’abord', $P['bureaux'][0]['total']['ca'] >= $P['bureaux'][1]['total']['ca']);
verifie('la semaine d’avant : 16 au 22/11, deux commandes, 100 €', $P['avant']['du'] === '2026-11-16' && $P['avant']['total'] === ['n' => 2, 'ca' => 100.0], json_encode($P['avant']));
verifie('la semaine d’avant par canal : webshop 1, bureau 0 (Hainaut au comptoir)', $P['avant']['webshop']['n'] === 1 && $P['avant']['comptoir']['n'] === 1, json_encode($P['avant']));

echo "Le panel sans commande\n";
verifie('404 NO_ORDERS_FOR_PICKUP_DATE : aucune commande, pas une panne', pcoAucuneCommande(404, ['status' => 'error', 'description' => 'NO_ORDERS_FOR_PICKUP_DATE']));
verifie('un autre 404 : une panne', !pcoAucuneCommande(404, ['description' => 'NOT_FOUND']) && !pcoAucuneCommande(500, ['description' => 'NO_ORDERS']));

echo "Une tournée lue, les clients pro\n";
[$t, $e] = pcoTourneeLue(['nom' => ' Midi ', 'depart' => '11:15', 'retour' => '12:40', 'jours' => '5321x3', 'livreur' => 'Nadia']);
verifie('les jours rangés, sans doublon', $t !== null && $t['jours'] === '1235' && $t['nom'] === 'Midi', json_encode([$t, $e], JSON_UNESCAPED_UNICODE));
verifie('le retour avant le départ : refusé', pcoTourneeLue(['nom' => 'X', 'depart' => '12:00', 'retour' => '11:00', 'jours' => '1'])[0] === null);
verifie('sans nom : refusé', pcoTourneeLue(['nom' => '', 'depart' => '11:00', 'retour' => '12:00', 'jours' => '1'])[0] === null);
verifie('sans jour : refusé', pcoTourneeLue(['nom' => 'X', 'depart' => '11:00', 'retour' => '12:00', 'jours' => '89'])[0] === null);
verifie('une heure mal écrite : refusée', pcoTourneeLue(['nom' => 'X', 'depart' => '7:00', 'retour' => '12:00', 'jours' => '1'])[0] === null);
$B = pcoBureauxDe([['id' => 3, 'is_b2b' => 1, 'company_name' => 'Zénith', 'city' => 'Jumet', 'zip' => '6040', 'password_hash' => 'x'],
    ['id' => 4, 'is_b2b' => 0, 'company_name' => 'Particulier SA'], ['id' => 5, 'is_b2b' => 1, 'company_name' => ''],
    ['id' => 6, 'is_b2b' => 1, 'company_name' => 'Ancien', 'active' => 0], ['id' => 7, 'is_b2b' => 1, 'company_name' => 'Fusionné', 'merged_into_id' => 3],
    ['id' => 8, 'is_b2b' => 1, 'company_name' => 'Atelier Delta', 'city' => 'Gosselies']]);
verifie('les clients pro actifs avec une société, par nom', array_column($B, 'id') === [8, 3], json_encode($B, JSON_UNESCAPED_UNICODE));
verifie('rien que l’id, la société, la ville et le code postal', array_keys($B[1]) === ['id', 'nom', 'ville', 'cp']);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
