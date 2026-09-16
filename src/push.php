<?php
declare(strict_types=1);

/**
 * LE WEB PUSH — une notification qui atteint le téléphone écran éteint.
 *
 * Sans dépendance : PHP 8 + OpenSSL suffisent. Deux mécaniques, l'une et
 * l'autre normalisées, qu'on assemble ici une fois pour toutes.
 *
 *  1. VAPID (RFC 8292) — dire au service de push QUI envoie. Un couple de clés
 *     EC P-256 propre au serveur, et un jeton JWT signé ES256 à chaque envoi.
 *     La clé publique part aussi dans le navigateur : c'est elle qu'il lie à
 *     l'abonnement, et un envoi signé d'une autre clé sera refusé.
 *
 *  2. Le chiffrement du message (RFC 8291, `aes128gcm`) — le service de push
 *     transporte sans lire. On génère une clé éphémère, on fait un ECDH avec
 *     la clé publique du navigateur, on dérive la clé et le nonce par HKDF, et
 *     on chiffre en AES-128-GCM. Personne entre les deux ne voit le texte.
 *
 * Les clés VAPID vivent dans `ceo_app_setting` sous `pushVapid`, générées à la
 * première demande. Les changer INVALIDE tous les abonnements existants : le
 * navigateur a lié chaque abonnement à la clé publique qu'on lui a donnée.
 */

const PUSH_TTL      = 86400;      // 24 h : au-delà, la rupture de stock n'intéresse plus
const PUSH_URGENCE  = 'normal';
const PUSH_SUJET    = 'mailto:cockpit@latelier.by';

