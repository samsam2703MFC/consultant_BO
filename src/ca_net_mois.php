<?php
declare(strict_types=1);

/**
 * Le CA net d'un mois, magasin par magasin, envoyé par e-mail (22/10/2026, demandé par Sam : « les chiffres d'affaires
 * de septembre pour le franchisé, net pas brut »).
 *
 * Net = hors TVA, ticket par ticket : chaque ticket du panel porte son montant TTC après remise
 * (`total_gross_amount_after_discount`) et sa TVA (`tax_amount_after_discount`, documentation de l'API) ; le net est
 * leur différence. Tous les tickets de la caisse, clients pro compris. Une journée close se lit une fois
 * (`caNet{magasin}:{jour}`) ; un mois se lit par lots, vingt-cinq secondes par passage, l'écran relit tant que ça
 * avance. L'e-mail ne part qu'un mois entièrement lu, et seulement aux adresses que le cockpit connaît déjà
 * (destinataires des rapports, compte SMTP) — la même règle que les dossiers.
 */

const CN_BUDGET_S = 25;

/** La somme d'une liste de tickets : [brut TTC, TVA, tickets, tickets sans TVA lue]. */
function cnSomme(array $liste): array
{
    $b = 0.0; $t = 0.0; $n = 0; $sans = 0;
    foreach (analyseListe($liste) as $x) {
        if (!is_array($x)) { continue; }
        $n++;
        $b += (float) ($x['total_gross_amount_after_discount'] ?? $x['total_value_after_discount'] ?? 0);
        if (isset($x['tax_amount_after_discount']) && is_numeric($x['tax_amount_after_discount'])) { $t += (float) $x['tax_amount_after_discount']; } else { $sans++; }
    }
    return [round($b, 2), round($t, 2), $n, $sans];
}

/** Les magasins actifs : [id => nom]. */
function cnMagasins(): array
{
    $out = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name') as $s) { $out[(int) $s['id']] = (string) $s['name']; } }
    catch (Throwable $e) { /* sans table */ }
    return $out;
}

/**
 * Le mois $ym de chaque magasin : {magasins: [{shopId, nom, brut, tva, net, tickets, jours, joursLus, sansTva}], reseau,
 * aLire, complet}. Les journées manquantes se lisent au panel dans le budget de temps.
 */
function cnMois(string $ym, int $budget = CN_BUDGET_S): array
{
    $deb = $ym . '-01';
    $fin = min(date('Y-m-t', strtotime($deb)), date('Y-m-d', strtotime('-1 day')));
    $jours = [];
    for ($ts = strtotime($deb); $ts <= strtotime($fin); $ts += 86400) { $jours[] = date('Y-m-d', $ts); }
    $mags = cnMagasins();
    $lu = []; $manque = [];
    foreach ($mags as $sid => $nom) {
        foreach ($jours as $j) {
            $c = setting('caNet' . $sid . ':' . $j);
            // Gardée seulement lue un jour plus tard : la journée était close.
            if (is_array($c) && isset($c['b'], $c['q']) && date('Y-m-d', (int) $c['q']) > $j) { $lu[$sid][$j] = $c; } else { $manque[] = [$sid, $j]; }
        }
    }
    $finT = microtime(true) + $budget;
    if ($manque !== [] && class_exists('PanelApi') && PanelApi::configured()) {
        foreach (array_chunk($manque, 24) as $lot) {
            if (microtime(true) > $finT) { break; }
            $ch = [];
            foreach ($lot as [$sid, $j]) { $ch[$sid . '|' . $j] = '/shops/' . $sid . '/transactions?date=' . $j; }
            foreach (PanelApi::getParallele($ch, 6) as $k => $liste) {
                if (!is_array($liste)) { continue; }
                [$sid, $j] = explode('|', (string) $k, 2);
                [$b, $t, $n, $sans] = cnSomme($liste);
                $v = ['b' => $b, 't' => $t, 'n' => $n, 's' => $sans, 'q' => time()];
                $lu[(int) $sid][$j] = $v;
                try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['caNet' . $sid . ':' . $j, json_encode($v)]); }
                catch (Throwable $e) { /* relue la prochaine fois */ }
            }
        }
    }
    $out = []; $R = ['brut' => 0.0, 'tva' => 0.0, 'net' => 0.0, 'tickets' => 0]; $aLire = 0;
    foreach ($mags as $sid => $nom) {
        $L = $lu[$sid] ?? [];
        $b = array_sum(array_column($L, 'b')); $t = array_sum(array_column($L, 't'));
        $n = (int) array_sum(array_column($L, 'n')); $sans = (int) array_sum(array_column($L, 's'));
        $aLire += count($jours) - count($L);
        $out[] = ['shopId' => (string) $sid, 'nom' => $nom, 'brut' => round($b, 2), 'tva' => round($t, 2), 'net' => round($b - $t, 2),
            'tickets' => $n, 'jours' => count($jours), 'joursLus' => count($L), 'sansTva' => $sans];
        $R['brut'] += $b; $R['tva'] += $t; $R['net'] += $b - $t; $R['tickets'] += $n;
    }
    // Un magasin sans un seul ticket sur le mois (fermé, pas encore ouvert) ne s'affiche pas.
    $out = array_values(array_filter($out, static fn ($m) => $m['tickets'] > 0 || $m['joursLus'] < $m['jours']));
    foreach (['brut', 'tva', 'net'] as $k) { $R[$k] = round($R[$k], 2); }
    return ['mois' => $ym, 'du' => $deb, 'au' => $fin, 'magasins' => $out, 'reseau' => $R, 'aLire' => $aLire,
        'complet' => $aLire === 0, 'sansTva' => (int) array_sum(array_column($out, 'sansTva'))];
}

