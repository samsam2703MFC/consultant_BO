<?php
declare(strict_types=1);

/*
 * SONDE — campagnes de bons de l'API admin TFBuddy (24/10/2026, demande de Sam : « fais-le via sonde pour être sûr,
 * et ensuite crée ta documentation »).
 *
 * Question : le webshop peut-il créer ses bons par l'API ERP (`/admin/voucher-campaigns`, `/admin/voucher-codes`)
 * au lieu d'écrire en SQL dans `voucher_campaign` / `voucher_code` ? La spécification publique
 * (atelierby.tfbuddy.com/api/documentation/api.php) donne les corps ; cette sonde dit ce que l'API rend VRAIMENT
 * avec le compte admin du cockpit, et si ce compte a le droit d'écrire.
 *
 * Lancée sur le serveur du cockpit par `.github/workflows/sonde-vouchers.yml` :
 *     php -- /var/www/consulant_bo < tools/sonde_vouchers.php
 *
 * AUCUNE ÉCRITURE ABOUTIE. Trois essais d'écriture seulement, construits pour être refusés avant tout
 * enregistrement : création sans `name` (422 attendu), génération de codes et changement de statut sur des
 * identifiants qui n'existent pas (404 attendu). Un 403 dit que le compte n'a pas le droit. Si, contre toute
 * attente, la création sans nom réussissait, la campagne est aussitôt passée INACTIVE et son numéro affiché.
 *
 * Rien de nominatif : les codes sont masqués (3 premiers caractères), les clients ne sont que comptés.
 */

$dir = rtrim((string) ($argv[1] ?? '/var/www/consulant_bo'), '/');
foreach (['Db.php', 'endpoints.php', 'installer.php', 'writes.php', 'erp_api.php'] as $f) {
    if (!is_file("$dir/src/$f")) { fwrite(STDERR, "fichier absent : $dir/src/$f\n"); exit(2); }
    require "$dir/src/$f";
}

/** Un code de bon : ses trois premiers caractères et sa longueur. */
function svMasque(string $s): string
{
    return mb_strlen($s) <= 3 ? '***' : mb_substr($s, 0, 3) . '…(' . mb_strlen($s) . ')';
}

/** Une valeur affichable : les codes masqués, les e-mails et téléphones retirés, le reste tronqué. */
function svValeur(string $cle, $v)
{
    if (is_string($v)) {
        if (preg_match('/(^|_)code$|shared_code|token/i', $cle)) { return svMasque($v); }
        if (preg_match('/mail|phone|tel/i', $cle)) { return '<masqué>'; }
        // Les noms de campagne du webshop portent le code (« Webshop — BIENVENUE10 ») : on le masque aussi.
        if ($cle === 'name') { $v = preg_replace_callback('/\b[A-Z0-9][A-Z0-9-]{5,}\b/', static fn ($m) => svMasque($m[0]), $v); }
        return mb_substr($v, 0, 80);
    }
    return $v;
}

/** Les champs d'un objet, aplatis (« promotion.status ») : [chemin => [type, exemple]]. */
function svAplatir($x, string $pre = '', int $prof = 0): array
{
    $out = [];
    if (!is_array($x) || $prof > 3) { return $out; }
    if (array_is_list($x)) {
        $out[rtrim($pre, '.') . '[]'] = ['liste(' . count($x) . ')', null];
        if (isset($x[0]) && is_array($x[0])) { $out += svAplatir($x[0], rtrim($pre, '.') . '[].', $prof + 1); }
        return $out;
    }
    foreach ($x as $k => $v) {
        $ch = $pre . $k;
        if (is_array($v) && $v !== []) { $out += svAplatir($v, $ch . '.', $prof + 1); continue; }
        $t = is_array($v) ? 'liste(0)' : (is_null($v) ? 'null' : gettype($v));
        $out[$ch] = [$t, is_scalar($v) ? svValeur((string) $k, $v) : null];
    }
    return $out;
}

/** Les éléments d'une réponse paginée {items, pagination} (ou `data.items`, ou une liste nue). */
function svItems($corps): array
{
    if (!is_array($corps)) { return []; }
    foreach ([$corps['items'] ?? null, $corps['data']['items'] ?? null, $corps['data'] ?? null, $corps] as $c) {
        if (is_array($c) && array_is_list($c)) { return $c; }
    }
    return [];
}

function svPagination($corps): ?array
{
    if (!is_array($corps)) { return null; }
    $p = $corps['pagination'] ?? ($corps['data']['pagination'] ?? null);
    return is_array($p) ? $p : null;
}

/** Une fiche : l'objet lui-même, qu'il vienne seul ou dans `data`. */
function svFiche($corps): ?array
{
    if (!is_array($corps)) { return null; }
    return is_array($corps['data'] ?? null) && !array_is_list($corps['data']) ? $corps['data'] : $corps;
}

function svTitre(string $t): void { echo "\n=== $t ===\n"; }

