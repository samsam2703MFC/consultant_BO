<?php
declare(strict_types=1);

/*
 * SONDE — les cartes de fidélité de l'ERP (13/11/2026, Sam : « utilise /loyalty/customers et /loyalty/statistics pour
 * savoir quel client a quelle carte, et où on en est au niveau général avec les cartes »). Lecture seule, rien de
 * nominatif : ni nom, ni prénom, ni identifiant de client — des comptes, des répartitions, les noms des cartes.
 *   0. les cartes définies (/admin/loyalty/cards) : nom, timbres ou points, timbres requis, récompense, portée, active ;
 *   1. par magasin (2, 3, 4, 5), ce mois et depuis le 1er janvier : /loyalty/statistics (participants, cartes,
 *      reconnaissances, activité) ;
 *   2. par magasin, /loyalty/customers (toutes les pages) : combien de clients par carte, progression des timbres,
 *      récompenses prêtes, soldes de points, actifs dans le magasin sur la période.
 * Lancée par .github/workflows/sonde-cartes-fidelite.yml : php -- /var/www/consulant_bo < tools/sonde_cartes_fidelite.php
 */
$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'erp_cles.php', 'endpoints.php', 'panel_api.php', 'erp_api.php'] as $f) { require_once "$dir/src/$f"; }
$corps = static fn (array $r) => is_array($r['corps'] ?? null) ? $r['corps'] : [];
$erreur = static fn (array $r) => 'HTTP ' . (int) ($r['code'] ?? 0) . ' ' . mb_substr(is_string($r['corps'] ?? null) ? $r['corps'] : (string) json_encode($r['corps'] ?? null, JSON_UNESCAPED_UNICODE), 0, 300);

// Deuxième passage (13/11/2026) : les permissions sont inscrites dans le jeton à sa création ; le cockpit garde le sien
// 30 minutes. Reconnexion forcée, puis le contenu du jeton : le type de compte et les permissions LOYALTY seulement —
// jamais le jeton lui-même.
// Quatrième passage (13/11/2026, Sam : « tu as le compte admin dans consultant ») : lancée avec compte=admin, la sonde
// passe par le compte admin ERP du cockpit (src/erp_api.php, /admin/auth/login, identifiants côté serveur).
$admin = ($argv[3] ?? '') === 'admin';
$get = static fn (string $p, int $t = 30): array => $admin ? ErpApi::sondeGet($p) : PanelApi::sondeGet($p, $t);
echo '=== -1. Le jeton du cockpit (', $admin ? 'compte ADMIN ERP' : 'compte consultant', "), renouvelé — son contenu, pas le jeton\n";
if ($admin) { echo '  compte admin ERP configuré : ', ErpApi::configured() ? 'oui' : 'NON', "\n"; }
else { Db::exec("DELETE FROM ceo_app_setting WHERE `key` = 'panelApiToken'"); }
$jt = $admin ? ErpApi::jetonSonde(true) : PanelApi::jetonSonde(true);
if ($jt === null && $admin) { echo '  connexion admin : ', (string) ErpApi::$lastError, "\n"; }
if ($jt === null) { echo "  pas de jeton\n"; }
else {
    $pl = json_decode((string) base64_decode(strtr(explode('.', $jt . '..')[1], '-_', '+/')), true);
    $pl = is_array($pl) ? $pl : [];
    $perms = (array) ($pl['perms'] ?? $pl['permissions'] ?? []);
    $plat = [];
    array_walk_recursive($perms, static function ($v, $k) use (&$plat) { $plat[] = is_string($k) ? $k . ':' . (is_scalar($v) ? $v : '') : (string) $v; });
    echo '  champs du jeton : ', implode(', ', array_keys($pl)), "\n";
    // Troisième passage (13/11/2026, Sam : « affiche le nom de la permission du jeton ») : l'application, la portée et les
    // permissions telles quelles — des noms de droits, pas des secrets ; ni le jeton, ni les noms de la personne.
    foreach (['app', 'scope_type', 'scope_id'] as $k) { if (isset($pl[$k])) { echo "  $k = ", json_encode($pl[$k], JSON_UNESCAPED_UNICODE), "\n"; } }
    echo '  perms (tel quel) = ', json_encode($pl['perms'] ?? $pl['permissions'] ?? null, JSON_UNESCAPED_UNICODE), "\n";
    foreach (['actor_type', 'type', 'role', 'roles', 'scope', 'scopes', 'access', 'aud'] as $k) { if (isset($pl[$k])) { echo "  $k = ", json_encode($pl[$k], JSON_UNESCAPED_UNICODE), "\n"; } }
    echo '  permissions : ', count($plat), ' en tout · LOYALTY : ', json_encode(array_values(array_filter($plat, static fn ($x) => stripos($x, 'loyal') !== false)), JSON_UNESCAPED_UNICODE), "\n";
}
echo "=== 0. Les cartes définies (/admin/loyalty/cards)\n";
$r = $get('/admin/loyalty/cards', 30);
if ((int) ($r['code'] ?? 0) !== 200) { echo '  ', $erreur($r), "\n"; }
foreach ((array) ($corps($r)['items'] ?? $corps($r)['data'] ?? []) as $c) {
    if (!is_array($c)) { continue; }
    $pc = $c['points_config'] ?? null;
    printf("  carte %-4s %-28s active=%s portée=%s magasins=%s timbres=%s récompense « %s » points=%s du %s au %s\n", $c['id'] ?? '?', '« ' . ($c['name'] ?? '') . ' »',
        $c['active'] ?? '?', $c['scope'] ?? '?', json_encode($c['shop_ids'] ?? []), $c['stamps_required'] ?? '—', $c['reward_label'] ?? '', is_array($pc) ? json_encode($pc, JSON_UNESCAPED_UNICODE) : 'non',
        $c['starts_at'] ?? '?', $c['ends_at'] ?? '—');
}

