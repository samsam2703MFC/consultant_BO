<?php

declare(strict_types=1);

/**
 * Active les comptes de la console franchisé (dépôt WebShop) sur le serveur — demandé par Sam le 01/11/2026,
 * pour que le module RH (/hr) puisse identifier franchisés et siège. Lancé en root par bin/deploy.sh ;
 * idempotent : sans rien à faire, il ne touche à rien.
 *
 *   php bin/console_bo.php [--api-dir=/var/www/html/webshop/api]
 *
 * 1. La section « bo » de la configuration de la console (son config.php, hors Git, géré à la main sur le
 *    serveur et jamais réécrit par les livraisons WebShop). Si elle MANQUE : deux secrets HMAC distincts sont
 *    tirés ici (jamais affichés), le bloc est ajouté à la fin du tableau de configuration, le fichier est
 *    sauvegardé, contrôlé (syntaxe, mêmes clés qu'avant plus « bo ») puis remplacé d'un coup ; si
 *    GET /health de la console ne répond plus comme avant, l'ancien fichier est remis aussitôt.
 *    Section présente : on n'y touche jamais.
 * 2. Les comptes de démonstration dont le mot de passe a été publié (« Test1234! », audit go-live WebShop du
 *    15/08) : tout compte actif qui l'accepte encore est désactivé.
 * 3. Les comptes demandés, s'il y en a : variable CONSOLE_BO_ACCOUNTS (secret GitHub du même nom), JSON
 *      [{"email": "…", "name": "…", "role": "siege|franchise", "shops": [2, 3], "password": "…"}, …]
 *    Créés ou mis à jour (nom, rôle, actif, boutiques ; le mot de passe seulement s'il a changé).
 *
 * Les journaux de livraison sont publics : ce script n'écrit jamais un secret, un mot de passe, un hachage ni
 * une adresse e-mail — seulement des comptes et des faits.
 */

const CB_DEMO_PASSWORD = 'Test1234!';

/** Le dossier de l'API de la console : celui qui porte config.php, lib.php (cfg) et bo/guard.php (bo_cfg). */
function cbLocateApi(?string $forced): ?string
{
    $cands = array_filter([$forced, getenv('WEBSHOP_API_DIR') ?: null, '/var/www/html/webshop/api', '/var/www/webshop/api', '/var/www/html/api']);
    foreach ((array) glob('/var/www/*/webshop/api') as $g) {
        $cands[] = $g;
    }
    // repli : tout dossier d'API de la console sous /var/www (le premier trouvé ; son chemin est journalisé)
    foreach (array_merge((array) glob('/var/www/*/api/bo/guard.php'), (array) glob('/var/www/*/*/api/bo/guard.php')) as $g) {
        $cands[] = dirname((string) $g, 2);
    }
    foreach (array_unique($cands) as $d) {
        $d = rtrim((string) $d, '/');
        if (is_file($d . '/config.php') && is_file($d . '/bo/guard.php') && is_file($d . '/lib.php')
            && str_contains((string) file_get_contents($d . '/lib.php'), 'function cfg')
            && str_contains((string) file_get_contents($d . '/bo/guard.php'), 'function bo_cfg')) {
            return $d;
        }
    }
    return null;
}

/** La configuration telle qu'un processus PHP neuf la lit (sans polluer celui-ci). */
function cbReadConfig(string $file): ?array
{
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo json_encode(require $argv[1]);') . ' ' . escapeshellarg($file) . ' 2>/dev/null');
    $j = is_string($out) ? json_decode($out, true) : null;
    return is_array($j) ? $j : null;
}

/** La section « bo » existe-t-elle déjà, avec les deux back-offices ? */
function cbHasBo(array $cfg): bool
{
    foreach (['franchisee', 'franchisor'] as $b) {
        $s = (string) ($cfg['bo'][$b]['secret'] ?? '');
        if (strlen($s) < 32 || str_starts_with($s, 'change-me')) {
            return false;
        }
    }
    return true;
}

/**
 * Ajoute « 'bo' => […] » à la fin du tableau retourné par config.php (avant le dernier « ]; »), avec la virgule
 * qu'il faut. Rend null si le fichier n'a pas la forme attendue (« return [ … ]; »).
 */
function cbInsertBo(string $src, array $bo): ?string
{
    $pos = strrpos($src, '];');
    if ($pos === false || !preg_match('/\breturn\s*\[/', $src)) {
        return null;
    }
    $head = rtrim(substr($src, 0, $pos));
    $sep = (str_ends_with($head, ',') || str_ends_with($head, '[')) ? '' : ',';
    $export = var_export($bo, true);
    $export = preg_replace('/^/m', '  ', $export);
    $block = $sep . "\n\n  /* Back-offices franchisé / siège (comptes bo_users) — section posée le " . gmdate('d/m/Y')
        . " par la livraison du cockpit\n   * (consultant_BO, bin/console_bo.php) pour le module RH /hr. Secrets tirés sur le serveur. */\n  'bo' => "
        . ltrim((string) $export) . ",\n";
    return $head . $block . substr($src, $pos);
}

function cbHealth(string $url): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5]);
    curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $c;
}

