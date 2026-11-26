<?php
declare(strict_types=1);

/**
 * Le compte TF Buddy du magasin (25/11/2026). Sam : « all should work either with admin, consultant or shop token ».
 * Les routes du panel sous `/panel/shops/{shop}/…` (entreprises B2B, bureaux, analyse B2B, livraisons) refusent les
 * comptes consultant et admin du cockpit (404 ORDER_NOT_FOUND, mesuré le 24/11/2026) et répondent à un employé du
 * magasin connecté au panel. Le dashboard propose donc cette connexion, celle du panel lui-même : téléphone, PIN, code
 * reçu par SMS (POST /panel/auth/login, /panel/auth/confirm), une session de 12 heures, un jeton d'accès de quelques
 * minutes renouvelé par /panel/auth/refresh.
 *
 * Rien n'est gardé au cockpit (comme l'écran /task, consultant/src/task_cadre.php) : la session vit dans le navigateur du
 * magasin, qui envoie son jeton d'accès dans l'en-tête `X-TFB-Jeton` ; le serveur l'impose à PanelApi le temps d'un
 * appel (PanelApi::$jetonImpose) et le rend tel quel. Jamais journalisé.
 *
 *   POST /dashboard/tfb/connexion   {shop, phone, pin}         → {etape: "sms", challenge} | {etape: "ok", jetons}
 *   POST /dashboard/tfb/confirmer   {shop, challenge, code}    → {jetons: {acces, renouvellement, expireAcces, expireSession}}
 *   POST /dashboard/tfb/sms         {shop, challenge}          → renvoyer le code
 *   POST /dashboard/tfb/renouveler  {shop, renouvellement}     → {jetons}
 *   POST /dashboard/tfb/deconnexion {shop, renouvellement}     → {ok}
 */

const TFBM_ENTETE = 'HTTP_X_TFB_JETON';

/** Le jeton d'accès du magasin envoyé par le navigateur (en-tête X-TFB-Jeton), s'il a la forme d'un jeton. */
function tfbmJeton(): ?string
{
    $j = trim((string) ($_SERVER[TFBM_ENTETE] ?? ''));
    return preg_match('/^[A-Za-z0-9._~+\/=\-]{20,4096}$/', $j) ? $j : null;
}

/** L'échéance (timestamp) d'un JWT, lue dans sa charge utile sans la vérifier (TF Buddy la vérifie) ; null sinon. */
function tfbmExpiration(string $jwt): ?int
{
    $p = explode('.', $jwt);
    if (count($p) !== 3) { return null; }
    $c = json_decode((string) base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4), true), true);
    return is_array($c) && isset($c['exp']) && is_numeric($c['exp']) ? (int) $c['exp'] : null;
}

/** Les jetons d'une réponse de connexion ou de renouvellement du panel, dans la forme du dashboard ; null sans jeton. */
function tfbmJetons($c): ?array
{
    $c = is_array($c) ? $c : [];
    $d = is_array($c['data'] ?? null) ? $c['data'] : [];
    $acces = $c['token'] ?? $c['access_token'] ?? $d['token'] ?? $d['access_token'] ?? null;
    if (!is_string($acces) || $acces === '') { return null; }
    $ren = $c['refresh_token'] ?? $d['refresh_token'] ?? null;
    $ses = $c['expires_at'] ?? $d['expires_at'] ?? null;
    $ses = is_numeric($ses) ? (int) $ses : (is_string($ses) && strtotime($ses) ? strtotime($ses) : null);
    return ['acces' => $acces, 'renouvellement' => is_string($ren) ? $ren : null,
        'expireAcces' => tfbmExpiration($acces) ?? time() + 240, 'expireSession' => $ses];
}

/** Un POST sans jeton vers les routes de connexion du panel : [code, corps]. */
function tfbmPost(string $chemin, array $corps): array
{
    if (!class_exists('PanelApi')) { return [0, null]; }
    $ch = curl_init(PanelApi::config()['base'] . $chemin);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($corps, JSON_UNESCAPED_UNICODE)]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$raw === false ? 0 : $code, $raw === false ? null : json_decode((string) $raw, true)];
}

/** Le code d'erreur de TF Buddy d'une réponse (`error`, sinon `description`, `message`). */
function tfbmCodeErreur($c): string
{
    return is_array($c) ? (string) ($c['error'] ?? $c['description'] ?? $c['message'] ?? '') : '';
}