function svChamps(array $objets): void
{
    $champs = [];
    foreach ($objets as $o) {
        foreach (svAplatir($o) as $ch => [$t, $ex]) {
            $c = $champs[$ch] ?? ['types' => [], 'n' => 0, 'ex' => null];
            $c['types'][$t] = true; $c['n']++;
            if ($c['ex'] === null && $ex !== null && $ex !== '') { $c['ex'] = $ex; }
            $champs[$ch] = $c;
        }
    }
    ksort($champs);
    foreach ($champs as $ch => $c) {
        printf("  %-46s %-22s %3d/%d  %s\n", $ch, implode('|', array_keys($c['types'])), $c['n'], count($objets),
            $c['ex'] === null ? '' : 'ex: ' . json_encode($c['ex'], JSON_UNESCAPED_UNICODE));
    }
}

function svRepartition(array $items, string $cle): string
{
    $r = [];
    foreach ($items as $it) { $v = $it[$cle] ?? null; $k = $v === null ? 'null' : (is_scalar($v) ? (string) $v : 'objet'); $r[$k] = ($r[$k] ?? 0) + 1; }
    arsort($r);
    return json_encode($r, JSON_UNESCAPED_UNICODE);
}

function svErreur(array $r): string
{
    $c = $r['corps'];
    $d = is_array($c) ? ($c['description'] ?? $c['message'] ?? $c['code'] ?? '') : '';
    $e = is_array($c) && isset($c['errors']) ? ' errors=' . mb_substr((string) json_encode($c['errors'], JSON_UNESCAPED_UNICODE), 0, 300) : '';
    return 'HTTP ' . $r['code'] . ($d !== '' ? ' · ' . (is_scalar($d) ? $d : json_encode($d)) : '') . $e;
}

// ── 00b. Le disque du serveur (lecture seule) ────────────────────────────────
// 11:20 : « The table '/tmp/#sql…' is full » et cinq écritures sans rapport bloquées en RUNNING depuis 7 à 20 min —
// la signature d'un disque plein (MySQL attend qu'on libère de la place). Où est la place ? Rien n'est effacé ici.
svTitre('disque du serveur (lecture seule)');
$sh = static function (string $cmd): string { return trim((string) shell_exec('timeout 90 sh -c ' . escapeshellarg($cmd) . ' 2>&1')); };
echo preg_replace('/^/m', '  ', $sh('df -hP /')), "\n";
echo preg_replace('/^/m', '  ', $sh('df -iP /')), "\n";
// 11:24 : datadir /var/lib/mysql, tmpdir /tmp, log_bin ON (binlog.*, 100 Mo chacun), gardés 30 jours — tout sur /.
// SHOW BINARY LOGS a calé 8 min (11:24, disque plein) : les journaux binaires se mesurent sur le disque.
echo '  journaux binaires : ', $sh("ls /var/lib/mysql/binlog.[0-9]* 2>/dev/null | wc -l | tr -d ' '"), ' fichier(s), ',
    $sh("du -ch /var/lib/mysql/binlog.[0-9]* 2>/dev/null | tail -1 | cut -f1"), ', le plus ancien ',
    $sh("ls -l --time-style=+%Y-%m-%d /var/lib/mysql/binlog.[0-9]* 2>/dev/null | head -1 | awk '{ print \$6, \$7 }'"), "\n";
echo '  journal systemd : ', $sh('journalctl --disk-usage'), "\n";
echo '  cache apt : ', $sh('du -sh /var/cache/apt | cut -f1'), "\n";
echo "  /var/log, les plus gros :\n";
echo preg_replace('/^/m', '    ', $sh('du -ah /var/log 2>/dev/null | sort -rh | head -12')), "\n";
echo "  les plus gros dossiers (même disque) :\n";
echo preg_replace('/^/m', '    ', $sh('du -xh -d 2 / 2>/dev/null | sort -rh | head -25')), "\n";
echo "  les plus gros fichiers (> 200 Mo, même disque) :\n";
echo preg_replace('/^/m', '    ', $sh("find / -xdev -type f -size +200M -printf '%s\\t%TY-%Tm-%Td\\t%p\\n' 2>/dev/null | sort -rn | head -25 | awk -F'\\t' '{ printf \"%7.1f Go  %s  %s\\n\", \$1/1e9, \$2, \$3 }'")), "\n";
echo "  /tmp, ce qui pèse :\n";
echo preg_replace('/^/m', '    ', $sh('du -xsh /tmp/* /tmp/.[!.]* 2>/dev/null | sort -rh | head -15')), "\n";
$plein = (int) trim((string) shell_exec("df -P / /tmp | awk 'NR > 1 { gsub(\"%\", \"\", \$5); if (\$5 > m) m = \$5 } END { print m + 0 }'"));
if ($plein >= 98 || ($bloquees ?? 0) > 0) {
    printf("\n  ⇒ disque à %d %% (/ ou /tmp), %d écriture(s) bloquée(s) depuis plus de 5 min : la base n'écrit plus.\n"
        . "    Sonde arrêtée ici, sans ajouter d'écriture bloquée (le jeton ERP s'enregistre dans la base).\n", $plein, $bloquees ?? 0);
    exit(2);
}