function cbLog(string $m): void
{
    fwrite(STDOUT, '[console-bo] ' . $m . "\n");
}

/** 1. La section bo. Rend true si elle est (ou était déjà) en place. */
function cbEnsureBo(string $dir, string $base): bool
{
    $file = $dir . '/config.php';
    $cfg = cbReadConfig($file);
    if ($cfg === null) {
        cbLog('configuration de la console illisible : rien n’est modifié');
        return false;
    }
    if (cbHasBo($cfg)) {
        cbLog('section « bo » déjà présente : inchangée');
        return true;
    }
    if (isset($cfg['bo'])) {
        cbLog('section « bo » présente mais incomplète : non modifiée (à reprendre à la main)');
        return false;
    }
    $bo = [
        'franchisee' => ['secret' => bin2hex(random_bytes(32)), 'cookie' => 'fb_franchisee_session', 'login_url' => '/bo/franchisee/login'],
        'franchisor' => ['secret' => bin2hex(random_bytes(32)), 'cookie' => 'fb_franchisor_session', 'login_url' => '/bo/franchisor/login'],
        'cookie_domain' => '',
        'cookie_secure' => true,
    ];
    $src = (string) file_get_contents($file);
    $new = cbInsertBo($src, $bo);
    if ($new === null) {
        cbLog('config.php n’a pas la forme « return [ … ]; » : rien n’est modifié');
        return false;
    }
    $tmp = $file . '.nouveau-' . bin2hex(random_bytes(3));
    file_put_contents($tmp, $new);
    $st = stat($file);
    @chown($tmp, (int) $st['uid']);
    @chgrp($tmp, (int) $st['gid']);
    @chmod($tmp, $st['mode'] & 0777);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc);
    $after = $rc === 0 ? cbReadConfig($tmp) : null;
    $before = $cfg;
    if ($after === null || !cbHasBo($after) || array_diff_key($before, $after) !== [] || array_keys(array_diff_key($after, $before)) !== ['bo']
        || json_encode(array_diff_key($after, ['bo' => 1])) !== json_encode($before)) {
        @unlink($tmp);
        cbLog('nouvelle configuration refusée au contrôle (syntaxe ou clés) : rien n’est modifié');
        return false;
    }
    $avant = cbHealth($base . '/health');
    $backup = $file . '.avant-bo-' . gmdate('YmdHis');
    copy($file, $backup);
    @chown($backup, (int) $st['uid']);
    @chgrp($backup, (int) $st['gid']);
    @chmod($backup, $st['mode'] & 0777);
    rename($tmp, $file);
    // Le cache PHP d'Apache revalide les fichiers toutes les 2 s (réglage par défaut, relevé à la livraison).
    sleep((int) (getenv('CB_OPCACHE_WAIT') ?: 3));
    $apres = cbHealth($base . '/health');
    $me = cbHealth($base . '/bo/franchisee/me');
    if ($apres !== $avant) {
        copy($backup, $file);
        cbLog("console : /health répondait {$avant}, puis {$apres} — ancienne configuration remise, rien n’est activé");
        return false;
    }
    cbLog("section « bo » ajoutée (deux secrets distincts, cookies Secure) ; sauvegarde : " . basename($backup)
        . " ; /health {$avant} → {$apres} ; /bo/franchisee/me → {$me} (401 attendu)");
    return true;
}