/**
 * Le message d'un refus de connexion, lisible au magasin, avec le code de TF Buddy entre parenthèses. Les limites sont
 * celles de TF Buddy (spécification de /panel/auth/login et /resend, 25/11/2026), pas du cockpit : `PANEL_AUTH_LIMIT`
 * (trop d'essais par téléphone et par adresse IP — tous les magasins se connectent depuis l'adresse du serveur du
 * cockpit), `PANEL_AUTH_RESEND_WAIT` (60 s entre deux SMS), un code valable 5 minutes et 5 essais.
 */
function tfbmMotif(int $code, $c): string
{
    $e = tfbmCodeErreur($c);
    $lib = ['PANEL_INVALID_CREDENTIALS' => 'téléphone ou PIN incorrect, ou accès inactif', 'INVALID_CREDENTIALS' => 'téléphone ou PIN incorrect, ou accès inactif',
        'PANEL_AUTH_INPUT' => 'numéro de téléphone invalide : au format international (+32…, +48…)',
        'PANEL_CODE_INVALID' => 'code SMS incorrect, expiré ou déjà utilisé (5 minutes, 5 essais) : recommencez si besoin', 'PANEL_SMS_CODE_INVALID' => 'code SMS incorrect',
        'PANEL_SESSION_EXPIRED' => 'la session TF Buddy a expiré : reconnectez-vous',
        'PANEL_AUTH_RESEND_WAIT' => 'un code SMS vient d’être envoyé : saisissez-le ; un nouveau code n’est possible qu’après 60 secondes',
        'PANEL_AUTH_LIMIT' => 'TF Buddy bloque les connexions pour quelques minutes (trop d’essais pour ce téléphone ou depuis le serveur du cockpit) : c’est une limite de TF Buddy, pas du dashboard',
        'PANEL_SMS_UNAVAILABLE' => 'TF Buddy n’arrive pas à envoyer le SMS pour le moment', 'PANEL_AUTH_UNAVAILABLE' => 'la connexion TF Buddy est indisponible pour le moment'][$e] ?? null;
    if ($lib === null && $code === 429) { $lib = 'TF Buddy limite les connexions pour le moment : c’est une limite de TF Buddy, pas du dashboard'; }
    if ($lib !== null) { return $lib . ($e !== '' ? ' (TF Buddy : ' . $e . ')' : ' (TF Buddy : HTTP ' . $code . ')'); }
    return $code === 0 ? 'TF Buddy injoignable' : 'TF Buddy refuse (HTTP ' . $code . ($e !== '' ? ' : ' . mb_substr($e, 0, 120) : '') . ')';
}

/**
 * Un appel à TF Buddy avec le jeton du magasin, s'il est là : {code, corps, erreur, compte: "magasin"} ; null sans jeton.
 * Le jeton est imposé à PanelApi le temps de l'appel ; périmé (401), PanelApi ne retombe pas sur un autre compte.
 */
function tfbmAppel(string $methode, string $chemin, ?array $corps = null, int $timeout = 25): ?array
{
    $j = tfbmJeton();
    if ($j === null || !class_exists('PanelApi') || !property_exists('PanelApi', 'jetonImpose')) { return null; }
    $avant = PanelApi::$jetonImpose;
    PanelApi::$jetonImpose = $j;
    try {
        if ($methode === 'GET') { $r = PanelApi::sondeGet($chemin, $timeout); return ['code' => (int) ($r['code'] ?? 0), 'corps' => $r['corps'] ?? null, 'erreur' => $r['erreur'] ?? null, 'compte' => 'magasin']; }
        $r = PanelApi::requete($methode, $chemin, $corps, $timeout);
        return ['code' => (int) ($r['code'] ?? 0), 'corps' => $r['data'] ?? null, 'erreur' => $r['erreur'] ?? null, 'compte' => 'magasin'];
    } finally {
        PanelApi::$jetonImpose = $avant;
    }
}

/* ── Les routes de connexion ─────────────────────────────────────────────────────────────────────────────────── */