// ── 00. Les verrous de la base du cockpit (lecture seule) ──────────────────────
// 24/10/2026 11:03 et 11:14 : le jeton ERP ne s'enregistre plus (INSERT ceo_app_setting → « Lock wait timeout
// exceeded »). Qui tient le verrou ? Processus, transactions ouvertes, verrous de métadonnées — rien n'est tué ici.
svTitre('verrous de la base du cockpit (lecture seule)');
// Une requête relue peut porter le jeton ERP qu'elle enregistre : il ne s'imprime pas.
function svSansJeton(string $q): string { return preg_replace('/(erpAdminToken\'\s*,\s*\').*/s', '$1…', $q); }
try {
    foreach (Db::rows("SELECT ID, USER, COMMAND, TIME, STATE, LEFT(COALESCE(INFO, ''), 160) AS INFO FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' OR TIME > 300 ORDER BY TIME DESC LIMIT 25") as $r) {
        printf("  proc #%-8s %-12s %-8s %6ss  %-28s %s\n", $r['ID'], $r['USER'], $r['COMMAND'], $r['TIME'], mb_substr((string) $r['STATE'], 0, 28), svSansJeton(preg_replace('/\s+/', ' ', (string) $r['INFO'])));
    }
} catch (Throwable $e) {
    echo '  processus illisibles : ', mb_substr($e->getMessage(), 0, 160), "\n";
    // information_schema passe par une table temporaire ; SHOW PROCESSLIST, non (11:20 : « /tmp/#sql… is full »).
    try {
        foreach (Db::rows('SHOW FULL PROCESSLIST') as $r) {
            if (($r['Command'] ?? '') === 'Sleep' && (int) ($r['Time'] ?? 0) <= 300) { continue; }
            printf("  proc #%-8s %-12s %-8s %6ss  %-28s %s\n", $r['Id'], $r['User'], $r['Command'], $r['Time'], mb_substr((string) $r['State'], 0, 28), svSansJeton(mb_substr(preg_replace('/\s+/', ' ', (string) $r['Info']), 0, 160)));
        }
    } catch (Throwable $e2) { echo '  SHOW PROCESSLIST illisible : ', mb_substr($e2->getMessage(), 0, 160), "\n"; }
}
try {
    foreach (Db::rows("SELECT trx_id, trx_state, trx_started, TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age, trx_mysql_thread_id, trx_rows_locked, trx_tables_locked, LEFT(COALESCE(trx_query, ''), 160) AS q FROM information_schema.INNODB_TRX ORDER BY trx_started LIMIT 20") as $r) {
        if ((int) $r['age'] > 300 && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $r['q'])) { $bloquees = ($bloquees ?? 0) + 1; }
        printf("  trx %s %-10s depuis %s (%ss) · thread %s · %s lignes / %s tables verrouillées · %s\n", $r['trx_id'], $r['trx_state'], $r['trx_started'], $r['age'], $r['trx_mysql_thread_id'], $r['trx_rows_locked'], $r['trx_tables_locked'], svSansJeton(preg_replace('/\s+/', ' ', (string) $r['q'])));
    }
} catch (Throwable $e) { echo '  transactions illisibles : ', mb_substr($e->getMessage(), 0, 160), "\n"; }
try {
    foreach (Db::rows("SELECT OBJECT_NAME, LOCK_TYPE, LOCK_STATUS, OWNER_THREAD_ID FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = 'ceo_app_setting'") as $r) {
        printf("  verrou de métadonnées %s %s %s (thread %s)\n", $r['OBJECT_NAME'], $r['LOCK_TYPE'], $r['LOCK_STATUS'], $r['OWNER_THREAD_ID']);
    }
} catch (Throwable $e) { echo '  verrous de métadonnées illisibles : ', mb_substr($e->getMessage(), 0, 160), "\n"; }

// ── 0. Le compte ─────────────────────────────────────────────────────────────
svTitre('compte admin ERP du cockpit');
$st = ErpApi::statut();
printf("  base=%s  configuré=%s  mot de passe=%s  jeton en cache valide=%s  téléphone=%s\n", $st['base'],
    $st['configure'] ? 'oui' : 'non', $st['motDePasseDefini'] ? 'défini' : 'absent', $st['jetonValide'] ? 'oui' : 'non',
    $st['phone'] !== '' ? '…' . substr((string) $st['phone'], -2) : '—');
if (!ErpApi::disponible()) { echo "  ⇒ aucun identifiant ni jeton : sonde impossible\n"; exit(1); }
foreach (['sondeGet', 'ecrire'] as $m) {
    if (!method_exists('ErpApi', $m)) { echo "  ⇒ ErpApi::$m absent sur ce serveur (cockpit trop ancien) : sonde impossible\n"; exit(1); }
}

