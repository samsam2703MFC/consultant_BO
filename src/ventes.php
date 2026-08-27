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

/** Sous ce volume d'heures, on montre mais on ne classe pas. */
const VENTE_SEUIL_HEURES = 20;

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
    // passe minuit ne devient pas négatif.
    $heures = [];
    try {
        foreach (Db::rows('SELECT id_employee,
                                  SUM((TIME_TO_SEC(end_hour) - TIME_TO_SEC(start_hour) + 86400) % 86400) / 3600 h
                             FROM franchisee_employee_schedule
                            WHERE work_date >= ? AND work_date < ?
                            GROUP BY id_employee', [substr($du, 0, 10), substr($au, 0, 10)]) as $r) {
            $heures[(int) $r['id_employee']] = (float) $r['h'];
        }
    } catch (PDOException $e) {
        return ['lignes' => [], 'sansVendeur' => [], 'motif' => 'le planning du panel n’a pas pu être lu : ' . $e->getMessage()];
    }

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
        $classable = $h >= VENTE_SEUIL_HEURES && $ca > 0;
        $lignes[] = [
            'id' => $id, 'nom' => $e['nom'],
            'shopId' => $e['shop'], 'magasin' => $nomDe[$e['shop']] ?? ('Magasin ' . $e['shop']),
            'heures' => round($h, 1),
            'ca' => (int) round($ca),
            'tickets' => $tickets,
            'caHeure' => $classable ? (int) round($ca / $h) : null,
            'panier' => $tickets > 0 ? round($ca / $tickets, 2) : null,
            'lignesTicket' => $tickets > 0 ? round(($v['lignes'] ?? 0) / $tickets, 1) : null,
            'classable' => $classable,
            'motifHorsClassement' => $classable ? null
                : ($ca <= 0 ? 'aucune vente à son nom'
                    : ($h <= 0 ? 'aucune heure au planning' : 'moins de ' . VENTE_SEUIL_HEURES . ' h au planning')),
        ];
    }
    // Les classables d'abord, au CA / heure ; les autres suivent, montrés
    // mais jamais classés.
    usort($lignes, static fn ($a, $b) =>
        ($b['classable'] <=> $a['classable'])
        ?: (($b['caHeure'] ?? 0) <=> ($a['caHeure'] ?? 0))
        ?: ($b['ca'] <=> $a['ca']));
    $rang = 0;
    foreach ($lignes as $i => $l) {
        $lignes[$i]['rang'] = $l['classable'] ? ++$rang : null;
    }
    return ['lignes' => $lignes, 'sansVendeur' => $sans, 'motif' => null];
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

    $out = ['m' => $m, 'seuil' => VENTE_SEUIL_HEURES, 'primes' => ventePrimesConfig(),
        'mois' => [], 'magasins' => array_map(static fn ($id, $n) => ['id' => $id, 'nom' => $n],
            array_keys($nomDe), $nomDe)];
    for ($i = 5; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $out['mois'][] = ['cle' => date('Y-m', $t), 'lib' => strftime_fr($t, 'M Y'),
            'encours' => date('Y-m', $t) === date('Y-m')];
    }

    $r = venteMois($m, $nomDe);
    $out['lignes'] = $r['lignes'];
    $out['sansVendeur'] = $r['sansVendeur'];
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
        'Prime réseau ' . $m . ' — ' . $montants['reseau'] . ' € (' . $g['reseau']['caHeure'] . ' €/h, ' . $g['reseau']['magasin'] . ')');
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
 * GET /ventes/classement.pdf?m=2026-07 — le rapport mensuel, à afficher en
 * réserve. Une page réseau, puis une page par magasin ; TOUTES les colonnes y
 * sont — heures, CA, CA/heure, panier, lignes/ticket, tickets, rang, prime.
 */
