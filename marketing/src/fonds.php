<?php
declare(strict_types=1);

/**
 * Le fonds marketing, lu au module Marketing (21/11/2026).
 *
 * Le grand livre, ses leviers, ses frais récurrents, les magasins et les campagnes qu'on peut y imputer : tout vient de
 * l'API du module (marketingAppel, shared/src/endpoints.php : jeton de service, identité « marque »). Le cockpit ne lit
 * plus ses tables (mar_fund_movement, mar_fund_recurrence, mar_shop, mar_campaign, mar_lever) et ne les écrit plus
 * (shared/src/writes.php, depuis la même date). Les redevances, elles, se calculent toujours ici
 * (fondsRoyaltiesCalcul, shared/src/endpoints.php : la fiche boutique et les ventes du panel), rapprochées des
 * écritures ROYALTY que le module rend.
 *
 * Sorti de shared/src/endpoints.php : la réponse de GET /fonds garde ses clés et la forme de ses lignes.
 */

/** Les clés d'une ligne du grand livre, telles que l'écran les lit (le module en rend d'autres : période, visibilité…). */
const FONDS_LIGNE_CLES = ['id', 'movement_date', 'direction', 'label', 'amount', 'source', 'shop_id', 'shop_name',
    'campaign_id', 'campaign_name', 'lever_id', 'lever_label', 'lever_color_hex', 'supplier_name', 'document_ref',
    'is_investment', 'recurrence_id'];

/**
 * Le grand livre du module (GET /funds/ledger, par mois) : [ledger, null], ou [null, raison] s'il ne se lit pas.
 *
 * @return array{0: ?array, 1: ?string}
 */
function fondsLivreModule(): array
{
    $r = marketingAppel('GET', '/funds/ledger');
    if ($r['erreur'] !== null) { return [null, $r['erreur']]; }
    if (!is_array($r['corps']) || !is_array($r['corps']['periods'] ?? null)) { return [null, 'réponse du module illisible']; }
    return [$r['corps'], null];
}

/** Les lignes d'un grand livre, à plat (entrées puis sorties de chaque période). */
function fondsLignes(array $ledger): array
{
    $out = [];
    foreach ((array) ($ledger['periods'] ?? []) as $p) {
        foreach ([...(array) ($p['entries'] ?? []), ...(array) ($p['exits'] ?? [])] as $l) {
            if (is_array($l)) { $out[] = $l; }
        }
    }
    return $out;
}

/**
 * Une écriture du grand livre, par son identifiant : ['ligne' => ?array, 'erreur' => ?string]. `ligne` null et
 * `erreur` null : elle n'existe pas.
 */
function fondsLigne(int $id): array
{
    [$ledger, $err] = fondsLivreModule();
    if ($ledger === null) { return ['ligne' => null, 'erreur' => $err]; }
    foreach (fondsLignes($ledger) as $l) {
        if ((int) ($l['id'] ?? 0) === $id) { return ['ligne' => $l, 'erreur' => null]; }
    }
    return ['ligne' => null, 'erreur' => null];
}

/** Les frais récurrents du module (GET /funds/recurrences) : [liste, null], ou [null, raison]. */
function fondsRecurrencesModule(): array
{
    $r = marketingAppel('GET', '/funds/recurrences');
    if ($r['erreur'] !== null) { return [null, $r['erreur']]; }
    return [is_array($r['corps']) && array_is_list($r['corps']) ? $r['corps'] : [], null];
}

/** Les boutiques du module (GET /shops), rangées par nom : [liste, null], ou [null, raison]. */
function fondsBoutiquesModule(): array
{
    $r = marketingAppel('GET', '/shops');
    if ($r['erreur'] !== null) { return [null, $r['erreur']]; }
    $l = is_array($r['corps']) && array_is_list($r['corps']) ? $r['corps'] : [];
    // Rangées par nom comme MySQL le faisait (ORDER BY name, interclassement insensible aux accents) : « Écaussinnes »
    // avant « Gosselies ».
    $cle = static function (array $b): string {
        $n = (string) ($b['name'] ?? '');
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n);
        return strtolower($t === false ? $n : $t);
    };
    usort($l, static fn ($a, $b) => strcmp($cle($a), $cle($b)));
    return [$l, null];
}

/** Une ligne du grand livre dans la forme de l'écran : ses clés seulement, typées. */
function fondsLigneEcran(array $l): array
{
    $out = [];
    foreach (FONDS_LIGNE_CLES as $k) { $out[$k] = $l[$k] ?? null; }
    $out['id'] = (int) $out['id'];
    $out['amount'] = (float) $out['amount'];
    foreach (['shop_id', 'campaign_id', 'lever_id', 'recurrence_id'] as $k) { $out[$k] = $out[$k] !== null ? (int) $out[$k] : null; }
    $out['is_investment'] = !empty($out['is_investment']);
    return $out;
}