// ── 1. La liste des campagnes ────────────────────────────────────────────────
svTitre('GET /admin/voucher-campaigns (toutes les pages, 100 par page)');
$campagnes = []; $page = 1; $pg = null; $l = null;
do {
    $l = ErpApi::sondeGet('/admin/voucher-campaigns?limit=100&page=' . $page);
    if ($l['code'] < 200 || $l['code'] >= 300) { echo '  page ' . $page . ' : ' . svErreur($l) . "\n"; break; }
    $campagnes = array_merge($campagnes, svItems($l['corps']));
    $pg = svPagination($l['corps']);
    $page++;
} while ($pg !== null && $page <= (int) ($pg['pages'] ?? 1) && $page <= 20);
printf("  HTTP %d · %d campagne(s) lue(s) · pagination=%s\n", $l['code'] ?? 0, count($campagnes), json_encode($pg));
if ($campagnes !== []) {
    echo '  status : ', svRepartition($campagnes, 'status'), "\n";
    echo '  code_type : ', svRepartition($campagnes, 'code_type'), "\n";
    echo '  planned_promotion_type : ', svRepartition($campagnes, 'planned_promotion_type'), "\n";
    foreach (['id_shop', 'target_kind', 'reason_kind', 'id_brand', 'channel', 'channels'] as $k) {
        $n = count(array_filter($campagnes, static fn ($c) => is_array($c) && array_key_exists($k, $c)));
        printf("  champ « %s » présent sur %d/%d\n", $k, $n, count($campagnes));
    }
    $ws = array_values(array_filter($campagnes, static fn ($c) => is_array($c) && preg_match('/^(Webshop|Boutique \d+) — /u', (string) ($c['name'] ?? ''))));
    printf("  campagnes créées par le webshop (nom « Webshop — … » / « Boutique N — … ») : %d\n", count($ws));
    echo "  champs d'une ligne de liste :\n";
    svChamps(array_slice($campagnes, 0, 40));
}

// ── 2. Quelques fiches : détail, codes, promotions rattachables ──────────────
$choix = [];
$ajoute = static function (array $c) use (&$choix): void { $id = (int) ($c['id'] ?? 0); if ($id > 0 && count($choix) < 6) { $choix[$id] = $c; } };
foreach (array_slice($ws ?? [], 0, 2) as $c) { $ajoute($c); }
foreach ($campagnes as $c) { if (($c['code_type'] ?? '') === 'UNIQUE') { $ajoute($c); break; } }
foreach ($campagnes as $c) { if (!empty($c['id_promotion'])) { $ajoute($c); break; } }
foreach ($campagnes as $c) { $ajoute($c); }

$fiches = []; $codes = []; $promos = [];
foreach ($choix as $id => $c) {
    svTitre("campagne #$id (" . svValeur('name', (string) ($c['name'] ?? '')) . ')');
    $f = ErpApi::sondeGet("/admin/voucher-campaigns/$id");
    echo '  GET /{id} : ', $f['code'] >= 200 && $f['code'] < 300 ? 'HTTP ' . $f['code'] : svErreur($f), "\n";
    if (($fi = svFiche($f['corps'])) !== null && $f['code'] < 300) {
        $fiches[] = $fi;
        printf("    configuration_complete=%s · missing_requirements=%s · id_promotion=%s · promotion=%s\n",
            json_encode($fi['configuration_complete'] ?? null), json_encode($fi['missing_requirements'] ?? null, JSON_UNESCAPED_UNICODE),
            json_encode($fi['campaign']['id_promotion'] ?? null), json_encode(is_array($fi['promotion'] ?? null) ? array_keys($fi['promotion']) : ($fi['promotion'] ?? null)));
    }
    $k = ErpApi::sondeGet("/admin/voucher-campaigns/$id/codes?limit=5");
    $ki = svItems($k['corps']);
    echo '  GET /{id}/codes : ', $k['code'] < 300 ? 'HTTP ' . $k['code'] . ' · total=' . json_encode(svPagination($k['corps'])['total'] ?? count($ki)) : svErreur($k), "\n";
    foreach ($ki as $x) {
        if (is_array($x)) {
            $codes[] = $x;
            printf("    code %s · status=%s · usage=%s/%s · client=%s · valid_to=%s\n", svMasque((string) ($x['code'] ?? '')), $x['status'] ?? '?',
                json_encode($x['usage_count'] ?? null), json_encode($x['usage_limit'] ?? null), empty($x['id_customer']) ? 'non' : 'oui', json_encode($x['valid_to'] ?? null));
        }
    }
    $a = ErpApi::sondeGet("/admin/voucher-campaigns/$id/available-promotions?limit=10");
    $ai = svItems($a['corps']);
    echo '  GET /{id}/available-promotions : ', $a['code'] < 300 ? 'HTTP ' . $a['code'] . ' · total=' . json_encode(svPagination($a['corps'])['total'] ?? count($ai)) : svErreur($a), "\n";
    if ($ai !== []) {
        echo '    promotion_type : ', svRepartition($ai, 'promotion_type'), ' · activation_mode : ', svRepartition($ai, 'activation_mode'), "\n";
        $promos = array_merge($promos, $ai);
    }
}
if ($fiches !== []) { svTitre('champs d\'une fiche campagne (GET /admin/voucher-campaigns/{id})'); svChamps($fiches); }
if ($codes !== []) { svTitre('champs d\'un code (GET /admin/voucher-campaigns/{id}/codes)'); svChamps($codes); }
if ($promos !== []) { svTitre('champs d\'une promotion rattachable (GET …/available-promotions)'); svChamps(array_slice($promos, 0, 20)); }

