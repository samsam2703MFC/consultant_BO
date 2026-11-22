<?php

declare(strict_types=1);

/**
 * erp_cles — la table des clés commune à tout l'ERP (décision du 7 nov. 2026 : « les clés sont dans une table
 * commune pour tout l'ERP ; à part les clés, tous les portails doivent être indépendants »). Elle remplace le
 * projet `master_variable_keys` du registre. Une ligne par clé : cle, valeur, secret, indice, portail
 * (`commun` = intégration partagée ; `rec` / `mar` / `pil` / `frm` = propre à un portail), integration,
 * maj_le, maj_par. Même définition dans les quatre portails (CREATE TABLE IF NOT EXISTS).
 *
 * C'est le SEUL accès aux clés des quatre domaines du dépôt (Marketing, Formation, Pilotage consultant, Dashboard
 * franchisé ; réorganisation du 19/11/2026) : aucun autre fichier n'écrit de requête sur erp_cles. Lecture : erpCle(),
 * erpClesLire(), erpClesInventaire() (sans les valeurs). Écriture : erpCleEcrire(), settingEcrire() pour les réglages
 * dont des champs sont des clés (ERP_CLES_REGLAGES). Les valeurs sont en clair (aucune clé de chiffrement).
 *
 * Le Cockpit (Pilotage) y LIT les clés communes tenues par les autres (Meta : meta_app_id, meta_app_secret,
 * meta_page_id, meta_page_token, meta_system_token ; e-mail : smtp_* ; adresse de l'API du Recrutement
 * rec_api_url ; jeton de service erp_jeton_service) et y ÉCRIT les siennes : son adresse publique
 * (pil_api_url, apprise d'une requête https par nom de domaine) et le compte TF Buddy de « Mon compte »
 * (tfbuddy_base, tfbuddy_phone, tfbuddy_password). Il ne lit plus aucune table du Recrutement
 * (crm_settings, meta_connexion).
 */

function erpClesSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    try {
        Db::exec("CREATE TABLE IF NOT EXISTS erp_cles (cle VARCHAR(80) NOT NULL PRIMARY KEY, valeur MEDIUMTEXT NULL,"
            . " secret TINYINT(1) NOT NULL DEFAULT 1, indice VARCHAR(12) NULL, portail VARCHAR(20) NOT NULL DEFAULT 'commun',"
            . ' integration VARCHAR(60) NULL, maj_le DATETIME NOT NULL, maj_par VARCHAR(160) NULL, INDEX idx_erp_cles_portail (portail))'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) { /* sans droit CREATE : la table existe déjà (créée par le Recrutement) ou n'existe pas */ }
}

/** Les portails dont ce dépôt lit les clés : les clés communes et celles de ses domaines (codes inchangés d'erp_cles). */
const ERP_CLES_PORTAILS = ['commun', 'pil', 'mar', 'frm'];

/** Une clé (en clair), '' si absente. Lues une fois par requête. */
function erpCle(string $nom): string
{
    static $toutes = null;
    if ($nom === '') { $toutes = null; return ''; }
    if ($toutes === null) {
        $toutes = [];
        try {
            foreach (Db::rows('SELECT cle, valeur FROM erp_cles WHERE portail IN (' . implode(', ', array_fill(0, count(ERP_CLES_PORTAILS), '?')) . ')',
                ERP_CLES_PORTAILS) as $r) {
                $toutes[(string) $r['cle']] = trim((string) $r['valeur']);
            }
        } catch (Throwable $e) { /* table absente */ }
    }
    return $toutes[$nom] ?? '';
}

/** Écrit une clé du Cockpit ou une clé commune qu'il tient (vide = retirée). */
function erpCleEcrire(string $nom, string $valeur, bool $secret, string $integration, string $portail = 'commun'): void
{
    erpClesSchema();
    try {
        if (trim($valeur) === '') {
            Db::exec('DELETE FROM erp_cles WHERE cle = ?', [$nom]);
        } else {
            $v = trim($valeur);
            Db::exec('INSERT INTO erp_cles (cle, valeur, secret, indice, portail, integration, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                      ON DUPLICATE KEY UPDATE maj_le = IF(valeur <=> VALUES(valeur), maj_le, VALUES(maj_le)),
                          valeur = VALUES(valeur), secret = VALUES(secret), indice = VALUES(indice), integration = VALUES(integration), maj_par = VALUES(maj_par)',
                [$nom, $v, $secret ? 1 : 0, $secret ? (strlen($v) >= 8 ? '…' . substr($v, -4) : '…') : null, $portail, $integration, 'Cockpit']);
        }
    } catch (Throwable $e) { error_log('erp_cles ' . $nom . ' : ' . $e->getMessage()); }
    erpCle('');
}

