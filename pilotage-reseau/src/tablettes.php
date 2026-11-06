<?php
declare(strict_types=1);

/*
 * MODULE FORMATION — LES TABLETTES DES MAGASINS (lot 5 de la fusion « Manuel du réseau », 06/11/2026).
 *
 * Décision du client : UNE SEULE tablette par magasin, celle du Cockpit (public/formation/sop/tablette.html). Le
 * PIN DU MAGASIN ouvre l'appareil (une session gardée sur l'appareil, révocable) ; ensuite le PIN DE L'EMPLOYÉ
 * signe, comme avant (src/sop.php : vérifié au panel, jeton de 4 h). L'ancienne tablette du CRM
 * (app/crm/web/manuel/, tables crm_mo_magasins / crm_mo_acces) renvoie ici.
 *
 *  ceo_tablette_magasin  une tablette de magasin : nom, boutique du panel (shop_id), PIN à 6 chiffres UNIQUE (ni
 *                        000000 ni suite), archivée ou non ; crm_id = le magasin du CRM d'où elle a été reprise
 *  ceo_tablette_acces    un appareil ouvert par le PIN du magasin : jeton HACHÉ (sha256), créé le, vu le, révoqué
 *  ceo_tablette_essai    les essais de PIN du magasin (adresse IP hachée avec le secret du cockpit) : 5 erreurs
 *                        par adresse en 15 minutes, 10 erreurs en tout par heure, 50 par jour — la même rigueur
 *                        que la tablette du CRM
 *
 * Le PIN est gardé lisible : l'écran de gestion l'affiche (« ⧉ Copier adresse + PIN ») et l'unicité se vérifie en
 * base, comme au CRM. Il n'est JAMAIS écrit dans le journal ; le jeton de l'appareil n'est gardé qu'haché.
 *
 * REPRISE DU CRM (même base en production) : chaque ligne de crm_mo_magasins devient une tablette ici, UNE fois
 * (crm_id unique), avec le MÊME PIN quand il est lisible et libre — les équipes n'ont rien à changer ; sinon un PIN
 * neuf, dit dans le bilan (réglage `tabRepriseCrm`) et sur l'écran Tablettes. Lancée par l'installeur
 * (ensureTablettes), à la lecture de l'écran et par « Reprendre les magasins du CRM ».
 *
 * Routes PUBLIQUES (avant l'authentification, comme la tablette) :
 *   GET  /formation/sop/tablette/appareil[?shop=]    l'état de l'appareil (ouvert ? quel magasin ?)
 *   POST /formation/sop/tablette/appareil/ouvrir     {pin} → la session de l'appareil (cookie + jeton)
 *   POST /formation/sop/tablette/appareil/fermer     la session de cet appareil est révoquée
 * Les autres routes /formation/sop/tablette/* (src/sop.php) EXIGENT la session de l'appareil ; le magasin vient
 * de la session (tabShop), plus du paramètre ?shop=, qui ne sert plus qu'à pré-choisir le magasin à l'écran du PIN.
 *
 * Routes de GESTION : /formation/tablettes (liste, créer), PATCH /{id} (nom, boutique, archive), POST /{id}/pin
 * (nouveau PIN : tous ses appareils déconnectés), DELETE /appareils/{id}, POST /reprise-crm. Contrat :
 * docs/contrat-api.md § Formation · Tablettes des magasins.
 *
 * CODE DE GESTION (lot 6, décision du client du 06/11/2026) : en production l'API du cockpit n'exige pas de connexion
 * (bin/deploy.sh écrit `auth => false`), or la liste porte les PIN des magasins. Chaque route de gestion exige donc
 * le code de gestion, envoyé dans l'en-tête X-Code-Gestion et comparé à temps constant à la clé de configuration :
 * variable d'environnement TABLETTES_CODE, puis `tablettesCode` de config/config.php (écrite par bin/deploy.sh,
 * jamais dans le dépôt). Code non configuré = gestion fermée (403 `gestion_fermee`). Les essais faux sont limités
 * comme le PIN du magasin (5 par adresse en 15 minutes, 10 par heure et 50 par jour en tout ; table
 * ceo_tablette_gestion_essai) ; ils ne touchent ni au PIN du magasin ni aux tablettes déjà ouvertes. Les routes de la
 * tablette elle-même (appareil, puis /formation/sop/tablette/*) ne demandent pas ce code.
 */