// ── 3. Le droit d'écrire, sans rien enregistrer ──────────────────────────────
svTitre('droit d\'écriture (essais construits pour être refusés)');
$e1 = ErpApi::ecrire('POST', '/admin/voucher-campaigns', ['description' => null]);
echo '  POST /admin/voucher-campaigns sans name (422 attendu) : ', svErreur($e1), "\n";
if ($e1['code'] >= 200 && $e1['code'] < 300) {
    $nid = (int) (svFiche($e1['corps'])['id'] ?? 0);
    echo "  ⚠ CRÉÉE malgré l'absence de nom : campagne #$nid — passage INACTIVE…\n";
    if ($nid > 0) { echo '    PATCH status INACTIVE : ', svErreur(ErpApi::ecrire('PATCH', "/admin/voucher-campaigns/$nid/status", ['status' => 'INACTIVE'])), "\n"; }
}
$e2 = ErpApi::ecrire('POST', '/admin/voucher-campaigns/999999999/codes/generate', ['count' => 1]);
echo '  POST /admin/voucher-campaigns/999999999/codes/generate (404 attendu) : ', svErreur($e2), "\n";
$e3 = ErpApi::ecrire('PATCH', '/admin/voucher-codes/999999999/status', ['status' => 'DISABLED']);
echo '  PATCH /admin/voucher-codes/999999999/status (404 attendu) : ', svErreur($e3), "\n";
$droit = in_array(403, [$e1['code'], $e2['code'], $e3['code']], true) ? 'NON (403 : permission MENU.BUNDLE.MANAGE manquante)'
       : (in_array($e1['code'], [400, 422], true) ? 'OUI (validation atteinte : le compte passe la garde)' : 'indéterminé');
echo "  ⇒ le compte peut-il écrire ? $droit\n";

// ── 4. La base que lit le cockpit : les colonnes ajoutées par le webshop ─────
svTitre('base du cockpit : colonnes des tables de bons (information_schema, lecture seule)');
try {
    $db = Db::rows('SELECT DATABASE() AS db')[0]['db'] ?? '?';
    echo "  base : $db\n";
    foreach (['promotion', 'voucher_campaign', 'voucher_code', 'voucher_campaign_channel', 'voucher_campaign_target', 'voucher_redemption', 'promotion_order_discount'] as $t) {
        $cols = array_map(static fn ($c) => (string) $c['COLUMN_NAME'],
            Db::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]));
        echo '  ', str_pad($t, 26), $cols === [] ? 'absente' : implode(', ', $cols), "\n";
    }
    $canaux = Db::rows('SELECT channel, COUNT(*) n FROM voucher_campaign_channel GROUP BY channel ORDER BY n DESC');
    echo '  campagnes par canal : ', json_encode($canaux, JSON_UNESCAPED_UNICODE), "\n";
} catch (Throwable $e) {
    echo '  lecture impossible : ', mb_substr($e->getMessage(), 0, 200), "\n";
}