function ep_ventes_pdf(): array
{
    $d = ep_ventes_classement();
    if (($d['motif'] ?? null) !== null) { http_response_code(422); return ['error' => $d['motif']]; }
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => number_format((float) $v, 0, ',', ' ') . ' €';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $logo = rapLogoDataUri();
    $libMois = strftime_fr(strtotime($d['m'] . '-01'), 'M Y');
    $hist = $d['primeEnregistree'];

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .h1{font-size:19pt;margin:4mm 0 1mm}
      .mut{color:#7a736a}.acc{color:#8D1D2C}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .prime{border:1px solid #E8C9A0;background:#FFF9EC;border-radius:8px;padding:2.6mm 3.5mm;margin-bottom:4mm;font-size:8.6pt;line-height:1.6}
      table.t{width:100%;border-collapse:collapse;margin-bottom:5mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.4pt;text-align:right;padding:1.3mm 2mm;border-bottom:.5pt solid #EAE3D8}
      .t .l{text-align:left}
      .gris td{color:#9a9186}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6}
    </style>';

    $entete = static fn (string $droite) =>
        '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="" style="height:34px">' : '<b>L’Atelier by</b>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Target de vente &amp; classement<br>' . $droite . '</td></tr></table>';

    $tableau = static function (array $lignes, bool $avecMagasin) use ($e, $eur, $court, $hist): string {
        $h = '<table class="t" cellpadding="0" cellspacing="0"><tr><th class="l">#</th><th class="l">Vendeur·se</th>'
            . ($avecMagasin ? '<th class="l">Magasin</th>' : '')
            . '<th>Heures</th><th>CA</th><th>CA / h</th><th>Panier</th><th>Lignes / tkt</th><th>Tickets</th><th class="l">Prime</th></tr>';
        foreach ($lignes as $l) {
            $prime = '';
            if ($hist !== null) {
                if ((int) ($hist['reseau']['id'] ?? 0) === $l['id']) { $prime = 'réseau · ' . $hist['montants']['reseau'] . ' €'; }
                else { foreach ($hist['magasins'] ?? [] as $g) { if ((int) $g['id'] === $l['id']) { $prime = 'magasin · ' . $hist['montants']['magasin'] . ' €'; } } }
            }
            $h .= '<tr' . ($l['classable'] ? '' : ' class="gris"') . '>'
                . '<td class="l">' . ($l['rang'] !== null ? (int) $l['rang'] : '—') . '</td>'
                . '<td class="l"><b>' . $e($l['nom']) . '</b>'
                . ($l['classable'] ? '' : ' <span style="font-size:7pt;color:#9a9186">· ' . $e($l['motifHorsClassement']) . '</span>') . '</td>'
                . ($avecMagasin ? '<td class="l mut">' . $e($court($l['magasin'])) . '</td>' : '')
                . '<td>' . number_format((float) $l['heures'], 1, ',', ' ') . ' h</td>'
                . '<td>' . $eur($l['ca']) . '</td>'
                . '<td class="acc"><b>' . ($l['caHeure'] !== null ? $eur($l['caHeure']) : '—') . '</b></td>'
                . '<td>' . ($l['panier'] !== null ? number_format((float) $l['panier'], 2, ',', ' ') . ' €' : '—') . '</td>'
                . '<td>' . ($l['lignesTicket'] !== null ? number_format((float) $l['lignesTicket'], 1, ',', ' ') : '—') . '</td>'
                . '<td>' . number_format((float) $l['tickets'], 0, ',', ' ') . '</td>'
                . '<td class="l" style="font-size:7.4pt;color:#8a5a1c"><b>' . $e($prime) . '</b></td></tr>';
        }
        return $h . '</table>';
    };

    $g = $d['gagnantes'];
    $blocPrime = '';
    if (($g['reseau'] ?? null) !== null) {
        $noms = [];
        foreach ($g['magasins'] as $x) {
            if ($x['id'] === $g['reseau']['id']) { continue; }
            $noms[] = $e($x['nom']) . ' (' . $e($court($x['magasin'])) . ')';
        }
        $blocPrime = '<div class="prime">🏆 <b>Prime réseau — ' . $e($g['reseau']['nom'])
            . ' (' . $e($court($g['reseau']['magasin'])) . ') : ' . (int) $d['primes']['reseau'] . ' €</b> · '
            . $eur($g['reseau']['caHeure']) . ' / h'
            . ($noms === [] ? '' : ' &nbsp;|&nbsp; 🥇 Primes magasin (' . (int) $d['primes']['magasin'] . ' €) : ' . implode(' · ', $noms))
            . ($hist === null ? ' — <i>désignées par le calcul, à enregistrer dans le cockpit</i>' : ' — enregistrées le ' . $e($hist['quand'])) . '</div>';
    }

    $classables = array_values(array_filter($d['lignes'], static fn ($l) => $l['classable']));
    $h = $css . '<div class="doc">' . $entete($e($libMois))
        . '<div class="serif h1">Classement du réseau — ' . $e($libMois) . '</div>'
        . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">' . count($classables) . ' classé(e)s sur '
        . count($d['lignes']) . ' · classement au CA par heure prestée (planning du panel)</div>'
        . $blocPrime
        . $tableau($d['lignes'], true);

    foreach ($d['magasins'] as $mag) {
        $siens = array_values(array_filter($d['lignes'], static fn ($l) => $l['shopId'] === $mag['id']));
        if ($siens === []) { continue; }
        $h .= '<div style="page-break-before:always">' . $entete($e($court($mag['nom'])) . ' · ' . $e($libMois))
            . '<div class="serif h1">' . $e($court($mag['nom'])) . ' — l’équipe de vente</div>'
            . '<div class="mut" style="font-size:9pt;margin-bottom:4mm">Part du magasin dans chaque colonne : les mêmes mesures que la page réseau, resserrées sur l’équipe.</div>'
            . $tableau($siens, false) . '</div>';
    }

    $h .= '<div class="methode"><b style="color:#221E1A">Comment lire.</b> Classement au CA ÷ heures prestées — jamais au CA brut : '
        . 'une personne à 20 h ne se compare pas à une à 38 h. Sous ' . VENTE_SEUIL_HEURES . ' h au planning : montrée, jamais classée ni primée. '
        . 'Panier = CA ÷ tickets · cross-selling = lignes par ticket. '
        . ($d['partSansVendeur'] !== null && $d['partSansVendeur'] > 0
            ? $d['partSansVendeur'] . ' % du CA du mois est encaissé sans vendeur identifié sur le ticket : cette part n’est attribuée à personne. '
            : '')
        . 'La meilleure du réseau ne cumule pas la prime magasin.</div></div>';

    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Target de vente — ' . $e($libMois) . '</title></head><body>' . $h . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => 'Réseau', 'rapport' => 'Target de vente — ' . $libMois,
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="target-vente-' . $d['m'] . '.pdf"');
    echo $pdf;
    exit;
}
