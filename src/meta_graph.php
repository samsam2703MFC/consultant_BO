<?php
declare(strict_types=1);

/**
 * Meta Graph API — lecture des pages Facebook du réseau.
 *
 * Le module est en LECTURE SEULE côté Meta : il résout une page, lit ses
 * posts, reçoit ses webhooks. Il ne publie ni ne supprime jamais rien.
 *
 * Trois règles tiennent l'accès :
 *
 *  1. un System User du Business Manager (token longue durée), jamais un token
 *     d'utilisateur personnel. Le token vit en variable d'environnement
 *     (`META_SYSTEM_TOKEN`), la base n'en garde que le NOM ;
 *  2. les tokens de page se demandent à Meta (`/me/accounts`) au moment de
 *     s'en servir — rien de secret ne dort en base ;
 *  3. tout appel réseau passe par UN point (`http`) : la version de l'API est un
 *     réglage, le code 190 (token expiré) se distingue d'une panne, et le rate
 *     limit se rejoue avec un délai qui double.
 *
 * `$transport` s'injecte pour les tests : un Graph simulé rend les mêmes
 * tableaux que le vrai, et la logique se vérifie sans réseau ni token.
 */
final class MetaTokenExpire extends RuntimeException {}
final class MetaIndisponible extends RuntimeException {}

final class MetaGraph
{
    /** @var null|callable(string $method, string $url, array $params): array{0:int,1:?array} */
    public static $transport = null;
    public static ?string $lastError = null;

    /** Réglages : version d'API (réglage), secrets (environnement, jamais la base). */
    public static function config(): array
    {
        $s = setting('brandGuard');
        if (!is_array($s)) { $s = []; }
        $env = static fn (string $k): string => (string) (getenv($k) ?: '');
        return [
            'version'      => trim((string) ($s['graphVersion'] ?? '')) ?: 'v21.0',
            'systemToken'  => $env('META_SYSTEM_TOKEN'),
            'appSecret'    => $env('META_APP_SECRET'),
            'verifyToken'  => $env('META_VERIFY_TOKEN'),
        ];
    }

    public static function configured(): bool
    {
        return self::config()['systemToken'] !== '';
    }

    /** État pour l'écran — l'existence des secrets, jamais leur valeur. */
    public static function statut(): array
    {
        $c = self::config();
        return ['version' => $c['version'], 'tokenDefini' => $c['systemToken'] !== '',
            'secretDefini' => $c['appSecret'] !== '', 'verifyDefini' => $c['verifyToken'] !== '',
            'configure' => self::configured()];
    }

    private static function base(): string
    {
        return 'https://graph.facebook.com/' . self::config()['version'];
    }

    /**
     * Un appel Graph, avec ses erreurs lues comme Meta les rend.
     *
     *  - 190 → token expiré ou révoqué : MetaTokenExpire, à traiter, pas à rejouer ;
     *  - 4, 17, 32, 613 et HTTP 429 → rate limit : rejoué jusqu'à quatre fois,
     *    délai doublé à chaque fois (1, 2, 4, 8 s) ;
     *  - le reste → MetaIndisponible, avec le message de Meta.
     */
    public static function http(string $method, string $path, array $params = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : self::base() . '/' . ltrim($path, '/');
        $delai = 1;
        for ($essai = 0; $essai < 5; $essai++) {
            [$code, $body] = self::$transport
                ? (self::$transport)($method, $url, $params)
                : self::curl($method, $url, $params);
            $err = is_array($body) ? ($body['error'] ?? null) : null;
            if ($code >= 200 && $code < 300 && $err === null) { return is_array($body) ? $body : []; }
            $ec = is_array($err) ? (int) ($err['code'] ?? 0) : 0;
            $msg = is_array($err) ? (string) ($err['message'] ?? '') : ('HTTP ' . $code);
            if ($ec === 190) { throw new MetaTokenExpire($msg); }
            if ($code === 429 || in_array($ec, [4, 17, 32, 613], true)) {
                if ($essai < 4) { self::attendre($delai); $delai *= 2; continue; }
                throw new MetaIndisponible('rate limit Meta : ' . $msg);
            }
            throw new MetaIndisponible($msg !== '' ? $msg : 'réponse illisible de Meta');
        }
        throw new MetaIndisponible('rate limit Meta');
    }

    /** Le délai d'attente, remplaçable par les tests pour ne pas dormir. */
    public static $attendre = null;
    private static function attendre(int $s): void
    {
        if (self::$attendre) { (self::$attendre)($s); return; }
        sleep($s);
    }