/**
 * Le jeton de service entre portails (en-tête X-Service-Token), comparé à temps constant. Il n'ouvre que des
 * LECTURES déclarées pour les autres portails (erpRouteIntegration).
 */
function erpJetonServiceOk(): bool
{
    $h = (string) ($_SERVER['HTTP_X_SERVICE_TOKEN'] ?? '');
    $v = erpCle('erp_jeton_service');
    return $h !== '' && $v !== '' && hash_equals($v, $h);
}

/** Les routes que les autres portails lisent avec le jeton de service (GET seulement). */
function erpRouteIntegration(string $method, string $path): bool
{
    if ($method !== 'GET') { return false; }
    return in_array($path, ['/stores', '/stores/perf', '/exploitation/pro', '/marketing/avis', '/formation/ref',
        '/formation/v25', '/formation/referentiel', '/formation/procedure'], true);
}

/**
 * L'adresse publique de l'API du Cockpit (« https://<nom>/consulant_bo/api/cockpit »), publiée pour les autres
 * portails dans erp_cles (pil_api_url) : apprise d'une requête https sur un NOM de domaine (jamais une IP), une
 * fois qu'elle a passé l'authentification — un en-tête Host forgé ne peut rien détourner.
 */
function erpPublierAdresse(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (!$https) { return; }
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (!preg_match('/^([a-z0-9-]+(?:\.[a-z0-9-]+)+)(:\d{1,5})?$/', $host, $h) || filter_var($h[1], FILTER_VALIDATE_IP) || str_ends_with($h[1], '.localhost')) { return; }
    $chemin = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (!preg_match('#^(.*/api/cockpit)(?:/|$)#', $chemin, $m)) { return; }
    $url = 'https://' . $host . $m[1];
    if (erpCle('pil_api_url') !== $url) { erpCleEcrire('pil_api_url', $url, false, 'Adresses des portails'); }
}

/** Le compte TF Buddy de « Mon compte », publié pour les autres portails (clé commune). */
function erpPublierTfbuddy(array $panel): void
{
    foreach (['base' => ['tfbuddy_base', false], 'phone' => ['tfbuddy_phone', false], 'password' => ['tfbuddy_password', true]] as $k => [$nom, $secret]) {
        $v = trim((string) ($panel[$k] ?? ''));
        if ($v !== '' || $k !== 'password') { erpCleEcrire($nom, $v, $secret, 'TF Buddy'); }
    }
}

