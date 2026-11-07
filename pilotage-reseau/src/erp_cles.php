<?php

declare(strict_types=1);

/**
 * erp_cles — la table des clés commune à tout l'ERP (décision du 7 nov. 2026 : « les clés sont dans une table
 * commune pour tout l'ERP ; à part les clés, tous les portails doivent être indépendants »). Elle remplace le
 * projet `master_variable_keys` du registre. Une ligne par clé : cle, valeur, secret, indice, portail
 * (`commun` = intégration partagée ; `rec` / `mar` / `pil` / `frm` = propre à un portail), integration,
 * maj_le, maj_par. Même définition dans les quatre portails (CREATE TABLE IF NOT EXISTS).
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

/** Une clé (en clair), '' si absente. Lues une fois par requête. */
function erpCle(string $nom): string
{
    static $toutes = null;
    if ($nom === '') { $toutes = null; return ''; }
    if ($toutes === null) {
        $toutes = [];
        try {
            foreach (Db::rows("SELECT cle, valeur FROM erp_cles WHERE portail IN ('commun', 'pil')") as $r) {
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
