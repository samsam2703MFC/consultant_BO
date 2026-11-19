<?php
declare(strict_types=1);

/**
 * Le dashboard magasin › Les chiffres › Frais (16/11/2026, maquette docs/maquettes/frais-tva) : les frais du mois tels
 * que le panel les rend, et pour chaque poste « TVA comprise ? » et le taux. Le calcul (lecture des frais, choix, part
 * hors TVA, P&L hors TVA) est dans src/pnl_ht.php, partagé avec le cockpit du siège ; ici, les deux routes de l'onglet.
 *
 *   GET  /exploitation/frais?shop=4[&mois=2026-11]         l'onglet Frais : les frais du mois au panel, le choix de chaque poste
 *   POST /exploitation/frais/tva {shop, poste, tva, taux}   le choix d'un poste ; `tva` : true, false, ou null (à indiquer)
 *
 * Table : `pil_frais_tva` (ensureFraisTva, src/pnl_ht.php). Le panel n'est pas modifié.
 */

/* --- les routes ------------------------------------------------------------------------------------------------------- */

/** Le mois demandé (AAAA-MM, pas après le mois en cours), sinon le mois en cours. */
function phMoisDemande(mixed $v): string
{
    $m = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) && $m <= date('Y-m') ? $m : date('Y-m');
}

/** GET /exploitation/frais?shop=4[&mois=AAAA-MM][&rafraichir=1] — l'onglet Frais du dashboard. */
function ep_exploitation_frais(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'magasin attendu (shop)']; }
    $ym = phMoisDemande($_GET['mois'] ?? null);
    $base = ['shop' => (string) $shop, 'mois' => $ym, 'taux' => PH_TAUX, 'tauxOui' => PH_TAUX_OUI,
        'source' => 'le panel : frais du magasin du mois (/shops/{id}/owner/costs?period=' . $ym . ')'];
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return $base + ['indispo' => true, 'motif' => 'compte du panel non configuré']; }
    $P = phFraisPanel([$shop], $ym, !empty($_GET['rafraichir']))[$shop] ?? ['lu' => false, 'le' => null, 'lignes' => []];
    $F = phFraisMois($P['lignes'], phChoix([$shop])[$shop] ?? []);
    // Ce que ça change dans le P&L du jour : les frais que le panel alloue à aujourd'hui (daily-summary.shop_cost).
    $jour = null;
    if ($ym === date('Y-m') && $F['part'] !== null) {
        $ds = PanelApi::get('/shops/' . $shop . '/statistics/daily-summary?date=' . date('Y-m-d'));
        $oh = is_array($ds) ? nombreOuNull($ds, ['shop_cost']) : null;
        if ($oh !== null && $oh > 0) { $jour = ['date' => date('Y-m-d'), 'saisi' => round($oh, 2), 'ht' => round($oh * $F['part'], 2)]; }
    }
    return $base + ['lu' => $P['lu'], 'luLe' => $P['le'] ? date('c', (int) $P['le']) : null, 'jour' => $jour] + $F;
}

/** POST /exploitation/frais/tva {shop, poste, tva: true|false|null, taux} — le choix d'un poste, gardé d'un mois à l'autre. */
function wr_exploitation_frais_tva(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $lib = trim((string) ($b['poste'] ?? ''));
    if ($shop <= 0 || $lib === '') { http_response_code(400); return ['error' => 'magasin et poste attendus']; }
    $tva = $b['tva'] ?? null;
    if ($tva !== null && !is_bool($tva)) { http_response_code(422); return ['error' => 'tva : true, false ou null']; }
    $taux = $b['taux'] ?? null;
    if ($taux !== null && (!is_numeric($taux) || (float) $taux < 0 || (float) $taux > 30)) { http_response_code(422); return ['error' => 'taux : entre 0 et 30 %']; }
    $taux = $tva === null ? null : ($taux !== null ? round((float) $taux, 2) : ($tva ? PH_TAUX_OUI : 0.0));
    $cle = phCle($lib);
    $qui = function_exists('accesIdentite') ? accesIdentite() : null;
    ensureFraisTva();
    Db::exec('DELETE FROM pil_frais_tva WHERE shop_id = ? AND poste = ?', [(string) $shop, $cle]);
    Db::exec('INSERT INTO pil_frais_tva (shop_id, poste, libelle, tva_comprise, taux, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [(string) $shop, $cle, mb_substr($lib, 0, 190), $tva === null ? null : ($tva ? 1 : 0), $taux, date('Y-m-d H:i:s'),
            is_array($qui) && isset($qui['nom']) ? mb_substr((string) $qui['nom'], 0, 120) : null]);
    phPerimerResultat();
    return ['ok' => true, 'poste' => $cle, 'tva' => $tva, 'taux' => $taux];
}

/**
 * Après un choix : le Résultat gardé d'aujourd'hui (jour, semaine, mois) part périmé — il reste servi et se refait en
 * arrière-plan, avec les nouveaux frais hors TVA.
 */
function phPerimerResultat(): void
{
    $auj = date('Y-m-d');
    foreach (['exJour:' . $auj, 'exPer:semaine:' . $auj, 'exPer:mois:' . $auj] as $cle) {
        try {
            $c = setting($cle);
            if (!is_array($c) || !isset($c['le'])) { continue; }
            $c['le'] = time() - (int) ($c['ttl'] ?? 0) - 1;
            Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($c, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* il expirera de lui-même */ }
    }
}