/** Appel en lecture d'une route d'intégration du Recrutement (rec_api_url + jeton de service), ou null. */
function erpLireRecrutement(string $ressource, int $delai = 8): ?array
{
    $base = rtrim(erpCle('rec_api_url'), '/');
    $tok = erpCle('erp_jeton_service');
    if ($base === '' || $tok === '' || !preg_match('#^https?://#i', $base)) { return null; }
    $url = $base . '/v1/integration/recrutement/' . $ressource;
    $essai = static function (string $u, array $opts = []) use ($tok, $delai): array {
        $ch = curl_init($u);
        curl_setopt_array($ch, $opts + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $delai, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Service-Token: ' . $tok]]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($raw) ? $raw : ''];
    };
    [$code, $raw] = $essai($url);
    // Même serveur : l'adresse publique peut ne pas répondre (NAT en épingle) — résolution forcée en local.
    if ($code === 0) {
        $p = parse_url($url);
        $hote = (string) ($p['host'] ?? '');
        $port = (int) ($p['port'] ?? (($p['scheme'] ?? '') === 'https' ? 443 : 80));
        if ($hote !== '' && !filter_var($hote, FILTER_VALIDATE_IP)) { [$code, $raw] = $essai($url, [CURLOPT_RESOLVE => ["$hote:$port:127.0.0.1"]]); }
    }
    $d = $code === 200 ? json_decode($raw, true) : null;
    if (!is_array($d)) { error_log("erp intégration recrutement $ressource : HTTP $code"); return null; }
    return $d;
}


/**
 * Plusieurs clés d'un portail avec leur date : [cle => ['valeur' => …, 'maj_le' => …]] ; null si la table manque
 * (une base sans table des clés n'est pas une base sans clés).
 */
function erpClesLire(array $noms, string $portail = 'commun'): ?array
{
    if ($noms === []) { return []; }
    try {
        $rows = Db::rows('SELECT cle, valeur, maj_le FROM erp_cles WHERE portail = ? AND cle IN ('
            . implode(', ', array_fill(0, count($noms), '?')) . ')', array_merge([$portail], array_values($noms)));
    } catch (Throwable $e) { return null; }
    $out = [];
    foreach ($rows as $r) { $out[(string) $r['cle']] = ['valeur' => trim((string) $r['valeur']), 'maj_le' => (string) ($r['maj_le'] ?? '')]; }
    return $out;
}

/**
 * Les clés dont le nom contient `$mot`, tous portails confondus, AVEC leurs valeurs : [{cle, portail, valeur}]. Pour
 * retrouver la clé d'une intégration rangée sous un nom qu'on ne connaît pas (le jeton SMSAPI du CRM, 20/11/2026).
 */
function erpClesCherche(string $mot): array
{
    try {
        return array_map(static fn ($r) => ['cle' => (string) $r['cle'], 'portail' => (string) $r['portail'], 'valeur' => trim((string) $r['valeur'])],
            Db::rows('SELECT cle, portail, valeur FROM erp_cles WHERE cle LIKE ? ORDER BY portail, cle', ['%' . $mot . '%']));
    } catch (Throwable $e) { return []; }
}

/** L'inventaire des clés SANS leurs valeurs : nom, portail, intégration, secret, indice, longueur, date de mise à jour. */
function erpClesInventaire(): array
{
    try {
        return Db::rows('SELECT cle, portail, integration, secret, indice, CHAR_LENGTH(valeur) AS longueur, maj_le FROM erp_cles ORDER BY portail, cle');
    } catch (Throwable $e) { return []; }
}

/**
 * Les réglages du cockpit dont des champs sont des clés d'intégration : ces champs vivent dans erp_cles (réorganisation,
 * étape 1), le reste du réglage dans ceo_app_setting. réglage → champ ('' = le réglage entier) → [clé, portail,
 * intégration, secret]. Les noms sont propres au cockpit (pil_…, mar_…) : une clé commune tenue par un autre portail
 * n'est jamais écrasée, et la priorité de lecture ne change pas. Seule exception, voulue par le code d'origine : le mot de
 * passe du compte TF Buddy, déjà gardé dans la clé commune tfbuddy_password (src/writes.php, wr_pwa_compte).
 */
const ERP_CLES_REGLAGES = [
    'anthropic' => ['cle' => ['pil_anthropic_api_key', 'pil', 'Assistance IA', true], 'espace' => ['pil_anthropic_workspace_id', 'pil', 'Assistance IA', false]],
    'google' => ['cle' => ['pil_google_places_api_key', 'pil', 'Google', true]],
    'panelApi' => ['base' => ['pil_tfbuddy_base', 'pil', 'TF Buddy', false], 'phone' => ['pil_tfbuddy_phone', 'pil', 'TF Buddy', false],
        'photoBase' => ['pil_tfbuddy_photo_base', 'pil', 'TF Buddy', false], 'password' => ['tfbuddy_password', 'commun', 'TF Buddy', true]],
    'erpAdmin' => ['base' => ['pil_erp_admin_base', 'pil', 'ERP (admin)', false], 'phone' => ['pil_erp_admin_phone', 'pil', 'ERP (admin)', false],
        'password' => ['pil_erp_admin_password', 'pil', 'ERP (admin)', true]],
    'smtp' => ['hote' => ['pil_smtp_host', 'pil', 'E-mail', false], 'port' => ['pil_smtp_port', 'pil', 'E-mail', false],
        'securite' => ['pil_smtp_secure', 'pil', 'E-mail', false], 'utilisateur' => ['pil_smtp_user', 'pil', 'E-mail', false],
        'motDePasse' => ['pil_smtp_pass', 'pil', 'E-mail', true], 'expediteur' => ['pil_smtp_from', 'pil', 'E-mail', false]],
    'pushVapid' => ['pem' => ['pil_push_vapid_pem', 'pil', 'Web Push', true], 'publique' => ['pil_push_vapid_public', 'pil', 'Web Push', false]],
    'mdMeta' => ['verify' => ['mar_meta_verify_token', 'mar', 'Meta (MétaDonnée)', true]],
    'mdMetaSecret' => ['' => ['mar_meta_app_secret', 'mar', 'Meta (MétaDonnée)', true]],
    'mdMetaJeton' => ['' => ['mar_meta_page_token', 'mar', 'Meta (MétaDonnée)', true]],
    'mdMetaJetonCrm' => ['jeton' => ['mar_meta_page_token_crm', 'mar', 'Meta (MétaDonnée)', true]],
    'mdSmsJeton' => ['' => ['mar_smsapi_token', 'mar', 'SMSAPI (MétaDonnée)', true]],
];

/** Les clés communes que les clés du cockpit répètent peut-être (signalées par la reprise, jamais fusionnées d'office). */
const ERP_CLES_DOUBLONS = [
    'pil_anthropic_api_key' => 'anthropic_api_key', 'pil_anthropic_workspace_id' => 'anthropic_workspace_id',
    'pil_tfbuddy_base' => 'tfbuddy_base', 'pil_tfbuddy_phone' => 'tfbuddy_phone', 'pil_smtp_host' => 'smtp_host',
    'pil_smtp_port' => 'smtp_port', 'pil_smtp_secure' => 'smtp_secure', 'pil_smtp_user' => 'smtp_user', 'pil_smtp_pass' => 'smtp_pass',
    'pil_smtp_from' => 'smtp_from', 'mar_meta_app_secret' => 'meta_app_secret', 'mar_meta_page_token' => 'meta_page_token',
];

/**
 * Le réglage tel que le code le lit : ses champs restés dans ceo_app_setting d'abord (un reste qu'une reprise n'a pas
 * pu ranger garde sa priorité d'avant), sinon ceux d'erp_cles. Appelé par setting().
 */
function erpClesReglage(string $key, mixed $val): mixed
{
    $carte = ERP_CLES_REGLAGES[$key] ?? null;
    if ($carte === null) { return $val; }
    if (isset($carte[''])) {
        if (is_string($val) && $val !== '') { return $val; }
        $v = erpCle($carte[''][0]);
        return $v !== '' ? $v : $val;
    }
    if ($val !== null && !is_array($val)) { return $val; }
    $out = is_array($val) ? $val : [];
    $trouve = false;
    foreach ($carte as $champ => [$nom]) {
        if (isset($out[$champ]) && (string) $out[$champ] !== '') { continue; }
        $v = erpCle($nom);
        if ($v !== '') { $out[$champ] = $v; $trouve = true; }
    }
    return ($trouve || is_array($val)) ? $out : $val;
}

/** Écrit une clé, et lève une exception si elle n'a pas pu l'être (vide = retirée). */
function erpCleEcrireSur(string $nom, string $valeur, bool $secret, string $integration, string $portail): void
{
    erpClesSchema();
    if (trim($valeur) === '') {
        Db::exec('DELETE FROM erp_cles WHERE cle = ?', [$nom]);
    } else {
        $v = trim($valeur);
        Db::exec('INSERT INTO erp_cles (cle, valeur, secret, indice, portail, integration, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                  ON DUPLICATE KEY UPDATE maj_le = IF(valeur <=> VALUES(valeur), maj_le, VALUES(maj_le)),
                      valeur = VALUES(valeur), secret = VALUES(secret), indice = VALUES(indice), integration = VALUES(integration), maj_par = VALUES(maj_par)',
            [$nom, $v, $secret ? 1 : 0, $secret ? (strlen($v) >= 8 ? '…' . substr($v, -4) : '…') : null, $portail, $integration, 'Cockpit']);
    }
    erpCle('');
}

/**
 * Écrit un réglage. Ses champs-clés (ERP_CLES_REGLAGES) vont dans erp_cles — un champ absent ou vide retire la clé —,
 * le reste dans ceo_app_setting. Si erp_cles refuse l'écriture, le champ reste dans le réglage : un secret n'est
 * jamais perdu.
 */
function settingEcrire(string $key, mixed $val): void
{
    $carte = ERP_CLES_REGLAGES[$key] ?? null;
    if ($carte !== null && isset($carte[''])) {
        [$nom, $portail, $integ, $secret] = $carte[''];
        try {
            erpCleEcrireSur($nom, is_scalar($val) ? (string) $val : '', $secret, $integ, $portail);
            Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [$key]);
            return;
        } catch (Throwable $e) { error_log('erp_cles ' . $nom . ' : ' . $e->getMessage()); }
    } elseif ($carte !== null && is_array($val)) {
        foreach ($carte as $champ => [$nom, $portail, $integ, $secret]) {
            $v = array_key_exists($champ, $val) ? (string) $val[$champ] : '';
            try { erpCleEcrireSur($nom, $v, $secret, $integ, $portail); unset($val[$champ]); }
            catch (Throwable $e) { error_log('erp_cles ' . $nom . ' : ' . $e->getMessage()); }
        }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        [$key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
}

/**
 * La reprise (réorganisation, étape 1) : les champs-clés encore dans ceo_app_setting passent dans erp_cles. Rejouable :
 * ce qui est déjà rangé ne bouge plus. Une clé déjà présente avec une AUTRE valeur n'est pas écrasée : le champ reste
 * dans le réglage (il garde sa priorité) et le conflit est noté. Rend les noms seulement, jamais les valeurs.
 */
function erpClesReprise(): array
{
    $bilan = ['rangees' => [], 'conflits' => [], 'doublons' => []];
    erpClesSchema();
    foreach (ERP_CLES_REGLAGES as $key => $carte) {
        $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]);
        if ($r === null) { continue; }
        $val = json_decode((string) $r['value'], true);
        $champs = isset($carte['']) ? ['' => $carte['']] : $carte;
        $reste = isset($carte['']) ? null : (is_array($val) ? $val : null);
        if ($reste === null && !isset($carte[''])) { continue; }
        $touche = false;
        foreach ($champs as $champ => [$nom, $portail, $integ, $secret]) {
            $v = $champ === '' ? (is_scalar($val) ? trim((string) $val) : '') : trim((string) ($reste[$champ] ?? ''));
            if ($v === '') { if ($champ !== '' && array_key_exists($champ, $reste)) { unset($reste[$champ]); $touche = true; } continue; }
            $deja = erpClesLire([$nom], $portail)[$nom]['valeur'] ?? '';
            if ($deja !== '' && $deja !== $v) { $bilan['conflits'][] = "$key.$champ → $nom"; continue; }
            erpCleEcrireSur($nom, $v, $secret, $integ, $portail);
            $bilan['rangees'][] = $champ === '' ? "$key → $nom" : "$key.$champ → $nom";
            if ($champ === '') { $val = null; } else { unset($reste[$champ]); }
            $touche = true;
        }
        if (!$touche) { continue; }
        if (isset($carte[''])) {
            if ($val === null) { Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', [$key]); }
        } else {
            Db::exec('UPDATE ceo_app_setting SET value = ? WHERE `key` = ?', [json_encode($reste, JSON_UNESCAPED_UNICODE), $key]);
        }
    }
    erpCle('');
    foreach (ERP_CLES_DOUBLONS as $nous => $commune) {
        $a = erpCle($nous); $b = erpCle($commune);
        if ($a !== '' && $b !== '') { $bilan['doublons'][] = $nous . ($a === $b ? ' = ' : ' ≠ ') . $commune; }
    }
    return $bilan;
}

/** Le retour arrière de la reprise : les champs-clés repartent dans ceo_app_setting et quittent erp_cles. */
function erpClesRendre(): array
{
    $rendues = [];
    foreach (ERP_CLES_REGLAGES as $key => $carte) {
        $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]);
        $val = $r === null ? null : json_decode((string) $r['value'], true);
        foreach ($carte as $champ => [$nom, $portail]) {
            if ($nom === 'tfbuddy_password') { continue; }   // clé commune déjà tenue avant la réorganisation : elle reste
            $v = erpClesLire([$nom], $portail)[$nom]['valeur'] ?? '';
            if ($v === '') { continue; }
            if ($champ === '') { $val = $v; } else { $val = is_array($val) ? $val : []; $val[$champ] = $v; }
            Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
            Db::exec('DELETE FROM erp_cles WHERE cle = ?', [$nom]);
            $rendues[] = $nom;
        }
    }
    erpCle('');
    return $rendues;
}

