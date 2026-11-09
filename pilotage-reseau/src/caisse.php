<?php
declare(strict_types=1);

/**
 * LA CAISSE — ouvertures, fermetures et reports, magasin par magasin, semaine
 * par semaine. Lecture seule, TFBuddy seul : rien n'est recopié en base, sauf
 * le mémo des journées closes (une journée passée ne bouge plus).
 *
 * Ce que TFBuddy rend, mesuré le 09/11/2026 avec le compte consultant :
 *  · les services d'un jour : GET /shops/{id}/owner/daily-shifts?date= —
 *    ouverture, fermeture, employés, `initial_cash` (le fond),
 *    `final_cash` (le total déclaré), `left_cash_for_next_day` (le laissé),
 *    `is_closed` ;
 *  · la caisse d'un service : GET /daily-shifts/{id} → `id_device` (la liste
 *    du jour ne la porte pas) ;
 *  · le nom des caisses : GET /shops/{id}/devices. ATTENTION : cette route
 *    rend aussi les secrets de chaque caisse (hachage du mot de passe, jeton,
 *    clé de connexion, code PIN). On n'en garde QUE l'identifiant et le nom ;
 *    rien d'autre n'est conservé ni renvoyé ;
 *  · la recette et les moyens de paiement du jour : l'export comptable
 *    GET /shops/{id}/reports/sales/daily/cash-flow/csv?date= (CSV, « ; »,
 *    virgule décimale) — c'est la seule source des espèces encaissées, des
 *    versements et des dépôts.
 *
 * Ce que TFBuddy ne rend pas : les RETRAITS. Ils s'enregistrent à la caisse
 * (POST /devices/{id}/cash-withdrawals) mais aucune route ne les relit.
 *
 * Les règles de contrôle (report rompu, montant impossible…) vivent dans la
 * page (public/caisse/caisse.js) : une seule définition, là où elle se lit.
 */

/** Le lundi de la semaine d'une date « YYYY-MM-DD ». */
function caisseLundi(string $d): string
{
    $t = strtotime($d . ' 12:00:00');
    return date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
}

/** Un montant de TFBuddy (« 12.00 », null) en nombre, ou null. */
function caisseNombre($v): ?float
{
    return $v === null || $v === '' || !is_numeric($v) ? null : round((float) $v, 2);
}

/**
 * Le nom de chaque caisse d'un magasin : [id => nom]. Mémo d'un jour.
 * De la route on ne retient QUE l'identifiant et le nom (voir l'en-tête).
 */
