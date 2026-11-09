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
    $parJour = []; $lire = [];
    foreach ($jours as $j) {
        if ($j > $auj) { $parJour[$j] = ['s' => [], 'c' => null, 'futur' => true]; continue; }
        $g = $relire ? null : setting('caisseJ:' . $shop . ':' . $j);
        if (is_array($g) && isset($g['s']) && $j <= $clos) { $parJour[$j] = $g; continue; }
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
            $c = ($j >= $du && $j <= $au) ? caisseComptable($rc['c' . $j] ?? null) : null;
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