/**
 * GET /fonds — le fonds marketing et les redevances.
 *
 * La donnée n'est PAS recopiée : elle est lue au module marketing, qui tient le grand livre. Dupliquer un grand livre
 * donnerait deux soldes pour le même fonds, et c'est celui qui a tort qu'on regarderait. Le relais se fait côté serveur :
 * le module vit sous un autre chemin, et un appel depuis le navigateur y serait une requête d'origine différente.
 */
function ep_fonds(): array
{
    $out = ['base' => 'API du module Marketing', 'ledger' => null, 'leviers' => [],
        'royalties' => null, 'recurrences' => [], 'magasins' => [], 'campagnes' => [],
        'fournisseurs' => [], 'erreurs' => [], 'manque' => [], 'source' => null];
    [$ledger, $err] = fondsLivreModule();
    if ($ledger === null) {
        $out['erreurs'][] = 'le grand livre ne se lit pas au module Marketing : ' . $err;
        return $out;
    }
    $mvts = fondsLignes($ledger);

    // Le référentiel des fournisseurs est celui de la centrale d'achat du cockpit (con_supplier).
    $out['fournisseurs'] = array_map(fn ($f) => ['id' => (string) $f['id'], 'nom' => (string) $f['name']],
        Db::rows('SELECT id, name FROM con_supplier ORDER BY name'));

    // Le grand livre, période par période (mois civils), avec les soldes — tels que le module les compte.
    $periodes = [];
    foreach ((array) $ledger['periods'] as $p) {
        $periodes[] = [
            'period_key' => (string) ($p['period_key'] ?? ''),
            'entries' => array_map('fondsLigneEcran', array_values(array_filter((array) ($p['entries'] ?? []), 'is_array'))),
            'exits' => array_map('fondsLigneEcran', array_values(array_filter((array) ($p['exits'] ?? []), 'is_array'))),
            'entries_total' => (float) ($p['entries_total'] ?? 0), 'exits_total' => (float) ($p['exits_total'] ?? 0),
            'opening_balance' => (float) ($p['opening_balance'] ?? 0), 'closing_balance' => (float) ($p['closing_balance'] ?? 0),
        ];
    }
    $out['ledger'] = ['granularity' => 'month', 'periods' => $periodes, 'closing_balance' => (float) ($ledger['closing_balance'] ?? 0)];

    // Les leviers actifs, dans l'ordre du réseau (références du module), avec la dépense qui leur est imputée
    // (synthèse du module). Le ROI reste absent de cet écran, comme avant : pas inventé.
    $ref = marketingAppel('GET', '/references');
    $perf = marketingAppel('GET', '/funds/levers');
    if ($ref['erreur'] !== null || $perf['erreur'] !== null) {
        $out['erreurs'][] = 'leviers illisibles au module Marketing : ' . ($ref['erreur'] ?? $perf['erreur']);
    } else {
        $depense = [];
        foreach (is_array($perf['corps']) ? $perf['corps'] : [] as $l) {
            if (is_array($l)) { $depense[(int) ($l['lever_id'] ?? 0)] = (float) ($l['spent_amount'] ?? 0); }
        }
        foreach ((array) ($ref['corps']['levers'] ?? []) as $l) {
            if (!is_array($l)) { continue; }
            $out['leviers'][] = ['lever_id' => (int) $l['id'], 'lever_code' => $l['code'] ?? null, 'lever_label' => $l['label'] ?? null,
                'color_hex' => $l['color_hex'] ?? null, 'spent_amount' => $depense[(int) $l['id']] ?? 0.0, 'roi_value' => null];
        }
    }

    [$recs, $errR] = fondsRecurrencesModule();
    if ($recs === null) { $out['erreurs'][] = 'frais récurrents illisibles au module Marketing : ' . $errR; }
    foreach ($recs ?? [] as $r) {
        $out['recurrences'][] = ['id' => (int) $r['id'], 'direction' => $r['direction'], 'frequency' => $r['frequency'],
            'label' => $r['label'], 'amount' => (float) $r['amount'], 'starts_on' => $r['starts_on'], 'ends_on' => $r['ends_on'],
            'shop_name' => $r['shop_name'] ?? null];
    }

    [$boutiques, $errB] = fondsBoutiquesModule();
    if ($boutiques === null) { $out['erreurs'][] = 'boutiques illisibles au module Marketing : ' . $errB; }
    foreach ($boutiques ?? [] as $s2) {
        $out['magasins'][] = ['id' => (int) $s2['id'], 'name' => (string) $s2['name'], 'city' => $s2['city'] ?? ''];
    }

    $camp = marketingAppel('GET', '/campaigns');
    if ($camp['erreur'] !== null) { $out['erreurs'][] = 'campagnes illisibles au module Marketing : ' . $camp['erreur']; }
    foreach (is_array($camp['corps']) && array_is_list($camp['corps']) ? $camp['corps'] : [] as $c2) {
        if (is_array($c2)) { $out['campagnes'][] = ['id' => (int) $c2['id'], 'name' => (string) $c2['name']]; }
    }

    // Les redevances : le calcul partagé fondsRoyaltiesCalcul() — grille des taux depuis la FICHE BOUTIQUE du panel
    // (royalty_*_percentage), chiffre d'affaires du mois choisi depuis l'API de ventes (borné au jour même quand ce mois
    // est en cours). Les écritures ROYALTY déjà passées au fonds pour ce mois sont rappelées en face du dû théorique.
    // Factures et règlements émis relèvent du realm ADMIN (compte « admin ERP »).
    $royFait = false;
    // ?mois=AAAA-MM : la période s'arrête à l'écran, l'API recalcule CA, taux et dû pour ce mois-là. Un mois invalide
    // ou futur retombe sur le courant — un CA qui n'existe pas encore ne se facture pas.
    $moisCle = (string) ($_GET['mois'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $moisCle) || $moisCle > date('Y-m')) { $moisCle = date('Y-m'); }
    $calc = fondsRoyaltiesCalcul($moisCle);
    if ($calc !== null) {
        // Écritures ROYALTY du mois déjà au fonds, par magasin.
        $ecrit = [];
        foreach ($mvts as $m) {
            if (($m['source'] ?? '') === 'ROYALTY' && ($m['direction'] ?? '') === 'IN' && ($m['shop_id'] ?? null) !== null
                && (str_starts_with((string) ($m['movement_date'] ?? ''), $moisCle)
                    || str_starts_with((string) ($m['period_from'] ?? ''), $moisCle))) {
                $ecrit[(int) $m['shop_id']][] = ['amount' => (float) $m['amount'], 'label' => $m['label']];
            }
        }
        $shops = [];
        foreach ($calc['shops'] as $s) {
            $rates = []; $dues = []; $duTotal = 0.0; $aDu = false; $duMkt = null;
            foreach ($s['sortes'] as $k) {
                $rates[] = ['code' => $k['cle'], 'label' => $k['label'], 'rate_pct' => round($k['taux'] * 100, 2)];
                if ($k['du'] !== null) {
                    $dues[] = ['code' => $k['code'], 'label' => $k['label'],
                        'rate_pct' => round($k['taux'] * 100, 2), 'amount' => $k['du'],
                        // Seule la sorte Marketing part au fonds : l'écran le dit ligne à ligne, sinon le « Dû » total
                        // laisse croire que tout alimente la caisse commune.
                        'au_fonds' => $k['code'] === 'MARKETING'];
                    $duTotal += $k['du']; $aDu = true;
                    if ($k['code'] === 'MARKETING') { $duMkt = $k['du']; }
                }
            }
            $shops[] = [
                'shop_id' => $s['shop_id'], 'shop_name' => $s['shop_name'], 'city' => $s['city'],
                'revenue_amount' => $s['ca'], 'rates' => $rates,
                'royalties_enabled' => $s['enabled'],
                'billing_frequency' => $s['billing_frequency'],
                // Le dû du mois TOUTES sortes confondues ; le détail sorte par sorte suit dans `dues`. Au fonds ne part
                // que la sorte Marketing — `due_marketing` la donne seule.
                'due_theorique' => $aDu ? round($duTotal, 2) : null,
                'due_marketing' => $duMkt !== null ? round($duMkt, 2) : null,
                'dues' => $dues,
                'movements' => $ecrit[$s['shop_id']] ?? [],
            ];
        }
        $roy = ['month' => $moisCle, 'shops' => $shops,
            'source' => 'API panel — fiche boutique (taux) et ventes du mois (CA, jour même)',
            'erp' => ['available' => true]];
        if (ErpApi::disponible()) {
            // Réponses enveloppées ({invoices: […]}, {settlements: […]}).
            $fac = ErpApi::get('/admin/royalties/invoices');
            $reg = ErpApi::get('/admin/royalties/settlements');
            if (is_array($fac)) { $roy['factures'] = array_slice((array) ($fac['invoices'] ?? (analyseListe($fac) ?: [])), 0, 24); }
            if (is_array($reg)) { $roy['reglements'] = array_slice((array) ($reg['settlements'] ?? (analyseListe($reg) ?: [])), 0, 24); }
        } else {
            $roy['facturesNote'] = 'Factures et règlements émis : renseignez le compte admin ERP (Mon compte) pour les lire.';
        }
        $out['royalties'] = $roy;
        $royFait = true;
    }
    if (!$royFait) {
        $out['manque'][] = [
            'champ' => 'Redevances par magasin',
            'quoi' => 'la grille des taux, les chiffres d’affaires du mois et l’écriture des redevances au fonds',
            'source' => 'La grille et le CA se lisent sur l’API du panel : configurez le compte consultant '
                . '(Mon compte) pour les afficher ici. ' . (PanelApi::$lastError ? 'Dernière erreur : ' . PanelApi::$lastError : ''),
        ];
    }
    $out['source'] = 'API du module Marketing (grand livre, leviers, frais récurrents, boutiques, campagnes)';
    return $out;
}