function caisseNoms(int $shop, bool $relire = false): array
{
    $cle = 'caisseNoms:' . $shop;
    $m = setting($cle);
    if (!$relire && is_array($m) && (string) ($m['le'] ?? '') === date('Y-m-d') && isset($m['n'])) {
        $out = [];
        foreach ((array) $m['n'] as $id => $nom) { $out[(int) $id] = (string) $nom; }
        return $out;
    }
    $r = PanelApi::get('/shops/' . $shop . '/devices');
    $out = [];
    foreach (PanelApi::liste(is_array($r) ? $r : []) as $d) {
        if (!is_array($d) || (int) ($d['id'] ?? 0) <= 0) { continue; }
        $nom = trim((string) ($d['name'] ?? ''));
        $out[(int) $d['id']] = $nom !== '' ? mb_strtoupper(mb_substr($nom, 0, 1)) . mb_substr($nom, 1) : 'Caisse ' . (int) $d['id'];
    }
    if ($out !== []) {
        try {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$cle, json_encode(['le' => date('Y-m-d'), 'n' => $out], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* sans mémo : relu la prochaine fois */ }
    }
    return $out;
}

/** Un service de la liste du jour, mis à plat. La caisse vient d'ailleurs. */
function caisseService(array $s, string $jour): array
{
    return [
        'id'      => (int) ($s['id'] ?? 0),
        'jour'    => (string) ($s['shift_date'] ?? $jour),
        'caisse'  => isset($s['id_device']) && is_numeric($s['id_device']) ? (int) $s['id_device'] : null,
        'ouv'     => $s['opening_time'] ?? null,
        'ferm'    => $s['closing_time'] ?? null,
        'fond'    => caisseNombre($s['initial_cash'] ?? null),
        'final'   => caisseNombre($s['final_cash'] ?? null),
        'laisse'  => caisseNombre($s['left_cash_for_next_day'] ?? null),
        'parOuv'  => trim((string) ($s['opening_employee'] ?? $s['opening_employee_display_name'] ?? '')),
        'parFerm' => trim((string) ($s['closing_employee'] ?? $s['closing_employee_display_name'] ?? '')),
        'ferme'   => !empty($s['is_closed']),
    ];
}

/**
 * L'export comptable d'un jour, lu : la recette, chaque moyen de paiement, et
 * les trois lignes qui comptent pour les espèces. null si illisible.
 *
 * Forme mesurée : `Date;Type de compte;Compte;Commentaire;Numero de facture;
 * Nom;N° TVA;Sorties;Entrées`. La recette est la ligne C 400000 (RECETCAIS,
 * colonne Entrées) ; chaque moyen de paiement est une ligne G 58xxxx dont le
 * nom est le Commentaire et le montant la colonne Sorties. « Versement
 * espèces » et « Dépôts » sont vides quand rien n'a été saisi.
 *
 * @return array{recette: ?float, moyens: list<array{nom: string, compte: string, montant: ?float}>, especes: ?float, versements: ?float, depots: ?float}|null
 */
function caisseComptable(?string $csv): ?array
{
    if ($csv === null || trim($csv) === '') { return null; }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lignes = preg_split('/\r\n|\n|\r/', trim($csv));
    if (!$lignes || stripos($lignes[0], 'compte') === false) { return null; }
    $num = static function (string $v): ?float {
        $v = trim(str_replace(["\u{202F}", "\u{00A0}", ' '], '', $v));
        if ($v === '') { return null; }
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? round((float) $v, 2) : null;
    };
    $out = ['recette' => null, 'moyens' => [], 'especes' => null, 'versements' => null, 'depots' => null];
    foreach (array_slice($lignes, 1) as $l) {
        $c = str_getcsv($l, ';', '"', '');
        if (count($c) < 9) { continue; }
        [$type, $compte, $nom] = [trim($c[1]), trim($c[2]), trim($c[3])];
        $sortie = $num($c[7]); $entree = $num($c[8]);
        if ($type === 'C') { $out['recette'] = ($out['recette'] ?? 0.0) + ($entree ?? 0.0) - ($sortie ?? 0.0); continue; }
        $m = $sortie ?? ($entree !== null ? -$entree : null);
        if (preg_match('/versement/i', $nom)) { $out['versements'] = $m === null ? $out['versements'] : ($out['versements'] ?? 0.0) + $m; continue; }
        if (preg_match('/d[ée]p[oô]t/iu', $nom)) { $out['depots'] = $m === null ? $out['depots'] : ($out['depots'] ?? 0.0) + $m; continue; }
        if (preg_match('/esp[eè]ce/iu', $nom)) { $out['especes'] = ($out['especes'] ?? 0.0) + ($m ?? 0.0); }
        // Deux lignes du même nom (Webshop porte deux comptes) : additionnées.
        $trouve = false;
        foreach ($out['moyens'] as &$x) {
            if ($x['nom'] === $nom) { $x['montant'] = $m === null ? $x['montant'] : ($x['montant'] ?? 0.0) + $m; $trouve = true; break; }
        }
        unset($x);
        if (!$trouve) { $out['moyens'][] = ['nom' => $nom, 'compte' => $compte, 'montant' => $m]; }
    }
    foreach (['recette', 'especes', 'versements', 'depots'] as $k) { if ($out[$k] !== null) { $out[$k] = round($out[$k], 2); } }
    return $out;
}

/**
 * GET /caisse/semaine?shop=2&date=2026-11-04[&rafraichir=1]
 *
 * La semaine (lundi → dimanche) qui contient `date`, pour un magasin : les
 * services de caisse du dimanche d'avant au lundi d'après (le report qui entre
 * dans la semaine, et l'ouverture qui suit le dernier soir), le nom des
 * caisses, et l'export comptable de chacun des sept jours.
 *
 * Une journée close (avant-hier ou plus ancienne) se lit une fois et se garde
 * (`ceo_app_setting`, clé `caisseJ:{shop}:{jour}`) ; aujourd'hui et hier se
 * relisent à chaque appel. `rafraichir=1` relit tout.
 */
function ep_caisse_semaine(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'paramètre shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte consultant de l’API TFBuddy non configuré (Mon compte)']; }
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) { $date = date('Y-m-d'); }
    $relire = !empty($_GET['rafraichir']);

    $du = caisseLundi($date);
    $au = date('Y-m-d', strtotime($du . ' +6 days'));
    $auj = date('Y-m-d');
    $clos = date('Y-m-d', strtotime('-2 days'));
    $jours = [];
    for ($j = date('Y-m-d', strtotime($du . ' -1 day')); $j <= date('Y-m-d', strtotime($au . ' +1 day')); $j = date('Y-m-d', strtotime($j . ' +1 day'))) {
        $jours[] = $j;
    }

    // 1) Ce qui est déjà gardé.
    $parJour = []; $lire = []; $anciens = [];
    foreach ($jours as $j) {
        if ($j > $auj) { $parJour[$j] = ['s' => [], 'c' => null, 'futur' => true]; continue; }
        $g0 = setting('caisseJ:' . $shop . ':' . $j);
        $g = $relire ? null : $g0;
        // Un jour gardé depuis une semaine voisine (le dimanche d'avant, le lundi
        // d'après) l'a été SANS son export comptable, qui ne servait pas là-bas :
        // dans SA semaine, il faut le relire.
        $sansExport = $j >= $du && $j <= $au && is_array($g) && ($g['c'] ?? null) === null;
        if (is_array($g) && isset($g['s']) && $j <= $clos && !$sansExport) { $parJour[$j] = $g; continue; }
        // Même en relecture forcée : l'export déjà gardé d'un jour de bord reste.
        $anciens[$j] = is_array($g0) ? $g0 : null;
        $lire[] = $j;
    }

    // 2) Les services et l'export comptable des jours à lire, ensemble.
    if ($lire !== []) {
        $chemins = []; $csv = [];
        foreach ($lire as $j) {
            $chemins['s' . $j] = '/shops/' . $shop . '/owner/daily-shifts?date=' . $j;
            if ($j >= $du && $j <= $au) { $csv['c' . $j] = '/shops/' . $shop . '/reports/sales/daily/cash-flow/csv?date=' . $j; }
        }
        $rs = PanelApi::getParallele($chemins, 6);
        $rc = $csv === [] ? [] : PanelApi::getParallele($csv, 4, 25, true);
        // La caisse de chaque service : une lecture par service, toutes ensemble.
        $detail = [];
        foreach ($lire as $j) {
            $r = $rs['s' . $j] ?? null;
            foreach ((array) (is_array($r) ? ($r['shifts'] ?? PanelApi::liste($r)) : []) as $s) {
                if (is_array($s) && (int) ($s['id'] ?? 0) > 0 && !isset($s['id_device'])) { $detail['d' . (int) $s['id']] = '/daily-shifts/' . (int) $s['id']; }
            }
        }
        $rd = $detail === [] ? [] : PanelApi::getParallele($detail, 6);
        foreach ($lire as $j) {
            $r = $rs['s' . $j] ?? null;
            if (!is_array($r)) { $parJour[$j] = ['s' => null, 'c' => null, 'erreur' => 'services illisibles']; continue; }
            $svc = [];
            foreach ((array) ($r['shifts'] ?? PanelApi::liste($r)) as $s) {
                if (!is_array($s)) { continue; }
                $x = caisseService($s, $j);
                if ($x['caisse'] === null) {
                    $d = $rd['d' . $x['id']] ?? null;
                    if (is_array($d) && isset($d['id_device']) && is_numeric($d['id_device'])) { $x['caisse'] = (int) $d['id_device']; }
                }
                $svc[] = $x;
            }
            // Hors de la semaine, l'export n'est pas relu : celui déjà gardé reste.
            $c = ($j >= $du && $j <= $au) ? caisseComptable($rc['c' . $j] ?? null) : ($anciens[$j]['c'] ?? null);
            $parJour[$j] = ['s' => $svc, 'c' => $c, 'le' => date('Y-m-d H:i')];
            // Une journée close se garde — si tout a été lu, caisses comprises.
            $complet = ($j < $du || $j > $au || $c !== null) && !array_filter($svc, static fn ($x) => $x['caisse'] === null);
            if ($j <= $clos && $complet) {
                try {
                    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                        ['caisseJ:' . $shop . ':' . $j, json_encode($parJour[$j], JSON_UNESCAPED_UNICODE)]);
                } catch (Throwable $e) { /* sans mémo : relu la prochaine fois */ }
            }
        }
    }

    // 3) La réponse.
    $services = []; $comptable = []; $lacunes = [];
    foreach ($jours as $j) {
        $p = $parJour[$j] ?? ['s' => null];
        if ($p['s'] === null) { $lacunes[] = ['jour' => $j, 'quoi' => 'services de caisse illisibles']; }
        foreach ((array) $p['s'] as $s) { $services[] = $s; }
        if ($j >= $du && $j <= $au && empty($p['futur'])) {
            $comptable[$j] = $p['c'] ?? null;
            if (($p['c'] ?? null) === null) { $lacunes[] = ['jour' => $j, 'quoi' => 'export comptable illisible']; }
        }
    }
    $noms = caisseNoms($shop, $relire);
    $ids = array_values(array_unique(array_filter(array_map(static fn ($s) => $s['caisse'], $services), static fn ($v) => $v !== null)));
    sort($ids);
    $caisses = array_map(static fn ($id) => ['id' => $id, 'nom' => $noms[$id] ?? ('Caisse ' . $id)], $ids);
    // L'ordre d'affichage : la caisse qui encaisse le plus en premier (le total déclaré de la semaine).
    $poids = [];
    foreach ($services as $s) { if ($s['caisse'] !== null) { $poids[$s['caisse']] = ($poids[$s['caisse']] ?? 0) + (float) ($s['final'] ?? 0); } }
    usort($caisses, static fn ($a, $b) => ($poids[$b['id']] ?? 0) <=> ($poids[$a['id']] ?? 0) ?: $a['id'] <=> $b['id']);

    $nom = null;
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$shop]); $nom = $r['name'] ?? null; } catch (Throwable $e) { /* sans table : l'identifiant */ }

    return [
        'shop'      => ['id' => $shop, 'nom' => $nom ?? ('Magasin #' . $shop)],
        'du'        => $du,
        'au'        => $au,
        'jours'     => array_slice($jours, 1, 7),
        'caisses'   => $caisses,
        'services'  => $services,
        'comptable' => $comptable,
        'lacunes'   => $lacunes,
        'lu'        => date('c'),
        'sources'   => [
            'services'  => 'TFBuddy · GET /shops/{id}/owner/daily-shifts?date= (caisse : GET /daily-shifts/{id})',
            'comptable' => 'TFBuddy · export comptable GET /shops/{id}/reports/sales/daily/cash-flow/csv?date=',
            'retraits'  => 'aucune route de lecture (POST /devices/{id}/cash-withdrawals en écriture seulement)',
        ],
    ];
}