// ── 5. L'API et la base parlent-elles des mêmes campagnes ? ──────────────────
// Le 24/10 au premier passage : 8 campagnes à l'API, aucune « Webshop — … », alors que la base du cockpit en a au
// canal WS ; et l'API rend un `office_id` sur les codes, colonne absente de `voucher_code` ici. On croise.
svTitre('croisement API ↔ base : mêmes campagnes ?');
try {
    $cfgDb = Db::config()['db'] ?? [];
    $hn = Db::rows('SELECT @@hostname AS h, DATABASE() AS d')[0] ?? [];
    printf("  base du cockpit : hôte configuré=%s · @@hostname=%s · base=%s\n", $cfgDb['host'] ?? '?', $hn['h'] ?? '?', $hn['d'] ?? '?');
    // La base du webshop (hôte et nom seulement — jamais l'utilisateur ni le mot de passe).
    foreach (['/var/www/html/api/config.php', '/var/www/html/webshop/api/config.php'] as $wc) {
        if (!is_file($wc)) { continue; }
        $w = (static function (string $f) { return include $f; })($wc);
        printf("  base du webshop (%s) : hôte=%s · base=%s\n", $wc, $w['db']['host'] ?? '?', $w['db']['name'] ?? '?');
    }
    $tv = array_map(static fn ($r) => (string) $r['TABLE_NAME'], Db::rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%voucher%' ORDER BY TABLE_NAME"));
    echo '  tables « voucher » de la base : ', implode(', ', $tv), "\n";
    $lignes = Db::rows("SELECT vc.id, vc.name, vc.status, vc.code_type, vc.id_shop, vc.target_kind, vc.created_by, vc.created_at,
                               (SELECT GROUP_CONCAT(ch.channel) FROM voucher_campaign_channel ch WHERE ch.id_voucher_campaign = vc.id) AS canaux,
                               p.promotion_type, p.activation_mode,
                               (SELECT COUNT(*) FROM voucher_code k WHERE k.id_voucher_campaign = vc.id) AS nb_codes
                          FROM voucher_campaign vc LEFT JOIN promotion p ON p.id = vc.id_promotion
                         ORDER BY vc.id LIMIT 80");
    printf("  %d campagne(s) en base :\n", count($lignes));
    $enBase = [];
    foreach ($lignes as $r) {
        $enBase[(int) $r['id']] = (string) $r['name'];
        printf("    #%-4d %-44s %-8s %-6s shop=%-4s cible=%-8s canaux=%-6s promo=%s/%s codes=%d par=%s le %s\n", (int) $r['id'],
            mb_substr((string) svValeur('name', (string) $r['name']), 0, 44), $r['status'], $r['code_type'], $r['id_shop'] ?? '—', $r['target_kind'] ?? '—',
            $r['canaux'] ?? '—', $r['promotion_type'] ?? '—', $r['activation_mode'] ?? '—', (int) $r['nb_codes'], $r['created_by'] ?? '—', substr((string) $r['created_at'], 0, 10));
    }
    $aApi = [];
    foreach ($campagnes as $c) { if (is_array($c) && isset($c['id'])) { $aApi[(int) $c['id']] = (string) ($c['name'] ?? ''); } }
    $communs = array_intersect_key($aApi, $enBase);
    $memeNom = count(array_filter(array_keys($communs), static fn ($id) => $aApi[$id] === $enBase[$id]));
    printf("  identifiants : API=%s · base=%s\n", json_encode(array_keys($aApi)), json_encode(array_keys($enBase)));
    printf("  communs=%d (même nom : %d) · à l'API seulement=%d · en base seulement=%d\n", count($communs), $memeNom,
        count(array_diff_key($aApi, $enBase)), count(array_diff_key($enBase, $aApi)));
    // Une campagne de la base absente de la liste : l'API la connaît-elle par son numéro ?
    foreach (array_slice(array_keys(array_diff_key($enBase, $aApi)), 0, 4) as $id) {
        $g = ErpApi::sondeGet("/admin/voucher-campaigns/$id");
        $gn = svFiche($g['corps'])['campaign']['name'] ?? null;
        printf("    GET /admin/voucher-campaigns/%d (en base seulement) : %s%s\n", $id, $g['code'] < 300 ? 'HTTP ' . $g['code'] : svErreur($g),
            $gn !== null ? ' · nom API=' . svValeur('name', (string) $gn) . ($gn === $enBase[$id] ? ' (identique à la base)' : ' (DIFFÉRENT de la base)') : '');
    }
    $verdict = $communs === [] ? 'NON — aucune campagne commune : l\'API ERP lit une autre base que le cockpit'
             : ($memeNom === count($communs) && count($communs) === count($aApi) && count($communs) === count($enBase) ? 'OUI — mêmes campagnes, mêmes noms'
             : 'EN PARTIE — des numéros communs mais des noms ou des listes qui diffèrent');
    echo "  ⇒ l'API et la base du cockpit voient-elles les mêmes campagnes ? $verdict\n";
} catch (Throwable $e) {
    echo '  croisement impossible : ', mb_substr($e->getMessage(), 0, 200), "\n";
}

// ── 6. Le passage en caisse côté ERP : valider, encaisser, annuler un bon ────
// « Rien en local » (Sam, 24/10) : le webshop devra valider et encaisser ses bons À L'ERP, pas dans sa base. La
// spécification publique liste /vouchers/validate, /redeem et /reverse sans en décrire le contrat : on envoie des
// corps VIDES, que l'API ne peut que refuser, pour lire les champs qu'elle exige. Aucun code n'est envoyé : rien ne
// peut être validé ni encaissé.
svTitre('routes de caisse /vouchers/* (corps vides : refus attendu, rien d\'encaissé)');
foreach (['/vouchers/validate', '/vouchers/redeem', '/vouchers/reverse'] as $ch) {
    $r = ErpApi::ecrire('POST', $ch, ['_sonde' => true]);
    echo '  POST ', str_pad($ch, 20), ' compte admin : ', svErreur($r), "\n";
    if ($r['code'] >= 200 && $r['code'] < 300) { echo '    ⚠ accepté — corps : ', mb_substr((string) json_encode(svAplatir($r['corps']), JSON_UNESCAPED_UNICODE), 0, 400), "\n"; }
}
$pc = ErpApi::sondeGet('/promo-codes?limit=5');
echo '  GET /promo-codes : ', $pc['code'] < 300 ? 'HTTP ' . $pc['code'] . ' · ' . count(svItems($pc['corps'])) . ' élément(s)' : svErreur($pc), "\n";
if ($pc['code'] < 300 && svItems($pc['corps']) !== []) { svChamps(array_slice(svItems($pc['corps']), 0, 5)); }

// ── 7. Le contrat de caisse, champ après champ ───────────────────────────────
// Troisième passage : validate rend {valid, validation_token, campaign, promotion, errors[]} ; redeem et reverse
// exigent d'abord un request_key. On ajoute un champ à la fois pour lire l'exigence suivante. Le code envoyé
// N'EXISTE PAS (ZZSONDE…) et le jeton est faux : rien ne peut être validé, encaissé ni annulé.
svTitre('contrat de caisse : exigence suivante, un champ à la fois (code inexistant, jeton faux)');
$cle = 'SONDE-COCKPIT-' . gmdate('YmdHis');
$essais = [
    ['/vouchers/validate', ['code' => 'ZZSONDE0000']],
    ['/vouchers/validate', ['code' => 'ZZSONDE0000', 'id_shop' => 2, 'channel' => 'WS', 'subtotal' => 10, 'items' => []]],
    ['/vouchers/redeem',   ['request_key' => $cle]],
    ['/vouchers/redeem',   ['request_key' => $cle, 'validation_token' => 'jeton-sonde-faux']],
    ['/vouchers/redeem',   ['request_key' => $cle, 'code' => 'ZZSONDE0000']],
    ['/vouchers/reverse',  ['request_key' => $cle]],
    ['/vouchers/reverse',  ['request_key' => $cle, 'id_redemption' => 999999999]],
];
foreach ($essais as [$ch, $corps]) {
    $r = ErpApi::ecrire('POST', $ch, $corps);
    printf("  POST %-18s %-70s → %s\n", $ch, json_encode(array_keys($corps)), svErreur($r));
    $c = $r['corps'];
    if (is_array($c) && isset($c['errors']) && is_array($c['errors']) && array_is_list($c['errors'])) {
        foreach ($c['errors'] as $e) { if (is_array($e)) { echo '      · ', $e['code'] ?? '?', ' — ', mb_substr((string) ($e['message'] ?? ''), 0, 160), "\n"; } }
    }
    if ($r['code'] >= 200 && $r['code'] < 300 && is_array($c)) { echo '      clés : ', implode(', ', array_keys($c)), "\n"; }
}

// ── 8. Les campagnes, une par une, sur chaque hôte de l'ERP ──────────────────
// 24/10 après l'essai, Sam : « je vois rien dans la création de bon ». Où est la campagne d'essai #9 ? On liste
// les campagnes de l'hôte du cockpit ET de l'hôte de test (même adresse IP, peut-être une autre base), avec les
// mêmes identifiants admin (jamais affichés).
svTitre('campagnes par hôte (nom, statut, type, promotion)');
$cfgErp = ErpApi::config();
$hotes = array_values(array_unique([$cfgErp['base'], 'https://atelierby.tfbuddy.com/api/v1', 'https://test.tfbuddy.com/api/v1']));
foreach ($hotes as $base) {
    echo "  --- $base\n";
    $ch = curl_init($base . '/admin/auth/login');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['phone' => $cfgErp['phone'], 'password' => $cfgErp['password']])]);
    $lr = json_decode((string) curl_exec($ch), true); $lc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $tok = is_array($lr) ? ($lr['access_token'] ?? $lr['data']['access_token'] ?? $lr['token'] ?? null) : null;
    if (!is_string($tok) || $tok === '') { echo "    connexion admin : HTTP $lc — refusée\n"; continue; }
    $ch = curl_init($base . '/admin/voucher-campaigns?limit=100');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $tok]]);
    $cr = json_decode((string) curl_exec($ch), true); $cc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $its = svItems($cr);
    printf("    GET /admin/voucher-campaigns : HTTP %d · %d campagne(s)\n", $cc, count($its));
    foreach ($its as $c) {
        if (!is_array($c)) { continue; }
        printf("    #%-4s %-62s %-8s %-6s promo=%-5s complète=%s\n", $c['id'] ?? '?', mb_substr((string) svValeur('name', (string) ($c['name'] ?? '')), 0, 62),
            $c['status'] ?? '?', $c['code_type'] ?? '?', json_encode($c['id_promotion'] ?? null), json_encode($c['configuration_complete'] ?? null));
    }
}