const TAB_COOKIE = 'ceo_tablette';
const TAB_ESSAIS_IP = 5;            // erreurs par adresse IP en 15 minutes…
const TAB_ESSAIS_HEURE = 10;        // … erreurs en tout en une heure…
const TAB_ESSAIS_JOUR = 50;         // … et en un jour, avant de suspendre les essais
const TAB_SESSION_S = 400 * 86400;  // la session de l'appareil est gardée (cookie de 400 jours), révocable
const TAB_VU_S = 600;               // « vu le » se met à jour au plus toutes les 10 minutes
const TAB_GESTION_CLE = 'tablettesCode';   // la clé de config/config.php ; variable d'environnement TABLETTES_CODE

/* ═══ Schéma et reprise ═══════════════════════════════════════════════════════════════════════════ */

/** Les tables des tablettes ; la reprise des magasins du CRM s'ils sont dans la base. Idempotent. */
function ensureTablettes(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_tablette_magasin ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'nom VARCHAR(120) NOT NULL,'
        . 'shop_id VARCHAR(32) NULL,'
        . 'pin CHAR(6) NULL,'
        . 'pin_le DATETIME NULL,'
        . 'archive TINYINT(1) NOT NULL DEFAULT 0,'
        . 'crm_id INT UNSIGNED NULL,'
        . 'cree_le DATETIME NOT NULL,'
        . 'cree_par VARCHAR(120) NULL,'
        . 'maj_le DATETIME NULL,'
        . 'UNIQUE KEY u_pin (pin),'
        . 'UNIQUE KEY u_crm (crm_id),'
        . 'KEY k_shop (shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_tablette_acces ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'magasin_id INT UNSIGNED NOT NULL,'
        . "appareil VARCHAR(40) NOT NULL DEFAULT '',"
        . 'jeton_hash CHAR(64) NOT NULL,'
        . 'cree_le DATETIME NOT NULL,'
        . 'vu_le DATETIME NULL,'
        . 'revoque TINYINT(1) NOT NULL DEFAULT 0,'
        . 'UNIQUE KEY u_jeton (jeton_hash),'
        . 'KEY k_mag (magasin_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_tablette_essai ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'ip_hash CHAR(64) NOT NULL,'
        . 'ok TINYINT(1) NOT NULL DEFAULT 0,'
        . 'cree_le DATETIME NOT NULL,'
        . 'KEY k_ip (ip_hash, cree_le),'
        . 'KEY k_le (cree_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Les essais faux du code de gestion (lot 6) : à part de ceux du PIN du magasin, qu'ils ne doivent pas bloquer.
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_tablette_gestion_essai ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'ip_hash CHAR(64) NOT NULL,'
        . 'cree_le DATETIME NOT NULL,'
        . 'KEY k_ip (ip_hash, cree_le),'
        . 'KEY k_le (cree_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // La reprise ne doit jamais fermer le cockpit : une panne se note, l'appel suivant réessaie.
    try { tabRepriseCrm(); }
    catch (Throwable $e) { try { journalAdd('Installation', 'Erreur', 'Tablettes', 'Reprise des magasins du CRM impossible : ' . $e->getMessage()); } catch (Throwable $x) { /* journal absent */ } }
}