    private static function curl(string $method, string $url, array $params): array
    {
        $ch = curl_init();
        if ($method === 'GET') {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params)]);
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $e = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { self::$lastError = $e; return [0, null]; }
        return [$code, json_decode((string) $raw, true)];
    }

    /* --- pages ------------------------------------------------------------------ */

    /**
     * L'identifiant d'une page depuis son URL (ou son nom d'utilisateur).
     *
     * `https://www.facebook.com/latelierby.halle` → `GET /latelierby.halle?fields=id,name`.
     * Une URL numérique (`/profile.php?id=…` ou `/123456`) se lit telle quelle.
     */
    public static function resoudrePage(string $url, ?string $token = null): array
    {
        $u = trim($url);
        $nom = null;
        if (preg_match('#[?&]id=(\d{5,})#', $u, $m)) { $nom = $m[1]; }
        elseif (preg_match('#facebook\.com/(?:pages/[^/]+/)?([^/?\#]+)#i', $u, $m)) { $nom = $m[1]; }
        elseif (preg_match('#^[\w.\-]+$#', $u)) { $nom = $u; }
        if ($nom === null || $nom === '' || $nom === 'XXXX') {
            throw new MetaIndisponible('URL de page illisible : ' . $url);
        }
        $r = self::http('GET', rawurlencode($nom), [
            'fields' => 'id,name', 'access_token' => $token ?? self::config()['systemToken']]);
        if (empty($r['id'])) { throw new MetaIndisponible('page introuvable : ' . $nom); }
        return ['id' => (string) $r['id'], 'nom' => (string) ($r['name'] ?? $nom)];
    }

    /** Les tokens de page accessibles au System User : page_id → [token, nom]. */
    public static function tokensPages(): array
    {
        $out = [];
        $params = ['fields' => 'id,name,access_token', 'limit' => 100, 'access_token' => self::config()['systemToken']];
        $path = 'me/accounts';
        for ($p = 0; $p < 10; $p++) {
            $r = self::http('GET', $path, $params);
            foreach ($r['data'] ?? [] as $pg) {
                if (!empty($pg['id']) && !empty($pg['access_token'])) {
                    $out[(string) $pg['id']] = ['token' => (string) $pg['access_token'], 'nom' => (string) ($pg['name'] ?? '')];
                }
            }
            $next = $r['paging']['next'] ?? null;
            if (!is_string($next) || $next === '') { break; }
            $path = $next; $params = [];
        }
        return $out;
    }

    /* --- posts ------------------------------------------------------------------- */

    /**
     * Les posts récents d'une page : texte, date, lien, TOUTES les images
     * (carrousels compris), pagination suivie jusqu'à la borne de date.
     *
     * @return list<array{id:string,texte:string,images:list<string>,date:string,lien:?string}>
     */
    public static function posts(string $pageId, string $token, int $jours = 7, int $max = 200): array
    {
        $since = time() - $jours * 86400;
        $params = ['access_token' => $token, 'since' => $since, 'limit' => 50,
            'fields' => 'id,message,created_time,permalink_url,full_picture,attachments{media,subattachments{media}}'];
        $path = $pageId . '/posts';
        $out = [];
        for ($p = 0; $p < 20 && count($out) < $max; $p++) {
            $r = self::http('GET', $path, $params);
            foreach ($r['data'] ?? [] as $post) { $out[] = self::normaliser($post); }
            $next = $r['paging']['next'] ?? null;
            if (!is_string($next) || $next === '' || ($r['data'] ?? []) === []) { break; }
            $path = $next; $params = [];
        }
        return array_slice($out, 0, $max);
    }

    /** Un post tel que Meta le rend, ramené à ce que le contrôle lit. */
    public static function normaliser(array $p): array
    {
        $imgs = [];
        foreach ($p['attachments']['data'] ?? [] as $att) {
            $subs = $att['subattachments']['data'] ?? null;
            foreach (is_array($subs) && $subs ? $subs : [$att] as $sub) {
                $src = $sub['media']['image']['src'] ?? null;
                if (is_string($src) && $src !== '') { $imgs[] = $src; }
            }
        }
        if (!$imgs && !empty($p['full_picture'])) { $imgs[] = (string) $p['full_picture']; }
        $date = (string) ($p['created_time'] ?? '');
        $ts = $date !== '' ? strtotime($date) : false;
        return ['id' => (string) ($p['id'] ?? ''), 'texte' => (string) ($p['message'] ?? ''),
            'images' => array_values(array_unique($imgs)),
            'date' => $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'),
            'lien' => isset($p['permalink_url']) ? (string) $p['permalink_url'] : null];
    }

    /* --- webhook ----------------------------------------------------------------- */

    /**
     * La signature d'un webhook : `X-Hub-Signature-256: sha256=<hmac du corps brut>`.
     * Comparaison en temps constant ; sans secret configuré, rien ne passe.
     */
    public static function signatureOk(string $corps, ?string $entete, ?string $secret = null): bool
    {
        $secret = $secret ?? self::config()['appSecret'];
        if ($secret === '' || !is_string($entete) || !str_starts_with($entete, 'sha256=')) { return false; }
        $attendu = 'sha256=' . hash_hmac('sha256', $corps, $secret);
        return hash_equals($attendu, $entete);
    }

    /** Les identifiants de posts qu'un événement `feed` annonce comme ajoutés. */
    public static function postsDuWebhook(array $payload): array
    {
        $out = [];
        if (($payload['object'] ?? '') !== 'page') { return $out; }
        foreach ($payload['entry'] ?? [] as $entry) {
            $page = (string) ($entry['id'] ?? '');
            foreach ($entry['changes'] ?? [] as $ch) {
                if (($ch['field'] ?? '') !== 'feed') { continue; }
                $v = $ch['value'] ?? [];
                if (($v['item'] ?? '') !== 'post' && ($v['item'] ?? '') !== 'photo' && ($v['item'] ?? '') !== 'status') { continue; }
                if (($v['verb'] ?? '') !== 'add') { continue; }
                $pid = (string) ($v['post_id'] ?? '');
                if ($pid !== '') { $out[] = ['page' => $page, 'post' => $pid]; }
            }
        }
        return $out;
    }
}