$periodes = ['ce mois' => [date('Y-m-01'), date('Y-m-d')], 'depuis janvier' => [date('Y-01-01'), date('Y-m-d')]];
foreach ([2, 3, 4, 5] as $sid) {
    echo "\n=== 1. Magasin $sid — /loyalty/statistics\n";
    foreach ($periodes as $lib => [$du, $au]) {
        $r = $get('/shops/' . $sid . '/loyalty/statistics?' . http_build_query(['from' => $du, 'to' => $au]), 30);
        if ((int) ($r['code'] ?? 0) !== 200) { echo "  $lib : ", $erreur($r), "\n"; continue; }
        $s = $corps($r);
        echo "  $lib ($du → $au) · participants ", json_encode($s['participants'] ?? null), "\n";
        echo '    reconnaissances ', json_encode(array_diff_key((array) ($s['recognitions'] ?? []), ['by_device' => 1])), ' · appareils ', count((array) ($s['recognitions']['by_device'] ?? [])), "\n";
        echo '    activité du magasin ', json_encode($s['shop_activity'] ?? null, JSON_UNESCAPED_UNICODE), "\n";
        foreach ((array) ($s['programs'] ?? []) as $p) { echo '    carte ', json_encode($p, JSON_UNESCAPED_UNICODE), "\n"; }
    }
    echo "=== 2. Magasin $sid — /loyalty/customers (ce mois), sans nom\n";
    $page = 1; $tot = null; $n = 0;
    $parCarte = []; $actifs = 0; $bloques = 0; $avecAchat = 0; $multi = 0;
    do {
        $r = $get('/shops/' . $sid . '/loyalty/customers?' . http_build_query(['from' => date('Y-m-01'), 'to' => date('Y-m-d'), 'page' => $page, 'limit' => 100]), 40);
        if ((int) ($r['code'] ?? 0) !== 200) { echo '  ', $erreur($r), "\n"; break; }
        $c = $corps($r);
        $tot = (int) ($c['pagination']['total'] ?? 0);
        foreach ((array) ($c['items'] ?? []) as $cl) {
            $n++;
            if (!empty($cl['active'])) { $actifs++; }
            if (!empty($cl['blocked'])) { $bloques++; }
            if ((int) ($cl['shop_activity']['transactions'] ?? 0) > 0) { $avecAchat++; }
            $P = (array) ($cl['programs'] ?? []);
            if (count($P) > 1) { $multi++; }
            foreach ($P as $p) {
                $k = ($p['id'] ?? '?') . ' « ' . ($p['name'] ?? '') . ' »';
                $x = &$parCarte[$k];
                $x ??= ['clients' => 0, 'timbres' => [], 'pretes' => 0, 'points' => [], 'actifsCarte' => 0];
                $x['clients']++;
                $st = $p['global_progress']['stamps'] ?? null;
                if (is_array($st)) { $x['timbres'][(int) $st['progress'] . '/' . (int) $st['required']] = ($x['timbres'][(int) $st['progress'] . '/' . (int) $st['required']] ?? 0) + 1; $x['pretes'] += (int) $st['ready_rewards']; }
                $pt = $p['global_progress']['points'] ?? null;
                if (is_array($pt)) { $x['points'][] = (int) $pt['balance']; }
                if ((int) ($p['shop_activity']['stamps_earned'] ?? 0) + (int) ($p['shop_activity']['points_earned'] ?? 0) > 0) { $x['actifsCarte']++; }
                unset($x);
            }
        }
        $page++;
    } while ($tot !== null && $n < $tot && $page <= 40);
    if ($tot !== null) {
        echo "  clients lus $n sur $tot · actifs $actifs · bloqués $bloques · avec un achat ce mois dans le magasin $avecAchat · sur plusieurs cartes $multi\n";
        if ($n > 0 && isset($cl)) { echo '  (champs d\'un client : ', implode(', ', array_keys(array_diff_key($cl, ['name' => 1, 'surname' => 1, 'id' => 1]))), " ; name/surname/id masqués)\n"; }
        foreach ($parCarte as $k => $x) {
            ksort($x['timbres'], SORT_NATURAL);
            $pts = $x['points']; sort($pts);
            printf("  carte %s : %d clients · timbres (avancement → clients) %s · récompenses prêtes %d · points %s · ont gagné ce mois %d\n", $k, $x['clients'], json_encode($x['timbres']), $x['pretes'],
                $pts ? count($pts) . ' comptes, médiane ' . $pts[intdiv(count($pts), 2)] . ', max ' . end($pts) : '—', $x['actifsCarte']);
        }
    }
}
// 3. Un client donné au lancement (entrée `client`, chiffres seulement) : ses cartes et son activité à Halle, sans nom.
$cid = ctype_digit((string) ($argv[2] ?? '')) ? (int) $argv[2] : 0;
if ($cid > 0) {
    echo "\n=== 3. Le client $cid à Halle — ses cartes (/loyalty/customers), sans nom\n";
    $page = 1; $trouve = false;
    do {
        $r = $get('/shops/4/loyalty/customers?' . http_build_query(['from' => date('Y-01-01'), 'to' => date('Y-m-d'), 'page' => $page, 'limit' => 100]), 40);
        if ((int) ($r['code'] ?? 0) !== 200) { echo '  ', $erreur($r), "\n"; break; }
        $c = $corps($r);
        foreach ((array) ($c['items'] ?? []) as $cl) {
            if ((int) ($cl['id'] ?? 0) !== $cid) { continue; }
            $trouve = true;
            unset($cl['name'], $cl['surname']);
            echo '  ', json_encode($cl, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        }
        $page++;
    } while (!$trouve && ($page - 1) * 100 < (int) ($c['pagination']['total'] ?? 0) && $page <= 40);
    if (!$trouve) { echo "  pas de compte de carte pour ce client à Halle\n"; }
}
echo "\nfin de la sonde\n";