/** La table du CRM est-elle dans cette base ? */
function tabCrmDisponible(): bool
{
    $t = Db::row('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['crm_mo_magasins']);
    return (int) ($t['n'] ?? 0) > 0;
}

/**
 * Les magasins du CRM (crm_mo_magasins) deviennent des tablettes ici, une fois chacun (crm_id unique). Le PIN du
 * CRM est gardé quand il est lisible (6 chiffres permis) et libre ici ; sinon un PIN neuf, dit dans le bilan. Rend
 * le bilan de ce passage ; garde dans `tabRepriseCrm` le dernier passage qui a repris quelque chose.
 */
function tabRepriseCrm(): array
{
    if (!tabCrmDisponible()) { return ['disponible' => false, 'reprises' => [], 'dejaLa' => 0]; }
    $reprises = [];
    $dejaLa = 0;
    foreach (Db::rows('SELECT id, nom, store_id, pin, archive, cree_le FROM crm_mo_magasins ORDER BY id') as $m) {
        $cid = (int) $m['id'];
        if (Db::row('SELECT id FROM ceo_tablette_magasin WHERE crm_id = ?', [$cid]) !== null) { $dejaLa++; continue; }
        $pin = preg_replace('/\D/', '', (string) ($m['pin'] ?? ''));
        $raison = null;
        if (!tabPinPermis($pin)) { $raison = $pin === '' ? 'aucun PIN au CRM' : 'PIN du CRM illisible'; $pin = null; }
        elseif (Db::row('SELECT id FROM ceo_tablette_magasin WHERE pin = ?', [$pin]) !== null) { $raison = 'PIN du CRM déjà pris par une autre tablette'; $pin = null; }
        $nom = tabCourt($m['nom'] ?? '', 120) ?? ('Magasin ' . $cid);
        $shop = $m['store_id'] !== null ? foShopVal((string) $m['store_id']) : null;
        $cree = is_string($m['cree_le'] ?? null) && $m['cree_le'] !== '' ? (string) $m['cree_le'] : date('Y-m-d H:i:s');
        try {
            Db::exec('INSERT INTO ceo_tablette_magasin (nom, shop_id, pin, pin_le, archive, crm_id, cree_le, cree_par, maj_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$nom, $shop, $pin, $pin !== null ? date('Y-m-d H:i:s') : null, (int) $m['archive'] === 1 ? 1 : 0, $cid, $cree, 'Reprise du CRM', date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') { throw $e; }
            // Un autre passage l'a reprise entre-temps (crm_id), ou le PIN vient d'être pris : on recommence sans PIN.
            if (Db::row('SELECT id FROM ceo_tablette_magasin WHERE crm_id = ?', [$cid]) !== null) { $dejaLa++; continue; }
            Db::exec('INSERT INTO ceo_tablette_magasin (nom, shop_id, pin, pin_le, archive, crm_id, cree_le, cree_par, maj_le) VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?)',
                [$nom, $shop, (int) $m['archive'] === 1 ? 1 : 0, $cid, $cree, 'Reprise du CRM', date('Y-m-d H:i:s')]);
            $pin = null;
            $raison = 'PIN du CRM déjà pris par une autre tablette';
        }
        $id = (int) (Db::row('SELECT id FROM ceo_tablette_magasin WHERE crm_id = ?', [$cid])['id'] ?? 0);
        if ($pin === null && $id > 0) { tabPinNeuf($id); }
        $reprises[] = ['id' => $id, 'nom' => $nom, 'pinGarde' => $pin !== null, 'raison' => $raison, 'sansBoutique' => $shop === null, 'archive' => (int) $m['archive'] === 1];
    }
    $bilan = ['le' => date('c'), 'disponible' => true, 'reprises' => $reprises, 'dejaLa' => $dejaLa];
    if ($reprises !== []) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['tabRepriseCrm', json_encode($bilan, JSON_UNESCAPED_UNICODE)]);
        $neufs = array_values(array_filter($reprises, static fn ($r) => !$r['pinGarde']));
        // Jamais de PIN dans le journal : seulement les noms et ce qui s'est passé.
        try {
            journalAdd('Reprise du CRM', 'Formation', 'Tablettes', count($reprises) . ' tablette(s) reprise(s) du CRM — PIN gardé : ' . (count($reprises) - count($neufs))
                . ($neufs !== [] ? ' ; nouveau PIN : ' . implode(', ', array_map(static fn ($r) => $r['nom'], $neufs)) : ''));
        } catch (Throwable $e) { /* journal absent */ }
    }
    return $bilan;
}

/* ═══ PIN, jeton et session de l'appareil ═════════════════════════════════════════════════════════ */

/** Un PIN de magasin permis : 6 chiffres, ni six fois le même (000000), ni une suite (123456, 654321…). */
function tabPinPermis(?string $pin): bool
{
    if ($pin === null || !preg_match('/^\d{6}$/', $pin)) { return false; }
    if (preg_match('/^(\d)\1{5}$/', $pin)) { return false; }
    return !str_contains('0123456789', $pin) && !str_contains('9876543210', $pin);
}