/* --- base64url, partout dans ces normes -------------------------------------- */
function pushB64(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function pushDeB64(string $txt): string
{
    $t = strtr($txt, '-_', '+/');
    $r = base64_decode($t . str_repeat('=', (4 - strlen($t) % 4) % 4), true);
    return $r === false ? '' : $r;
}

/* --- la table des abonnements ------------------------------------------------ */
function pushTable(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_push_abonnement (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL,
        endpoint VARCHAR(512) NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(64) NOT NULL,
        agent VARCHAR(255) DEFAULT NULL,
        cree_le DATETIME NOT NULL,
        vu_le DATETIME DEFAULT NULL,
        echecs INT NOT NULL DEFAULT 0,
        UNIQUE KEY u_endpoint (endpoint(255)),
        KEY k_shop (shop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* --- les clés VAPID ---------------------------------------------------------- */
/** Le couple du serveur, créé une fois puis relu. */
function pushVapid(): ?array
{
    $v = setting('pushVapid', null);
    if (is_array($v) && !empty($v['pem']) && !empty($v['publique'])) { return $v; }
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($k === false) { return null; }
    $pem = '';
    if (!openssl_pkey_export($k, $pem)) { return null; }
    $d = openssl_pkey_get_details($k);
    if (!isset($d['ec']['x'], $d['ec']['y'])) { return null; }
    $pub = "\x04" . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $v = ['pem' => $pem, 'publique' => pushB64($pub)];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['pushVapid', json_encode($v, JSON_UNESCAPED_UNICODE)]);
    return $v;
}

/** Le jeton VAPID pour une origine donnée (« https://fcm.googleapis.com »). */
function pushJeton(string $origine, array $vapid): ?string
{
    $entete = pushB64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $corps  = pushB64(json_encode(['aud' => $origine, 'exp' => time() + 12 * 3600, 'sub' => PUSH_SUJET]));
    $signe  = '';
    $cle    = openssl_pkey_get_private($vapid['pem']);
    if ($cle === false) { return null; }
    if (!openssl_sign($entete . '.' . $corps, $signe, $cle, OPENSSL_ALGO_SHA256)) { return null; }
    // openssl_sign rend du DER ; le JWT veut r||s sur 32 octets chacun.
    $rs = pushDerVersRs($signe);
    if ($rs === null) { return null; }
    return $entete . '.' . $corps . '.' . pushB64($rs);
}

/** DER SEQUENCE{INTEGER r, INTEGER s} → r||s, 64 octets. */
function pushDerVersRs(string $der): ?string
{
    $i = 0;
    if (($der[$i++] ?? '') !== "\x30") { return null; }
    $len = ord($der[$i++] ?? "\x00");
    if ($len & 0x80) { $i += ($len & 0x7f); }          // longueur longue : on saute
    $lire = function () use ($der, &$i): ?string {
        if (($der[$i++] ?? '') !== "\x02") { return null; }
        $n = ord($der[$i++] ?? "\x00");
        $v = substr($der, $i, $n); $i += $n;
        $v = ltrim($v, "\x00");                        // l'INTEGER DER peut porter un 0 de signe
        return str_pad($v, 32, "\x00", STR_PAD_LEFT);
    };
    $r = $lire(); $s = $lire();
    return ($r === null || $s === null) ? null : $r . $s;
}

/** Un point P-256 brut (65 octets) → clé publique OpenSSL. */
function pushClePublique(string $point): mixed
{
    if (strlen($point) !== 65 || $point[0] !== "\x04") { return false; }
    // L'en-tête DER d'une SubjectPublicKeyInfo ecPublicKey/prime256v1 est fixe.
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

/**
 * Le corps chiffré d'un message, en `aes128gcm` (RFC 8291).
 * En-tête : sel(16) ‖ rs(4) ‖ longueur de la clé(1) ‖ clé éphémère(65), puis
 * le chiffré. Le tout part tel quel dans le POST.
 */
function pushChiffrer(string $texte, string $p256dh, string $authSecret): ?string
{
    $uaPub = pushDeB64($p256dh);
    $auth  = pushDeB64($authSecret);
    $pair  = pushClePublique($uaPub);
    if ($pair === false || strlen($auth) < 16) { return null; }

    $ephem = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($ephem === false) { return null; }
    $ed = openssl_pkey_get_details($ephem);
    $asPub = "\x04" . str_pad($ed['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($ed['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $partage = openssl_pkey_derive($pair, $ephem, 32);
    if ($partage === false) { return null; }

    // IKM : HKDF(sel = secret d'authentification, info = « WebPush: info »)
    $ikm = hash_hkdf('sha256', $partage, 32, "WebPush: info\x00" . $uaPub . $asPub, $auth);
    $sel = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $sel);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $sel);

    $tag = '';
    $chiffre = openssl_encrypt($texte . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($chiffre === false) { return null; }

    return $sel . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $chiffre . $tag;
}

/**
 * Envoie un message à UN abonnement. Rend [code HTTP, message].
 * 404 et 410 : l'abonnement est mort, l'appelant doit le retirer.
 */
function pushEnvoyer(array $ab, array $charge): array
{
    $vapid = pushVapid();
    if ($vapid === null) { return [0, 'clés VAPID indisponibles']; }
    $u = parse_url((string) $ab['endpoint']);
    if (!isset($u['scheme'], $u['host'])) { return [0, 'endpoint illisible']; }
    $origine = $u['scheme'] . '://' . $u['host'];
    $jeton = pushJeton($origine, $vapid);
    if ($jeton === null) { return [0, 'jeton VAPID non signé']; }

    $corps = pushChiffrer(json_encode($charge, JSON_UNESCAPED_UNICODE), (string) $ab['p256dh'], (string) $ab['auth']);
    if ($corps === null) { return [0, 'chiffrement impossible']; }

    $ch = curl_init((string) $ab['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corps,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'TTL: ' . PUSH_TTL,
            'Urgency: ' . PUSH_URGENCE,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $jeton . ', k=' . $vapid['publique'],
        ],
    ]);
    $rep  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $code ? substr((string) $rep, 0, 200) : ($err ?: 'pas de réponse')];
}

/** Envoie à tous les abonnements d'un magasin, et nettoie les morts. */
function pushDiffuser(string $shop, array $charge): array
{
    pushTable();
    $abs = Db::rows('SELECT * FROM ceo_push_abonnement WHERE shop_id = ?', [$shop]);
    $ok = 0; $morts = 0; $erreurs = [];
    foreach ($abs as $ab) {
        [$code, $msg] = pushEnvoyer($ab, $charge);
        if ($code >= 200 && $code < 300) {
            $ok++;
            Db::exec('UPDATE ceo_push_abonnement SET vu_le = NOW(), echecs = 0 WHERE id = ?', [$ab['id']]);
        } elseif ($code === 404 || $code === 410) {
            $morts++;
            Db::exec('DELETE FROM ceo_push_abonnement WHERE id = ?', [$ab['id']]);
        } else {
            $erreurs[] = $code . ' ' . $msg;
            Db::exec('UPDATE ceo_push_abonnement SET echecs = echecs + 1 WHERE id = ?', [$ab['id']]);
        }
    }
    return ['abonnements' => count($abs), 'envoyes' => $ok, 'retires' => $morts, 'erreurs' => $erreurs];
}

/* --- les routes -------------------------------------------------------------- */

/** GET /push/cle — la clé publique VAPID, et ce que le serveur sait faire. */
function ep_push_cle(): array
{
    $capable = function_exists('openssl_pkey_derive') && function_exists('hash_hkdf')
        && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    if (!$capable) {
        return ['pret' => false, 'motif' => 'PHP sans openssl_pkey_derive, hash_hkdf ou aes-128-gcm'];
    }
    $v = pushVapid();
    if ($v === null) { return ['pret' => false, 'motif' => 'clés VAPID non générées']; }
    $n = 0;
    try { pushTable(); $n = (int) (Db::row('SELECT COUNT(*) n FROM ceo_push_abonnement')['n'] ?? 0); }
    catch (Throwable $e) { /* table absente : zéro */ }
    return ['pret' => true, 'cle' => $v['publique'], 'abonnements' => $n];
}

/** POST /push/abonnements — le navigateur s'inscrit. */
function wr_push_abonnement(): array
{
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    $end  = trim((string) ($b['endpoint'] ?? ''));
    $p256 = trim((string) ($b['p256dh'] ?? ''));
    $auth = trim((string) ($b['auth'] ?? ''));
    if ($shop === '' || $end === '' || $p256 === '' || $auth === '') {
        http_response_code(400); return ['error' => 'shop, endpoint, p256dh et auth sont requis'];
    }
    pushTable();
    // Un même appareil qui se réabonne garde son endpoint : on met à jour.
    Db::exec('INSERT INTO ceo_push_abonnement (shop_id, endpoint, p256dh, auth, agent, cree_le)
              VALUES (?,?,?,?,?,NOW())
              ON DUPLICATE KEY UPDATE shop_id = VALUES(shop_id), p256dh = VALUES(p256dh),
                                      auth = VALUES(auth), agent = VALUES(agent), echecs = 0',
        [$shop, $end, $p256, $auth, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    journalAdd('CEO', 'Paramètre', null, 'Abonnement aux notifications du magasin ' . $shop);
    return ['ok' => true, 'shop' => $shop];
}

/** DELETE /push/abonnements — le navigateur se désinscrit. */
function wr_push_desabonnement(): array
{
    $b = body();
    $end = trim((string) ($b['endpoint'] ?? ''));
    if ($end === '') { http_response_code(400); return ['error' => 'endpoint requis']; }
    pushTable();
    Db::exec('DELETE FROM ceo_push_abonnement WHERE endpoint = ?', [$end]);
    return ['ok' => true];
}

/** POST /push/essai — un message de vérification, tout de suite. */
function wr_push_essai(): array
{
    $b = body();
    $shop = trim((string) ($b['shop'] ?? ''));
    if ($shop === '') { http_response_code(400); return ['error' => 'shop requis']; }
    $r = pushDiffuser($shop, [
        'titre' => 'Cockpit — essai',
        'corps' => 'Les notifications fonctionnent. Vous serez averti quand une référence passera sous son minimum.',
        'url'   => 'dashboard/?shop=' . rawurlencode($shop),
        'tag'   => 'essai-' . $shop,
    ]);
    return $r;
}