// ── 8b. Pour l'assistant « bon » du dashboard magasin (24/10/2026) ─────────────
// Les clients du magasin (le ciblage d'un bon) et ce que la fiche d'une promotion « X + Y » dit de son mode
// d'activation et de sa campagne. Lecture seule ; rien de nominatif n'est affiché (clés et comptes seulement).
svTitre('clients du magasin et fiche « X + Y » (pour le bon du dashboard)');
foreach ([4, 2] as $sh) {
    $c = ErpApi::sondeGet("/shops/$sh/clients?limit=5");
    $it = svItems($c['corps']);
    printf("  GET /shops/%d/clients?limit=5 : %s · %d ligne(s) · clés : %s\n", $sh, $c['code'] < 300 ? 'HTTP ' . $c['code'] : svErreur($c), count($it),
        $it !== [] && is_array($it[0]) ? implode(', ', array_keys($it[0])) : '—');
    if ($it !== []) {
        printf("    b2b : %s · avec société : %d · avec e-mail : %d · avec téléphone : %d\n", svRepartition($it, 'is_b2b'),
            count(array_filter($it, static fn ($x) => !empty($x['company_name']))), count(array_filter($it, static fn ($x) => !empty($x['email']))),
            count(array_filter($it, static fn ($x) => !empty($x['phone']))));
    }
    $c2 = ErpApi::sondeGet("/shops/$sh/clients?limit=500&offset=0");
    printf("    limit=500 : %s · %d ligne(s)\n", $c2['code'] < 300 ? 'HTTP ' . $c2['code'] : svErreur($c2), count(svItems($c2['corps'])));
}
$bx = ErpApi::sondeGet('/admin/promotions/buy-x-get-y?limit=5');
$bxi = svItems($bx['corps']);
echo '  GET /admin/promotions/buy-x-get-y?limit=5 : ', $bx['code'] < 300 ? 'HTTP ' . $bx['code'] : svErreur($bx), ' · ', count($bxi), " ligne(s)\n";
if ($bxi !== [] && isset($bxi[0]['id'])) {
    $fx = ErpApi::sondeGet('/admin/promotions/buy-x-get-y/' . (int) $bxi[0]['id']);
    $fxc = svFiche($fx['corps']) ?? [];
    echo '    fiche : activation_mode=', json_encode($fxc['activation_mode'] ?? null), ' · voucher_campaign_id=', json_encode(array_key_exists('voucher_campaign_id', $fxc) ? $fxc['voucher_campaign_id'] : 'absent'),
         ' · voucher_campaign=', json_encode(array_key_exists('voucher_campaign', $fxc) ? (is_array($fxc['voucher_campaign']) ? array_keys($fxc['voucher_campaign']) : $fxc['voucher_campaign']) : 'absent'), "\n";
}