/** Un PIN neuf, unique, pour la tablette ; tous ses appareils sont déconnectés. Rend le PIN. */
function tabPinNeuf(int $id): string
{
    for ($i = 0; $i < 60; $i++) {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!tabPinPermis($pin)) { continue; }
        try {
            Db::exec('UPDATE ceo_tablette_magasin SET pin = ?, pin_le = ?, maj_le = ? WHERE id = ?', [$pin, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            Db::exec('UPDATE ceo_tablette_acces SET revoque = 1 WHERE magasin_id = ? AND revoque = 0', [$id]);
            return $pin;
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') { throw $e; }   // déjà pris : on en tire un autre
        }
    }
    throw new RuntimeException('aucun PIN libre');
}

function tabHash(string $jeton): string { return hash('sha256', 'tab:' . $jeton); }

/** L'adresse IP de l'appel, hachée avec le secret du cockpit (jamais gardée en clair). */
function tabIpHash(): string
{
    $sel = function_exists('foSecret') ? foSecret() : (string) (setting('authSecret', '') ?: 'tablettes');
    return hash('sha256', 'tab-ip|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $sel);
}

/** Le jeton présenté par l'appareil : l'en-tête X-Tablette (gardé dans la tablette), sinon le cookie. */
function tabJetonRecu(): string
{
    $j = trim((string) ($_SERVER['HTTP_X_TABLETTE'] ?? ''));
    if ($j === '') { $j = trim((string) ($_COOKIE[TAB_COOKIE] ?? '')); }
    return preg_match('/^[A-Za-z0-9_-]{20,100}$/', $j) ? $j : '';
}

/**
 * La session de l'appareil qui appelle : ['acces', 'magasin', 'nom', 'shop'] ou null (aucun jeton, révoqué,
 * tablette archivée). Lue une fois par requête.
 */
function tabAppareil(): ?array
{
    static $memo = false;
    if ($memo !== false) { return $memo; }
    $memo = null;
    $j = tabJetonRecu();
    if ($j === '') { return null; }
    $a = Db::row('SELECT a.id, a.magasin_id, a.vu_le, a.revoque, m.nom, m.shop_id, m.archive FROM ceo_tablette_acces a
                  JOIN ceo_tablette_magasin m ON m.id = a.magasin_id WHERE a.jeton_hash = ?', [tabHash($j)]);
    if ($a === null || (int) $a['revoque'] === 1 || (int) $a['archive'] === 1) { return null; }
    if ($a['vu_le'] === null || strtotime((string) $a['vu_le']) < time() - TAB_VU_S) {
        Db::exec('UPDATE ceo_tablette_acces SET vu_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $a['id']]);
    }
    $memo = ['acces' => (int) $a['id'], 'magasin' => (int) $a['magasin_id'], 'nom' => (string) $a['nom'],
        'shop' => $a['shop_id'] !== null && $a['shop_id'] !== '' ? (string) $a['shop_id'] : null];
    return $memo;
}

/** La boutique du panel de la session de l'appareil ; null sans session ou sans boutique reliée. */
function tabShop(): ?string
{
    $a = tabAppareil();
    return $a !== null ? $a['shop'] : null;
}

/** L'appareil d'après son navigateur (affiché dans la gestion, rien de plus). */
function tabAppareilNom(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return match (true) {
        str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'iPhone') => 'iPhone',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Macintosh') => 'Mac / iPad',
        str_contains($ua, 'Windows') => 'Windows',
        default => 'Navigateur',
    };
}

/** Le chemin du cookie : le dossier de l'API qui a reçu l'appel (…/api/cockpit/). */
function tabCookieChemin(): string
{
    $p = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    $i = strpos($p, '/api/cockpit/');
    return $i !== false ? substr($p, 0, $i) . '/api/cockpit/' : '/';
}

function tabHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Un refus de la tablette, avec un code que l'écran traduit (FR | NL). */
function tabRefus(int $http, string $code, string $msg): array
{
    http_response_code($http);
    return ['error' => $msg, 'code' => $code];
}

/**
 * POST /formation/sop/tablette/appareil/ouvrir {pin} — le PIN du magasin ouvre l'appareil. Essais limités : 5
 * erreurs par adresse IP en 15 minutes, puis 10 erreurs en tout par heure ou 50 par jour suspendent les essais
 * (une session déjà ouverte n'est pas touchée). Un refus attend 0,4 s.
 */
function tabOuvrir(array $b): array
{
    $ip = tabIpHash();
    $now = date('Y-m-d H:i:s');
    Db::exec('DELETE FROM ceo_tablette_essai WHERE cree_le < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $n = static function (string $where, array $v): int { return (int) (Db::row('SELECT COUNT(*) AS n FROM ceo_tablette_essai WHERE ok = 0 AND ' . $where, $v)['n'] ?? 0); };
    if ($n('ip_hash = ? AND cree_le > ?', [$ip, date('Y-m-d H:i:s', time() - 900)]) >= TAB_ESSAIS_IP) {
        return tabRefus(429, 'essais', 'Trop d’essais depuis cet appareil : réessayer dans 15 minutes.');
    }
    if ($n('cree_le > ?', [date('Y-m-d H:i:s', time() - 3600)]) >= TAB_ESSAIS_HEURE || $n('cree_le > ?', [date('Y-m-d H:i:s', time() - 86400)]) >= TAB_ESSAIS_JOUR) {
        tabNoterSuspension();
        return tabRefus(429, 'suspendu', 'Ouverture des tablettes momentanément suspendue (trop d’essais) : réessayer dans une heure.');
    }
    $pin = preg_replace('/\D/', '', is_scalar($b['pin'] ?? null) ? (string) $b['pin'] : '');
    $m = strlen($pin) === 6 ? Db::row('SELECT id, nom, shop_id, archive FROM ceo_tablette_magasin WHERE pin = ?', [$pin]) : null;
    $ok = $m !== null && (int) $m['archive'] === 0;
    Db::exec('INSERT INTO ceo_tablette_essai (ip_hash, ok, cree_le) VALUES (?, ?, ?)', [$ip, $ok ? 1 : 0, $now]);
    if (!$ok) {
        usleep(400000);
        return $m === null ? tabRefus(401, 'pin', 'PIN du magasin inconnu.') : tabRefus(401, 'archive', 'Cette tablette n’est plus active.');
    }
    $jeton = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    Db::exec('INSERT INTO ceo_tablette_acces (magasin_id, appareil, jeton_hash, cree_le, vu_le) VALUES (?, ?, ?, ?, ?)',
        [(int) $m['id'], tabAppareilNom(), tabHash($jeton), $now, $now]);
    if (!headers_sent()) {
        setcookie(TAB_COOKIE, $jeton, ['expires' => time() + TAB_SESSION_S, 'path' => tabCookieChemin(), 'secure' => tabHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    try { journalAdd('Tablette', 'Formation', 'Tablettes', '« ' . (string) $m['nom'] . ' » : un appareil ouvert (' . tabAppareilNom() . ')'); } catch (Throwable $e) { /* journal absent */ }
    return ['ok' => true, 'jeton' => $jeton, 'magasin' => tabMagasinPublic($m)];
}

/** Les essais sont suspendus : noté au journal une fois par heure (sans PIN ni adresse). */
function tabNoterSuspension(): void
{
    $d = setting('tabSuspensionNotee');
    if (is_int($d) && $d > time() - 3600) { return; }
    try {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['tabSuspensionNotee', json_encode(time())]);
        journalAdd('Tablette', 'Erreur', 'Tablettes', 'Ouverture des tablettes suspendue : trop de PIN de magasin faux en peu de temps.');
    } catch (Throwable $e) { /* journal absent */ }
}

/** Ce que la tablette voit de son magasin : jamais le PIN. */
function tabMagasinPublic(array $m): array
{
    $shop = $m['shop_id'] !== null && $m['shop_id'] !== '' ? (string) $m['shop_id'] : null;
    return ['id' => (int) $m['id'], 'nom' => (string) $m['nom'], 'shop' => $shop];
}

/**
 * GET /formation/sop/tablette/appareil[?shop=] — l'état de l'appareil. Ouvert : son magasin (et `autreShop` quand
 * le lien demandait une autre boutique). Fermé : le magasin pré-choisi par ?shop= (son nom seulement) s'il a une
 * tablette active.
 */
function tabEtat(): array
{
    $q = foShopParam();
    $a = tabAppareil();
    if ($a !== null) {
        return ['ouvert' => true, 'magasin' => ['id' => $a['magasin'], 'nom' => $a['nom'], 'shop' => $a['shop']],
            'autreShop' => $q !== null && $a['shop'] !== null && $q !== $a['shop']];
    }
    $pre = null;
    if ($q !== null) {
        $m = Db::row('SELECT nom FROM ceo_tablette_magasin WHERE shop_id = ? AND archive = 0 ORDER BY id LIMIT 1', [$q]);
        if ($m !== null) { $pre = ['shop' => $q, 'nom' => (string) $m['nom']]; }
    }
    return ['ouvert' => false, 'prechoix' => $pre];
}

/** POST /formation/sop/tablette/appareil/fermer — la session de cet appareil est révoquée. */
function tabFermer(): array
{
    $j = tabJetonRecu();
    if ($j !== '') { Db::exec('UPDATE ceo_tablette_acces SET revoque = 1 WHERE jeton_hash = ?', [tabHash($j)]); }
    if (!headers_sent()) { setcookie(TAB_COOKIE, '', ['expires' => time() - 3600, 'path' => tabCookieChemin(), 'secure' => tabHttps(), 'httponly' => true, 'samesite' => 'Lax']); }
    return ['ok' => true];
}

/** Les routes publiques de l'appareil ; null quand le chemin n'en est pas une. */
function tabRoutePublique(string $method, string $path): ?array
{
    return match (true) {
        $method === 'GET' && $path === '/formation/sop/tablette/appareil' => tabEtat(),
        $method === 'POST' && $path === '/formation/sop/tablette/appareil/ouvrir' => tabOuvrir(body()),
        $method === 'POST' && $path === '/formation/sop/tablette/appareil/fermer' => tabFermer(),
        default => null,
    };
}

/**
 * La garde des routes de la tablette (src/sop.php) : null quand l'appareil est ouvert, sinon le refus à rendre
 * (401, `appareil: true` — la tablette revient à l'écran du PIN du magasin).
 */
function tabGarde(): ?array
{
    if (tabAppareil() !== null) { return null; }
    http_response_code(401);
    return ['error' => 'Tablette fermée : taper le PIN du magasin.', 'code' => 'appareil', 'appareil' => true];
}

/* ═══ Le code de gestion (lot 6) ══════════════════════════════════════════════════════════════════ */

/** Le code de gestion configuré : TABLETTES_CODE, puis `tablettesCode` de config.php ; '' = non configuré. */
function tabCodeGestion(): string
{
    $v = getenv('TABLETTES_CODE');
    if (!is_string($v) || trim($v) === '') { $v = Db::config()[TAB_GESTION_CLE] ?? ''; }
    return is_scalar($v) ? trim((string) $v) : '';
}

/**
 * La garde des routes de gestion : null quand le code reçu (en-tête X-Code-Gestion) est le bon, sinon le refus à
 * rendre. Code non configuré : 403 `gestion_fermee`. Code absent : 403 `code_attendu` (ce n'est pas un essai). Code
 * faux : 403 `code_gestion`, compté ; 5 erreurs par adresse en 15 minutes, puis 10 en tout par heure ou 50 par jour
 * suspendent la gestion (429, même avec le bon code, comme le PIN du magasin). Comparaison à temps constant (deux
 * empreintes de même longueur). Jamais de code dans le journal.
 */
function tabGardeGestion(): ?array
{
    $code = tabCodeGestion();
    if ($code === '') {
        return tabRefus(403, 'gestion_fermee', 'Code de gestion non configuré : la gestion des tablettes est fermée. Il se pose sur le serveur (TABLETTES_CODE, voir docs/DEPLOIEMENT.md).');
    }
    $recu = trim((string) ($_SERVER['HTTP_X_CODE_GESTION'] ?? ''));
    if ($recu === '') { return tabRefus(403, 'code_attendu', 'Code de gestion attendu pour gérer les tablettes.'); }
    $ip = tabIpHash();
    Db::exec('DELETE FROM ceo_tablette_gestion_essai WHERE cree_le < ?', [date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $n = static function (string $where, array $v): int { return (int) (Db::row('SELECT COUNT(*) AS n FROM ceo_tablette_gestion_essai WHERE ' . $where, $v)['n'] ?? 0); };
    if ($n('ip_hash = ? AND cree_le > ?', [$ip, date('Y-m-d H:i:s', time() - 900)]) >= TAB_ESSAIS_IP) {
        return tabRefus(429, 'essais', 'Trop de codes de gestion faux depuis cet appareil : réessayer dans 15 minutes.');
    }
    if ($n('cree_le > ?', [date('Y-m-d H:i:s', time() - 3600)]) >= TAB_ESSAIS_HEURE || $n('cree_le > ?', [date('Y-m-d H:i:s', time() - 86400)]) >= TAB_ESSAIS_JOUR) {
        tabNoterSuspensionGestion();
        return tabRefus(429, 'suspendu', 'Gestion des tablettes momentanément suspendue (trop de codes faux) : réessayer dans une heure.');
    }
    $sel = function_exists('foSecret') ? foSecret() : 'tablettes';
    if (hash_equals(hash_hmac('sha256', $code, $sel), hash_hmac('sha256', $recu, $sel))) { return null; }
    Db::exec('INSERT INTO ceo_tablette_gestion_essai (ip_hash, cree_le) VALUES (?, ?)', [$ip, date('Y-m-d H:i:s')]);
    usleep(400000);
    return tabRefus(403, 'code_gestion', 'Code de gestion refusé.');
}

/** La gestion est suspendue : noté au journal une fois par heure (sans code ni adresse). */
function tabNoterSuspensionGestion(): void
{
    $d = setting('tabGestionSuspensionNotee');
    if (is_int($d) && $d > time() - 3600) { return; }
    try {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['tabGestionSuspensionNotee', json_encode(time())]);
        journalAdd('Tablette', 'Erreur', 'Tablettes', 'Gestion des tablettes suspendue : trop de codes de gestion faux en peu de temps.');
    } catch (Throwable $e) { /* journal absent */ }
}

/* ═══ Gestion (sous le code de gestion) ═══════════════════════════════════════════════════════════ */

/** GET /formation/tablettes — les tablettes, leurs appareils, les boutiques du panel, le bilan de la reprise. */
function tabListe(): array
{
    $boutiques = function_exists('foMagasins') ? foMagasins() : [];
    $noms = [];
    foreach ($boutiques as $b) { $noms[$b['id']] = $b['nom']; }
    $acc = [];
    foreach (Db::rows('SELECT id, magasin_id, appareil, cree_le, vu_le FROM ceo_tablette_acces WHERE revoque = 0 ORDER BY id DESC') as $a) {
        $acc[(int) $a['magasin_id']][] = ['id' => (int) $a['id'], 'appareil' => (string) $a['appareil'], 'creeLe' => foIso($a['cree_le']), 'vuLe' => foIso($a['vu_le'])];
    }
    $auj = [];
    try {
        foreach (Db::rows('SELECT shop_id, COUNT(*) AS n FROM ceo_sop_suivi WHERE maj_le >= ? GROUP BY shop_id', [date('Y-m-d 00:00:00')]) as $r) { $auj[(string) $r['shop_id']] = (int) $r['n']; }
    } catch (PDOException $e) { /* suivi absent : rien à compter */ }
    $rows = Db::rows('SELECT * FROM ceo_tablette_magasin ORDER BY archive, nom, id');
    $parShop = [];
    foreach ($rows as $m) { if ((int) $m['archive'] === 0 && $m['shop_id'] !== null) { $parShop[(string) $m['shop_id']] = ($parShop[(string) $m['shop_id']] ?? 0) + 1; } }
    $mags = array_map(static function (array $m) use ($acc, $auj, $noms, $parShop): array {
        $shop = $m['shop_id'] !== null && $m['shop_id'] !== '' ? (string) $m['shop_id'] : null;
        return ['id' => (int) $m['id'], 'nom' => (string) $m['nom'], 'shopId' => $shop, 'boutique' => $shop !== null ? ($noms[$shop] ?? null) : null,
            'pin' => (string) ($m['pin'] ?? ''), 'pinLe' => foIso($m['pin_le']), 'archive' => (int) $m['archive'] === 1,
            'creeLe' => foIso($m['cree_le']), 'creePar' => (string) ($m['cree_par'] ?? ''), 'reprisCrm' => $m['crm_id'] !== null,
            'appareils' => $acc[(int) $m['id']] ?? [], 'aujourdhui' => $shop !== null ? ($auj[$shop] ?? 0) : 0,
            'memeBoutique' => $shop !== null && ($parShop[$shop] ?? 0) > 1 && (int) $m['archive'] === 0];
    }, $rows);
    $bilan = setting('tabRepriseCrm');
    return ['magasins' => $mags, 'boutiques' => $boutiques, 'crmDisponible' => tabCrmDisponible(), 'reprise' => is_array($bilan) ? $bilan : null,
        'essais' => ['ip15min' => TAB_ESSAIS_IP, 'heure' => TAB_ESSAIS_HEURE, 'jour' => TAB_ESSAIS_JOUR]];
}

function tabLigne(int $id): ?array
{
    return Db::row('SELECT * FROM ceo_tablette_magasin WHERE id = ?', [$id]);
}

/** Une boutique choisie : son identifiant, contrôlé ; '' ou null = aucune. */
function tabShopChoisi(mixed $v, ?string &$err): ?string
{
    $err = null;
    if ($v === null || $v === '') { return null; }
    $s = foShopVal($v);
    if ($s === null) { $err = 'boutique illisible'; }
    return $s;
}

/** POST /formation/tablettes {nom, shopId?} — une tablette de magasin, avec son PIN. */
function tabCreer(array $b): array
{
    $nom = tabCourt($b['nom'] ?? null, 120);
    if ($nom === null) { return foRefus(422, 'nom de la tablette attendu'); }
    $shop = tabShopChoisi($b['shopId'] ?? null, $err);
    if ($err !== null) { return foRefus(422, $err); }
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_tablette_magasin (nom, shop_id, cree_le, cree_par, maj_le) VALUES (?, ?, ?, ?, ?)', [$nom, $shop, $now, foPar($b), $now]);
    $id = (int) Db::pdo()->lastInsertId();
    tabPinNeuf($id);
    try { journalAdd(foPar($b), 'Formation', 'Tablettes', '« ' . $nom . ' » : tablette créée'); } catch (Throwable $e) { /* journal absent */ }
    return ['ok' => true, 'id' => $id] + tabListe();
}

/** PATCH /formation/tablettes/{id} {nom?, shopId?, archive?} — clé absente = inchangée ; archiver déconnecte ses appareils. */
function tabModifier(int $id, array $b): array
{
    $m = tabLigne($id);
    if ($m === null) { return foRefus(404, 'tablette inconnue'); }
    $set = [];
    $v = [];
    $quoi = [];
    if (array_key_exists('nom', $b)) {
        $nom = tabCourt($b['nom'], 120);
        if ($nom === null) { return foRefus(422, 'nom de la tablette attendu'); }
        $set[] = 'nom = ?'; $v[] = $nom; $quoi[] = 'renommée « ' . $nom . ' »';
    }
    if (array_key_exists('shopId', $b)) {
        $shop = tabShopChoisi($b['shopId'], $err);
        if ($err !== null) { return foRefus(422, $err); }
        $set[] = 'shop_id = ?'; $v[] = $shop; $quoi[] = $shop !== null ? 'reliée à la boutique ' . $shop : 'sans boutique';
    }
    $archiver = null;
    if (array_key_exists('archive', $b)) {
        $archiver = (bool) $b['archive'];
        $set[] = 'archive = ?'; $v[] = $archiver ? 1 : 0; $quoi[] = $archiver ? 'archivée' : 'réactivée';
    }
    if ($set === []) { return ['ok' => true] + tabListe(); }
    $set[] = 'maj_le = ?'; $v[] = date('Y-m-d H:i:s');
    $v[] = $id;
    Db::exec('UPDATE ceo_tablette_magasin SET ' . implode(', ', $set) . ' WHERE id = ?', $v);
    $n = 0;
    if ($archiver === true) { $n = Db::exec('UPDATE ceo_tablette_acces SET revoque = 1 WHERE magasin_id = ? AND revoque = 0', [$id]); }
    try { journalAdd(foPar($b), 'Formation', 'Tablettes', '« ' . (string) $m['nom'] . ' » : ' . implode(', ', $quoi) . ($n ? ' (' . $n . ' appareil(s) déconnecté(s))' : '')); } catch (Throwable $e) { /* journal absent */ }
    return ['ok' => true] + tabListe();
}

/** POST /formation/tablettes/{id}/pin — un nouveau PIN ; tous les appareils de la tablette sont déconnectés. */
function tabNouveauPin(int $id, array $b): array
{
    $m = tabLigne($id);
    if ($m === null) { return foRefus(404, 'tablette inconnue'); }
    $n = (int) (Db::row('SELECT COUNT(*) AS n FROM ceo_tablette_acces WHERE magasin_id = ? AND revoque = 0', [$id])['n'] ?? 0);
    tabPinNeuf($id);
    try { journalAdd(foPar($b), 'Formation', 'Tablettes', '« ' . (string) $m['nom'] . ' » : nouveau PIN (' . $n . ' appareil(s) déconnecté(s))'); } catch (Throwable $e) { /* journal absent */ }
    return ['ok' => true, 'deconnectes' => $n] + tabListe();
}

/** DELETE /formation/tablettes/appareils/{id} — cet appareil devra retaper le PIN du magasin. */
function tabDeconnecter(int $id): array
{
    $a = Db::row('SELECT a.id, a.appareil, m.nom FROM ceo_tablette_acces a JOIN ceo_tablette_magasin m ON m.id = a.magasin_id WHERE a.id = ?', [$id]);
    if ($a === null) { return foRefus(404, 'appareil inconnu'); }
    Db::exec('UPDATE ceo_tablette_acces SET revoque = 1 WHERE id = ?', [$id]);
    try { journalAdd(foPar([]), 'Formation', 'Tablettes', '« ' . (string) $a['nom'] . ' » : appareil déconnecté (' . (string) $a['appareil'] . ')'); } catch (Throwable $e) { /* journal absent */ }
    return ['ok' => true] + tabListe();
}

/** Les routes de gestion ; null quand le chemin n'en est pas une. */
function tabRoute(string $method, string $path): ?array
{
    if ($path !== '/formation/tablettes' && !str_starts_with($path, '/formation/tablettes/')) { return null; }
    // Le code de gestion d'abord (lot 6) : la liste porte les PIN, et l'API n'exige pas de connexion en production.
    if (($g = tabGardeGestion()) !== null) { return $g; }
    return match (true) {
        $method === 'GET' && $path === '/formation/tablettes' => (static function (): array { tabRepriseCrm(); return tabListe(); })(),
        $method === 'POST' && $path === '/formation/tablettes' => tabCreer(body()),
        $method === 'POST' && $path === '/formation/tablettes/reprise-crm' => ['ok' => true, 'bilan' => tabRepriseCrm()] + tabListe(),
        $method === 'PATCH' && preg_match('#^/formation/tablettes/(\d+)$#', $path, $m) === 1 => tabModifier((int) $m[1], body()),
        $method === 'POST' && preg_match('#^/formation/tablettes/(\d+)/pin$#', $path, $m) === 1 => tabNouveauPin((int) $m[1], body()),
        $method === 'DELETE' && preg_match('#^/formation/tablettes/appareils/(\d+)$#', $path, $m) === 1 => tabDeconnecter((int) $m[1]),
        default => null,
    };
}

function tabCourt(mixed $v, int $max): ?string
{
    if (!is_scalar($v)) { return null; }
    $v = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
    return $v === '' ? null : mb_substr($v, 0, $max);
}