function wr_tfb_connexion(): array
{
    $b = body();
    $tel = preg_replace('/[^\d+]/', '', (string) ($b['phone'] ?? ''));
    $pin = preg_replace('/\D/', '', (string) ($b['pin'] ?? ''));
    if (strlen($tel) < 8 || strlen($pin) < 4) { http_response_code(422); return ['error' => 'le téléphone (avec l’indicatif, +32…) et le PIN sont attendus']; }
    [$code, $c] = tfbmPost('/panel/auth/login', ['phone' => $tel, 'pin' => $pin]);
    if ($code === 200 && ($j = tfbmJetons($c)) !== null) { return ['etape' => 'ok', 'jetons' => $j]; }
    $ch = is_array($c) ? ($c['challenge'] ?? $c['data']['challenge'] ?? null) : null;
    if ($code >= 200 && $code < 300 && is_string($ch) && $ch !== '') {
        return ['etape' => 'sms', 'challenge' => $ch, 'texte' => 'Un code a été envoyé par SMS au ' . substr($tel, 0, 4) . '•••' . substr($tel, -2) . '.'];
    }
    // Pas de pause ajoutée ici : les limites d'essais sont celles de TF Buddy. `attente` : un code SMS est déjà parti
    // (PANEL_AUTH_RESEND_WAIT) ; le navigateur reprend le code en cours au lieu de redemander une connexion.
    http_response_code($code === 0 ? 502 : ($code === 429 ? 429 : 401));
    return ['error' => tfbmMotif($code, $c), 'code' => tfbmCodeErreur($c) ?: null, 'attente' => tfbmCodeErreur($c) === 'PANEL_AUTH_RESEND_WAIT'];
}

function wr_tfb_confirmer(): array
{
    $b = body();
    $ch = trim((string) ($b['challenge'] ?? ''));
    $sms = preg_replace('/\D/', '', (string) ($b['code'] ?? ''));
    if ($ch === '' || strlen($sms) < 4) { http_response_code(422); return ['error' => 'le code reçu par SMS est attendu']; }
    [$code, $c] = tfbmPost('/panel/auth/confirm', ['challenge' => $ch, 'code' => $sms]);
    if ($code >= 200 && $code < 300 && ($j = tfbmJetons($c)) !== null) {
        if (function_exists('journalAdd')) { journalAdd('dashboard', 'tfbuddy', null, 'Magasin ' . (int) ($b['shop'] ?? 0) . ' : connexion au panel TF Buddy depuis le dashboard'); }
        return ['ok' => true, 'jetons' => $j];
    }
    http_response_code($code === 0 ? 502 : ($code === 429 ? 429 : 401));
    return ['error' => tfbmMotif($code, $c), 'code' => tfbmCodeErreur($c) ?: null];
}

function wr_tfb_sms(): array
{
    $ch = trim((string) (body()['challenge'] ?? ''));
    if ($ch === '') { http_response_code(422); return ['error' => 'challenge manquant']; }
    [$code, $c] = tfbmPost('/panel/auth/resend', ['challenge' => $ch]);
    if ($code >= 200 && $code < 300) { return ['ok' => true]; }
    http_response_code($code === 0 ? 502 : ($code === 429 ? 429 : 422));
    return ['error' => tfbmMotif($code, $c), 'code' => tfbmCodeErreur($c) ?: null];
}

function wr_tfb_renouveler(): array
{
    $r = trim((string) (body()['renouvellement'] ?? ''));
    if ($r === '') { http_response_code(422); return ['error' => 'jeton de renouvellement manquant']; }
    [$code, $c] = tfbmPost('/panel/auth/refresh', ['refresh_token' => $r]);
    if ($code >= 200 && $code < 300 && ($j = tfbmJetons($c)) !== null) {
        if ($j['renouvellement'] === null) { $j['renouvellement'] = $r; }
        return ['ok' => true, 'jetons' => $j];
    }
    http_response_code($code === 0 ? 502 : 401);
    return ['error' => tfbmMotif($code, $c), 'expire' => $code !== 0];
}

function wr_tfb_deconnexion(): array
{
    $r = trim((string) (body()['renouvellement'] ?? ''));
    if ($r !== '') { tfbmPost('/panel/auth/logout', ['refresh_token' => $r]); }
    return ['ok' => true];
}

function tfbmRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/dashboard/tfb/')) { return null; }
    if ($method === 'POST') {
        switch ($path) {
            case '/dashboard/tfb/connexion': return wr_tfb_connexion();
            case '/dashboard/tfb/confirmer': return wr_tfb_confirmer();
            case '/dashboard/tfb/sms': return wr_tfb_sms();
            case '/dashboard/tfb/renouveler': return wr_tfb_renouveler();
            case '/dashboard/tfb/deconnexion': return wr_tfb_deconnexion();
        }
    }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