function cnLibMois(string $ym): string
{
    $M = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $M[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4);
}

function cnMoisDemande(): ?string
{
    $m = trim((string) ($_GET['mois'] ?? (function_exists('body') ? (body()['mois'] ?? '') : '')));
    if ($m === '') { $m = date('Y-m', strtotime('first day of last month')); }
    return preg_match('/^\d{4}-\d{2}$/', $m) && $m <= date('Y-m') ? $m : null;
}

/** GET /rapports/ca-net?mois=2026-09 — le CA net du mois par magasin, ce qui reste à lire, les adresses admises. */
function ep_rapports_ca_net(): array
{
    $m = cnMoisDemande();
    if ($m === null) { http_response_code(400); return ['error' => 'mois attendu (AAAA-MM), passé ou en cours']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return ['mois' => $m, 'indispo' => true, 'motif' => 'compte du panel non configuré']; }
    $d = cnMois($m);
    $mois = [];
    for ($i = 1; $i <= 12; $i++) { $k = date('Y-m', strtotime('first day of -' . $i . ' month')); $mois[] = ['v' => $k, 'nom' => cnLibMois($k)]; }
    return $d + ['libelle' => cnLibMois($m), 'moisDispo' => $mois,
        'adresses' => function_exists('dsrAdressesConnues') ? dsrAdressesConnues() : [], 'smtp' => Smtp::configured()];
}

