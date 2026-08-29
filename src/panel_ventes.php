<?php

declare(strict_types=1);

/**
 * Les VENTES par les endpoints du panel (atelierby.tfbuddy.com) — le
 * remplaçant de la table locale `transaction`, morte à la mi-juillet.
 *
 * Règle actée : plus de copie locale des données sources, seulement les
 * endpoints. Pour un mois entier :
 *   · CA et tickets PAR VENDEUSE : /shops/{id}/statistics/sales/employees/{date},
 *     un appel par magasin et par jour, agrégé ici par personne ;
 *   · la ventilation par créneau (la difficulté mesurée du coefficient) :
 *     /shops/{id}/statistics/sales/hourly-distribution/{date}.
 *
 * Les LIGNES PAR TICKET par vendeuse n'existent dans aucune route (mesuré :
 * /receipt vide, pas de /products par ticket) — elles restent nulles, et un
 * record ne se joue jamais sur un zéro inventé. La route est à réclamer à
 * tfbuddy ; le jour où elle répond, elle se branche ici.
 *
 * Un mois RÉVOLU se calcule une fois et se grave (ceo_app_setting.pvMois…) ;
 * le mois en cours expire au bout d'une heure. Ce cache est un agrégat du
 * cockpit, pas un miroir de la source.
 */

function panelVentesMois(string $m): ?array
{
    if (!function_exists('setting') || !PanelApi::configured()) { return null; }
    $encours = $m >= date('Y-m');
    $cache = setting('pvMois' . $m);
    if (is_array($cache) && isset($cache['d'])
        && (!$encours || (int) ($cache['quand'] ?? 0) > time() - 3600)) {
        return $cache['d'];
    }

    [$du, $au] = venteBornes($m);
    $jours = [];
    $fin = min(substr($au, 0, 10), date('Y-m-d', strtotime('+1 day')));
    for ($j = substr($du, 0, 10); $j < $fin; $j = date('Y-m-d', strtotime($j . ' +1 day'))) { $jours[] = $j; }
    if ($jours === []) { return null; }

    try { $shops = Db::rows('SELECT id FROM shops WHERE active = 1'); }
    catch (PDOException $e) { return null; }
    $idDe = [];
    foreach (venteEmployes() as $id => $e2) { $idDe[$e2['nom']] = (int) $id; }

    $chemins = [];
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        foreach ($jours as $j) {
            $chemins['e' . $sid . '_' . $j] = '/shops/' . $sid . '/statistics/sales/employees/' . $j;
            $chemins['h' . $sid . '_' . $j] = '/shops/' . $sid . '/statistics/sales/hourly-distribution/' . $j;
        }
    }
    $res = PanelApi::getParallele($chemins, 8);

    $ventes = []; $sans = ['tickets' => 0, 'ca' => 0.0];
    $caSeg = ['matSem' => 0.0, 'amSem' => 0.0, 'matWe' => 0.0, 'amWe' => 0.0];
    $joursServis = 0;
    foreach ($shops as $s) {
        $sid = (int) $s['id'];
        foreach ($jours as $j) {
            $le = $res['e' . $sid . '_' . $j] ?? null;
            if (is_array($le)) {
                $joursServis++;
                foreach (analyseListe($le) as $r) {
                    $t = (int) ($r['transactions_qty'] ?? 0);
                    $ca = (float) ($r['total_receipt_value'] ?? 0);
                    if ($t === 0 && $ca === 0.0) { continue; }
                    $id = $idDe[(string) ($r['display_name'] ?? '')] ?? null;
                    // Une vendeuse que le référentiel ne connaît pas rejoint le
                    // « sans vendeur » : visible à l'écran, jamais perdue.
                    if ($id === null) { $sans['tickets'] += $t; $sans['ca'] += $ca; continue; }
                    if (!isset($ventes[$id])) { $ventes[$id] = ['tickets' => 0, 'ca' => 0.0, 'lignes' => null]; }
                    $ventes[$id]['tickets'] += $t;
                    $ventes[$id]['ca'] += $ca;
                }
            }
            $lh = $res['h' . $sid . '_' . $j] ?? null;
            if (is_array($lh)) {
                $we = (int) date('N', strtotime($j)) >= 6;
                foreach (analyseListe($lh) as $r) {
                    $h = (int) substr((string) ($r['hour_from'] ?? '00'), 0, 2);
                    $cle = $we ? ($h >= VENTE_CRENEAU_BASCULE ? 'amWe' : 'matWe')
                               : ($h >= VENTE_CRENEAU_BASCULE ? 'amSem' : 'matSem');
                    $caSeg[$cle] += (float) ($r['income'] ?? 0);
                }
            }
        }
    }
    // Un mois troué n'est pas un mois : sous 90 % de jours-magasins servis,
    // mieux vaut « indisponible » qu'un classement bâti sur trois jours.
    $attendus = count($jours) * max(1, count($shops));
    if ($ventes === [] || $joursServis < (int) ceil($attendus * 0.9)) { return null; }

    $d = ['ventes' => $ventes, 'sans' => $sans, 'caSeg' => $caSeg, 'source' => 'endpoints'];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['pvMois' . $m, json_encode(['quand' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE)]);
    return $d;
}