/**
 * Le nom de chaque vendeuse d'un magasin : [id => nom affiché]. Mémo d'un jour.
 * ATTENTION : GET /shops/{id}/employees rend aussi les secrets de chacun
 * (hachage du mot de passe, jeton, code PIN). On n'en garde QUE l'identifiant
 * et le nom affiché ; rien d'autre n'est conservé ni renvoyé.
 */
function caisseVendeuses(int $shop, bool $relire = false): array
{
    $cle = 'caisseEmp:' . $shop;
    $m = setting($cle);
    if (!$relire && is_array($m) && (string) ($m['le'] ?? '') === date('Y-m-d') && isset($m['n'])) {
        $out = [];
        foreach ((array) $m['n'] as $id => $nom) { $out[(int) $id] = (string) $nom; }
        return $out;
    }
    $r = PanelApi::get('/shops/' . $shop . '/employees');
    $out = [];
    foreach (PanelApi::liste(is_array($r) ? $r : []) as $e) {
        if (!is_array($e) || (int) ($e['id'] ?? 0) <= 0) { continue; }
        $nom = trim((string) ($e['display_name'] ?? ''));
        if ($nom === '') {
            $nom = trim((string) ($e['name'] ?? '') . ' ' . mb_substr(trim((string) ($e['surname'] ?? '')), 0, 1) . (trim((string) ($e['surname'] ?? '')) !== '' ? '.' : ''));
        }
        $out[(int) $e['id']] = $nom !== '' ? $nom : 'Vendeuse ' . (int) $e['id'];
    }
    if ($out !== []) {
        try {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$cle, json_encode(['le' => date('Y-m-d'), 'n' => $out], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* sans mémo : relu la prochaine fois */ }
    }
    return $out;
}

/**
 * Quel `id_payment_type` est quel moyen de paiement ? TFBuddy ne le dit nulle
 * part : ni le ticket, ni une route. On le CALE sur l'export comptable du même
 * jour, qui, lui, nomme ses moyens (« Espèce », « Bancontact »…) : le total des
 * tickets d'un type doit égaler le montant d'un moyen.
 *
 * Deux sommes par type sont essayées — tous les tickets, puis sans les tickets
 * à facturer ou en paiement différé, que l'export peut écarter. Un rapprochement
 * à 1 € près est « exact » et s'apprend (`ceo_app_setting.caissePaiements`, la
 * correspondance vaut pour tout le réseau) ; à 2 % près, « approché ». Un jour
 * sans rapprochement retombe sur ce qui a été appris.
 *
 * @param array<int, array{n: int, total: float, totalCaisse: float}> $parType
 * @return array{noms: array<int, string>, calage: array<int, string>}
 */
function caisseCalePaiements(array $parType, ?array $comptable): array
{
    $appris = setting('caissePaiements', []);
    $appris = is_array($appris) ? $appris : [];
    $noms = []; $calage = []; $pris = [];
    foreach ((array) ($comptable['moyens'] ?? []) as $m) {
        $v = (float) ($m['montant'] ?? 0);
        if ($v <= 0) { continue; }
        $meilleur = null; $ecart = INF;
        foreach ($parType as $id => $s) {
            if (isset($pris[$id])) { continue; }
            foreach ([$s['total'], $s['totalCaisse']] as $tot) {
                $e = abs($tot - $v);
                if ($e < $ecart) { $ecart = $e; $meilleur = (int) $id; }
            }
        }
        if ($meilleur === null) { continue; }
        if ($ecart <= 1.0) {
            $noms[$meilleur] = (string) $m['nom']; $calage[$meilleur] = 'exact'; $pris[$meilleur] = true;
            $appris[(string) $meilleur] = (string) $m['nom'];
        } elseif ($ecart <= 0.02 * $v) {
            $noms[$meilleur] = (string) $m['nom']; $calage[$meilleur] = 'approché'; $pris[$meilleur] = true;
        }
    }
    foreach ($parType as $id => $s) {
        if (!isset($noms[$id]) && isset($appris[(string) $id])) { $noms[$id] = (string) $appris[(string) $id]; $calage[$id] = 'appris'; }
    }
    try {
        if ($appris !== setting('caissePaiements', [])) {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                ['caissePaiements', json_encode($appris, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) { /* sans mémo : recalé la prochaine fois */ }
    return ['noms' => $noms, 'calage' => $calage];
}

/**
 * GET /caisse/jour?shop=2&date=2026-11-08[&rafraichir=1]
 *
 * Les tickets EN ESPÈCES d'une journée, un par un : l'heure, la caisse, la
 * vendeuse, le montant. Source : la liste des tickets du jour,
 * GET /shops/{id}/transactions?date= (un appel, tous les tickets), dont chaque
 * ligne porte `id_device` et `id_payment_type`. Le type « espèces » est calé
 * sur l'export comptable du jour (`caisseCalePaiements()`), et la réponse dit
 * comment : exact, approché, appris ou introuvable.
 *
 * Une journée close se garde (`caisseT:{shop}:{jour}`, les seuls tickets en
 * espèces et les totaux par type) ; aujourd'hui et hier se relisent.
 */
function ep_caisse_jour(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'paramètre shop requis']; }
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte consultant de l’API TFBuddy non configuré (Mon compte)']; }
    $jour = (string) ($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || strtotime($jour) === false) { http_response_code(400); return ['error' => 'date invalide (YYYY-MM-DD)']; }
    if ($jour > date('Y-m-d')) { http_response_code(422); return ['error' => 'ce jour n’est pas encore passé']; }
    $relire = !empty($_GET['rafraichir']);
    $clos = $jour <= date('Y-m-d', strtotime('-2 days'));
    $cle = 'caisseT:' . $shop . ':' . $jour;

    $g = $relire ? null : setting($cle);
    if (!(is_array($g) && isset($g['tickets']) && $clos)) {
        // L'export comptable du jour : celui que la semaine a déjà gardé, sinon relu.
        $sem = setting('caisseJ:' . $shop . ':' . $jour);
        $comptable = is_array($sem) && is_array($sem['c'] ?? null) ? $sem['c'] : null;
        $chemins = ['t' => '/shops/' . $shop . '/transactions?date=' . $jour];
        $r = PanelApi::getParallele($chemins, 2);
        if ($comptable === null) {
            $rc = PanelApi::getParallele(['c' => '/shops/' . $shop . '/reports/sales/daily/cash-flow/csv?date=' . $jour], 1, 25, true);
            $comptable = caisseComptable($rc['c'] ?? null);
        }
        $liste = $r['t'] ?? null;
        if (!is_array($liste)) {
            http_response_code(502);
            return ['error' => 'liste des tickets du jour illisible chez TFBuddy' . (PanelApi::$lastError ? ' : ' . PanelApi::$lastError : '')];
        }
        $parType = []; $tickets = [];
        foreach (PanelApi::liste($liste) as $t) {
            if (!is_array($t)) { continue; }
            $type = isset($t['id_payment_type']) && is_numeric($t['id_payment_type']) ? (int) $t['id_payment_type'] : -1;
            $m = caisseNombre($t['total_gross_amount_after_discount'] ?? $t['total_value_after_discount'] ?? null) ?? 0.0;
            $aFacturer = !empty($t['will_be_invoiced']) || !empty($t['deferral_payment']);
            $parType[$type] = $parType[$type] ?? ['n' => 0, 'total' => 0.0, 'totalCaisse' => 0.0];
            $parType[$type]['n']++;
            $parType[$type]['total'] += $m;
            if (!$aFacturer) { $parType[$type]['totalCaisse'] += $m; }
            $tickets[] = ['id' => (int) ($t['id'] ?? 0), 'type' => $type, 'heure' => (string) ($t['insert_timestamp'] ?? ''),
                'caisse' => isset($t['id_device']) && is_numeric($t['id_device']) ? (int) $t['id_device'] : null,
                'employe' => isset($t['id_employee']) && is_numeric($t['id_employee']) ? (int) $t['id_employee'] : null,
                'montant' => $m, 'facture' => $aFacturer, 'pro' => !empty($t['is_client_b2b']),
                'corrige' => !empty($t['has_corrections'])];
        }
        foreach ($parType as &$s) { $s['total'] = round($s['total'], 2); $s['totalCaisse'] = round($s['totalCaisse'], 2); }
        unset($s);
        $cal = caisseCalePaiements($parType, $comptable);
        // Le type « espèces » : celui calé sur la ligne « Espèce » de l'export.
        $typeEsp = null;
        foreach ($cal['noms'] as $id => $nom) { if (preg_match('/esp[eè]ce/iu', $nom)) { $typeEsp = (int) $id; break; } }
        $esp = array_values(array_filter($tickets, static fn ($t) => $typeEsp !== null && $t['type'] === $typeEsp));
        usort($esp, static fn ($a, $b) => strcmp($a['heure'], $b['heure']));
        $paiements = [];
        foreach ($parType as $id => $s) {
            $paiements[] = ['id' => (int) $id, 'nom' => $cal['noms'][$id] ?? null, 'calage' => $cal['calage'][$id] ?? 'introuvable',
                'n' => $s['n'], 'total' => $s['total']];
        }
        usort($paiements, static fn ($a, $b) => $b['total'] <=> $a['total']);
        $totEsp = round(array_sum(array_column($esp, 'montant')), 2);
        $g = [
            'tickets'   => $esp,
            'paiements' => $paiements,
            'especes'   => ['type' => $typeEsp, 'calage' => $typeEsp !== null ? ($cal['calage'][$typeEsp] ?? 'introuvable') : 'introuvable',
                'n' => count($esp), 'totalTickets' => $totEsp, 'totalExport' => $comptable['especes'] ?? null,
                'ecart' => isset($comptable['especes']) ? round($totEsp - (float) $comptable['especes'], 2) : null],
            'nTickets'  => count($tickets),
            'le'        => date('Y-m-d H:i'),
        ];
        if ($clos && $typeEsp !== null) {
            try {
                Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    [$cle, json_encode($g, JSON_UNESCAPED_UNICODE)]);
            } catch (Throwable $e) { /* sans mémo : relu la prochaine fois */ }
        }
    }

    // Les noms : vendeuses et caisses, résolus à la lecture (ils changent sans que la journée change).
    $vend = caisseVendeuses($shop, $relire);
    $caisses = caisseNoms($shop, $relire);
    foreach ($g['tickets'] as &$t) {
        $t['vendeuse'] = $t['employe'] !== null ? ($vend[$t['employe']] ?? ('#' . $t['employe'])) : null;
        $t['caisseNom'] = $t['caisse'] !== null ? ($caisses[$t['caisse']] ?? ('Caisse ' . $t['caisse'])) : null;
    }
    unset($t);
    return ['shop' => $shop, 'jour' => $jour] + $g + [
        'source' => 'TFBuddy · GET /shops/{id}/transactions?date= ; type « espèces » calé sur l’export comptable du jour',
    ];
}