/** L'e-mail : [sujet, html]. */
function cnMailHtml(array $d): array
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $eur = static fn (float $v) => number_format($v, 2, ',', ' ') . ' €';
    $res = setting('reseau', []);
    $marque = is_array($res) ? ($res['nom'] ?? 'L’Atelier by') : 'L’Atelier by';
    $logoUri = function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
    $logo = $logoUri !== '' ? '<img src="' . $logoUri . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
        : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>';
    $lib = cnLibMois($d['mois']);
    $th = static fn ($t, $a = 'right') => '<th align="' . $a . '" style="' . "font-family:'Segoe UI',Arial,sans-serif" . ';font-size:11px;letter-spacing:.6px;text-transform:uppercase;color:#8b8177;font-weight:700;padding:6px 6px;border-bottom:1px solid #EFE9DF">' . $t . '</th>';
    $td = static fn ($t, $a = 'right', $s = '') => '<td align="' . $a . '" style="' . "font-family:'Segoe UI',Arial,sans-serif" . ';font-size:13px;color:#221E1A;padding:7px 6px;border-bottom:1px solid #EFE9DF;white-space:nowrap;' . $s . '">' . $t . '</td>';
    // Trois colonnes, lisibles sur un téléphone : le TTC et la TVA en petit sous le nom du magasin.
    $nom = static fn (string $n, array $v) => $n . '<div style="font-size:11px;color:#8b8177;font-weight:400;white-space:normal">TTC ' . $eur($v['brut']) . ' · TVA ' . $eur($v['tva']) . '</div>';
    $lignes = '';
    foreach ($d['magasins'] as $m) {
        $lignes .= '<tr>' . $td($nom($e(cqMailVille($m['nom'])), $m), 'left', 'white-space:normal') . $td('<b>' . $eur($m['net']) . '</b>') . $td(number_format($m['tickets'], 0, ',', ' '), 'right', 'color:#5d554d') . '</tr>';
    }
    $R = $d['reseau'];
    $lignes .= '<tr>' . $td($nom('<b>Réseau</b>', $R), 'left', 'border-bottom:0;white-space:normal') . $td('<b>' . $eur($R['net']) . '</b>', 'right', 'border-bottom:0') . $td(number_format($R['tickets'], 0, ',', ' '), 'right', 'border-bottom:0;color:#5d554d') . '</tr>';
    $c = '<tr><td class="pad" style="' . $F . ';padding:20px 26px 6px">'
        . '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#8D1D2C;font-weight:700">Chiffre d’affaires net</div>'
        . '<div class="titre" style="font-size:19px;font-weight:700;color:#221E1A;margin-top:4px">' . $e(ucfirst($lib)) . ' · ' . $eur($R['net']) . ' hors TVA</div>'
        . '<div style="font-size:13px;color:#5d554d;margin-top:3px">' . count($d['magasins']) . ' magasin' . (count($d['magasins']) > 1 ? 's' : '') . ' · du ' . $e(date('d/m', strtotime($d['du']))) . ' au ' . $e(date('d/m/Y', strtotime($d['au']))) . '</div></td></tr>';
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:12px 26px 4px">Bonjour,<br><br>Voici le chiffre d’affaires net (hors TVA) de ' . $e($lib) . ', magasin par magasin.</td></tr>';
    $c .= '<tr><td class="pad" style="' . $F . ';padding:8px 20px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        . '<tr>' . $th('Magasin', 'left') . $th('CA net (HTVA)') . $th('Tickets') . '</tr>' . $lignes . '</table></td></tr>';
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:11px;line-height:1.5;color:#8b8177;padding:12px 26px 0">CA net = montant TTC après remise moins la TVA, ticket par ticket, tels que la caisse du panel les enregistre ; tous les tickets, clients pro compris.</td></tr>';
    $html = caMailSquelette('Chiffre d’affaires', 'message envoyé depuis le cockpit.');
    $html = str_replace(['{{logo}}', '{{marque}}', '{{contenu}}'], [$logo, $e($marque), $c], $html);
    return ['Chiffre d’affaires net de ' . $lib . ' — tous les magasins', $html];
}

/** POST /rapports/ca-net/envoyer {mois, a} — envoie le CA net du mois, entièrement lu, à une adresse connue du cockpit. */
function wr_rapports_ca_net_envoyer(): array
{
    $b = body();
    $_GET['mois'] = (string) ($b['mois'] ?? '');
    $m = cnMoisDemande();
    if ($m === null) { http_response_code(400); return ['error' => 'mois attendu (AAAA-MM)']; }
    $a = strtolower(trim((string) ($b['a'] ?? '')));
    $connues = function_exists('dsrAdressesConnues') ? dsrAdressesConnues() : [];
    if (!filter_var($a, FILTER_VALIDATE_EMAIL) || !in_array($a, $connues, true)) {
        http_response_code(422);
        return ['error' => 'adresse inconnue du cockpit : choisissez un destinataire des rapports (Reporting) ou le compte SMTP'];
    }
    if (!Smtp::configured()) { http_response_code(503); return ['error' => 'SMTP non configuré (Paramètres)']; }
    $d = cnMois($m);
    if (!$d['complet']) { http_response_code(409); return ['error' => 'le mois n’est pas encore entièrement lu', 'aLire' => $d['aLire']]; }
    if ($d['sansTva'] > 0) { http_response_code(409); return ['error' => $d['sansTva'] . ' ticket(s) sans TVA lue au panel : le net ne se calcule pas'];}
    [$sujet, $html] = cnMailHtml($d);
    if (!Smtp::envoyer($a, $sujet, $html)) { http_response_code(502); return ['error' => 'envoi refusé : ' . (Smtp::$lastError ?? 'SMTP')]; }
    if (function_exists('journalAdd')) { journalAdd('CEO', 'Rapport', 'CA net ' . $m, 'Envoyé à ' . $a . ' — réseau ' . number_format($d['reseau']['net'], 2, ',', ' ') . ' € HTVA, ' . count($d['magasins']) . ' magasin(s)'); }
    return ['ok' => true, 'a' => $a, 'sujet' => $sujet, 'reseau' => $d['reseau']];
}