function cbPdo(string $dir): PDO
{
    $c = cbReadConfig($dir . '/config.php')['db'] ?? [];
    return new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", (string) $c['user'], (string) $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** 2. Les comptes qui acceptent encore le mot de passe de démonstration publié : désactivés. */
function cbDisableDemo(PDO $pdo): int
{
    $n = 0;
    foreach ($pdo->query("SELECT id, password_hash FROM bo_users WHERE active = 1 AND password_hash <> ''")->fetchAll() as $u) {
        if (password_verify(CB_DEMO_PASSWORD, (string) $u['password_hash'])) {
            $pdo->prepare('UPDATE bo_users SET active = 0 WHERE id = ?')->execute([(int) $u['id']]);
            $n++;
        }
    }
    return $n;
}

/**
 * 3. Les comptes demandés (CONSOLE_BO_ACCOUNTS). Rend [créés, mis à jour, refusés].
 *
 * @return array{0: int, 1: int, 2: list<string>}
 */
function cbProvision(PDO $pdo, string $json): array
{
    $list = json_decode($json, true);
    if (!is_array($list)) {
        return [0, 0, ['CONSOLE_BO_ACCOUNTS n’est pas un JSON valide']];
    }
    $shopIds = array_map('intval', $pdo->query('SELECT id FROM shops')->fetchAll(PDO::FETCH_COLUMN));
    $created = 0;
    $updated = 0;
    $refused = [];
    foreach (array_values($list) as $i => $a) {
        $email = strtolower(trim((string) ($a['email'] ?? '')));
        $role = ($a['role'] ?? '') === 'siege' ? 'siege' : (($a['role'] ?? '') === 'franchise' ? 'franchise' : null);
        $pw = (string) ($a['password'] ?? '');
        $name = mb_substr(trim((string) ($a['name'] ?? '')), 0, 120);
        $shops = array_values(array_unique(array_map('intval', (array) ($a['shops'] ?? []))));
        $why = null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $why = 'e-mail invalide';
        } elseif ($role === null) {
            $why = 'rôle attendu : siege ou franchise';
        } elseif ($role === 'franchise' && $shops === []) {
            $why = 'un franchisé a besoin d’au moins une boutique';
        } elseif (array_diff($shops, $shopIds) !== []) {
            $why = 'boutique inconnue : ' . implode(', ', array_diff($shops, $shopIds));
        } elseif ($pw !== '' && (strlen($pw) < 12 || $pw === CB_DEMO_PASSWORD)) {
            $why = 'mot de passe trop court (12 caractères au moins)';
        }
        $st = $pdo->prepare('SELECT id, password_hash FROM bo_users WHERE LOWER(TRIM(email)) = ?');
        $st->execute([$email]);
        $cur = $why === null ? $st->fetch() : false;
        if ($why === null && $cur === false && $pw === '') {
            $why = 'mot de passe obligatoire pour un nouveau compte';
        }
        if ($why !== null) {
            $refused[] = 'compte n° ' . ($i + 1) . ' : ' . $why;
            continue;
        }
        if ($cur === false) {
            $pdo->prepare('INSERT INTO bo_users (email, password_hash, display_name, role, active) VALUES (?, ?, ?, ?, 1)')
                ->execute([$email, password_hash($pw, PASSWORD_BCRYPT), $name !== '' ? $name : $email, $role]);
            $id = (int) $pdo->lastInsertId();
            $created++;
        } else {
            $id = (int) $cur['id'];
            $pdo->prepare('UPDATE bo_users SET display_name = ?, role = ?, active = 1 WHERE id = ?')->execute([$name !== '' ? $name : $email, $role, $id]);
            if ($pw !== '' && !password_verify($pw, (string) $cur['password_hash'])) {
                $pdo->prepare('UPDATE bo_users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_BCRYPT), $id]);
            }
            $updated++;
        }
        $pdo->prepare('DELETE FROM bo_user_shops WHERE user_id = ?')->execute([$id]);
        if ($role === 'franchise') {
            $ins = $pdo->prepare('INSERT INTO bo_user_shops (user_id, shop_id) VALUES (?, ?)');
            foreach ($shops as $s) {
                $ins->execute([$id, $s]);
            }
        }
    }
    return [$created, $updated, $refused];
}

/** Ce qu'il y a, en nombres seulement. */
function cbSummary(PDO $pdo): string
{
    $r = $pdo->query("SELECT role, SUM(active = 1) AS actifs, SUM(active = 0) AS inactifs,
        SUM(active = 1 AND password_hash <> '') AS avec_mdp FROM bo_users GROUP BY role")->fetchAll();
    $parts = [];
    foreach ($r as $x) {
        $parts[] = $x['role'] . ' : ' . (int) $x['actifs'] . ' actif(s) dont ' . (int) $x['avec_mdp'] . ' avec mot de passe, ' . (int) $x['inactifs'] . ' inactif(s)';
    }
    $sans = (int) $pdo->query("SELECT COUNT(*) FROM bo_users u WHERE u.role = 'franchise' AND u.active = 1
        AND NOT EXISTS (SELECT 1 FROM bo_user_shops s WHERE s.user_id = u.id)")->fetchColumn();
    return ($parts !== [] ? implode(' ; ', $parts) : 'aucun compte') . ($sans > 0 ? " ; {$sans} franchisé(s) actif(s) sans boutique" : '');
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $forced = null;
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--api-dir=(.+)$/', $a, $m)) {
            $forced = $m[1];
        }
    }
    $base = rtrim((string) (getenv('WEBSHOP_API_LOCAL') ?: 'http://127.0.0.1/webshop/api'), '/');
    $dir = cbLocateApi($forced);
    if ($dir === null) {
        cbLog('API de la console introuvable sur ce serveur : rien n’est fait');
        exit(0);
    }
    cbLog('API de la console : ' . $dir);
    $ok = cbEnsureBo($dir, $base);
    try {
        $pdo = cbPdo($dir);
        $n = cbDisableDemo($pdo);
        cbLog($n > 0 ? "{$n} compte(s) de démonstration au mot de passe publié désactivé(s)" : 'aucun compte n’accepte le mot de passe de démonstration publié');
        $json = (string) (getenv('CONSOLE_BO_ACCOUNTS') ?: '');
        if ($json !== '') {
            [$c, $u, $refused] = cbProvision($pdo, $json);
            cbLog("comptes demandés : {$c} créé(s), {$u} mis à jour" . ($refused !== [] ? ' ; refusés — ' . implode(' ; ', $refused) : ''));
        } else {
            cbLog('aucun compte demandé (secret CONSOLE_BO_ACCOUNTS vide)');
        }
        cbLog('comptes de la console : ' . cbSummary($pdo));
    } catch (Throwable $e) {
        cbLog('comptes : base de la console injoignable (' . get_class($e) . ')');
    }
    cbLog($ok ? 'connexion par la console : active' : 'connexion par la console : pas encore active');
}