// ── 9. Ce que montre le PANEL (l'écran de Sam), en lecture ───────────────────
// Sam regarde le panel de production et « ne voit rien dans la création de bon ». On s'y connecte avec le compte
// admin du cockpit (formulaire `login` / `password` de /panel/), on relève les liens du menu qui parlent de bons
// ou de promotions, puis on lit ces pages : titre, lignes de tableau (codes masqués), et si la campagne d'essai
// y figure. Uniquement des GET après la connexion.
svTitre('panel de production : les écrans de bons');
$panel = 'https://atelierby.tfbuddy.com/panel/';
$jar = tempnam(sys_get_temp_dir(), 'svp');
$get = static function (string $url, ?array $post = null) use ($jar): array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 25,
          CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Accept: text/html']];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $o);
    $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $u = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$c, $b, $u];
};
$texte = static fn (string $h): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
try {
    $connecte = false;
    foreach ([$cfgErp['phone'], ltrim($cfgErp['phone'], '+')] as $login) {
        [$c, $b, $u] = $get($panel, ['login' => $login, 'password' => $cfgErp['password']]);
        $connecte = !str_contains($b, 'name="password"');
        printf("  connexion au panel (identifiant %s) : HTTP %d → %s · %s\n", $login === $cfgErp['phone'] ? 'tel' : 'sans +', $c, preg_replace('#^https?://[^/]+#', '', $u), $connecte ? 'connecté' : 'refusée');
        if ($connecte) { break; }
    }
    if ($connecte) {
        [$c, $accueil] = $get($panel);
        preg_match_all('#<a\b[^>]*href="([^"#][^"]*)"[^>]*>(.*?)</a>#si', $accueil, $mm, PREG_SET_ORDER);
        $liens = [];
        foreach ($mm as $m) {
            $t = $texte($m[2]); $h = $m[1];
            // Jamais un lien d'action (suppression, déconnexion…) : on ne suit que des pages à lire.
            if (preg_match('/delete|remove|usun|logout|wyloguj|toggle|status|activate|deactivate/i', $h)) { continue; }
            if (preg_match('/vouch|bon|kupon|promo|rabat|code|kod|discount|remise/i', $h . ' ' . $t)) { $liens[$h] = $t; }
        }
        echo '  liens du menu (bons / promotions) : ', count($liens), "\n";
        foreach ($liens as $h => $t) { printf("    %-60s %s\n", mb_substr($h, 0, 60), mb_substr($t, 0, 60)); }
        foreach (array_slice(array_keys($liens), 0, 8) as $h) {
            $url = preg_match('#^https?://#', $h) ? $h : (str_starts_with($h, '/') ? 'https://atelierby.tfbuddy.com' . $h : $panel . $h);
            [$c, $p] = $get($url);
            $titre = preg_match('#<h[1-3][^>]*>(.*?)</h[1-3]>#si', $p, $tm) ? $texte($tm[1]) : '';
            preg_match_all('#<tr\b[^>]*>(.*?)</tr>#si', $p, $rows);
            printf("  --- %s → HTTP %d · « %s » · %d ligne(s) de tableau · « SONDE COCKPIT » %s · « Office invitation » %s\n",
                mb_substr($h, 0, 50), $c, mb_substr($titre, 0, 50), count($rows[1]),
                str_contains($p, 'SONDE COCKPIT') ? 'présent' : 'absent', str_contains($p, 'Office invitation') ? 'présent' : 'absent');
            foreach (array_slice($rows[1], 0, 14) as $r) {
                $l = (string) svValeur('name', $texte($r));
                if ($l !== '') { echo '      | ', mb_substr($l, 0, 150), "\n"; }
            }
            // Une page sans tableau (application chargée en JavaScript) : les appels d'API qu'elle fait.
            if ($rows[1] === [] && preg_match_all('#["\'](/api/v1/[a-z0-9/_\-{}]+|[a-z0-9_\-/]*voucher[a-z0-9_\-/]*)["\']#i', $p, $am)) {
                echo '      appels relevés : ', implode(', ', array_slice(array_values(array_unique($am[1])), 0, 12)), "\n";
            }
        }
    }
} catch (Throwable $e) {
    echo '  panel illisible : ', mb_substr($e->getMessage(), 0, 200), "\n";
} finally {
    @unlink($jar);
}

echo "\n=== FIN SONDE VOUCHERS ===\n";
