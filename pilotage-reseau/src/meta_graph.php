<?php
declare(strict_types=1);

/**
 * Meta Graph API — les pages Facebook du réseau et leurs comptes Instagram.
 *
 * Brand Guard LIT : il résout une page, lit ses posts, reçoit ses webhooks.
 * Depuis le 13/10/2026, le portail Marketing PUBLIE aussi (src/social_publication.php) :
 * un post validé part sur la page Facebook et le compte Instagram du magasin, et
 * seulement sur son ordre — rien ne part d'ici de soi-même, rien n'est supprimé.
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
        // Les secrets : variable d'environnement d'abord, sinon le bloc `meta`
        // de config/config.php — hors Git, jamais écrasé par le déploiement,
        // là où vivent déjà le mot de passe de la base et la clé Google.
        $cfg = [];
        try { $cfg = (array) ((Db::config()['meta'] ?? []) ?: []); } catch (Throwable $e) { $cfg = []; }
        $env = static fn (string $k, string $c) => (string) (getenv($k) ?: ($cfg[$c] ?? ''));
        return [
            'version'      => trim((string) ($s['graphVersion'] ?? '')) ?: (trim((string) ($cfg['graphVersion'] ?? '')) ?: 'v21.0'),
            'systemToken'  => trim($env('META_SYSTEM_TOKEN', 'systemToken')),
            'appSecret'    => trim($env('META_APP_SECRET', 'appSecret')),
            'verifyToken'  => trim($env('META_VERIFY_TOKEN', 'verifyToken')),
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
        $nom = self::nomDePage($url);
        $token = $token ?? self::config()['systemToken'];
        // Sans token, l'identifiant se lit quand même : le module public de
        // Facebook (« Page Plugin ») rend le pageID d'une page publique. Les
        // posts, eux, attendent le System User — mais la page est déjà connue.
        if ($token === '') { return self::resoudrePagePublique($nom); }
        $r = self::http('GET', rawurlencode($nom), ['fields' => 'id,name', 'access_token' => $token]);
        if (empty($r['id'])) { throw new MetaIndisponible('page introuvable : ' . $nom); }
        return ['id' => (string) $r['id'], 'nom' => (string) ($r['name'] ?? $nom), 'source' => 'graph'];
    }

    /** Le nom d'utilisateur (ou l'identifiant) porté par une URL de page. */
    public static function nomDePage(string $url): string
    {
        $u = trim($url);
        $nom = null;
        if (preg_match('#[?&]id=(\d{5,})#', $u, $m)) { $nom = $m[1]; }
        elseif (preg_match('#facebook\.com/(?:pages/[^/]+/)?([^/?\#]+)#i', $u, $m)) { $nom = $m[1]; }
        elseif (preg_match('#^[\w.\-]+$#', $u)) { $nom = $u; }
        if ($nom === null || $nom === '' || $nom === 'XXXX') {
            throw new MetaIndisponible('URL de page illisible : ' . $url);
        }
        return $nom;
    }

    /**
     * L'identifiant d'une page publique sans token, par le Page Plugin
     * (`/plugins/page.php?href=…`), qui s'affiche sans session et porte
     * `"pageID":"…"`. Vérifié sur la page de Halle : 883508651512115.
     */
    public static function resoudrePagePublique(string $nom): array
    {
        $href = ctype_digit($nom) ? 'https://www.facebook.com/profile.php?id=' . $nom : 'https://www.facebook.com/' . rawurlencode($nom);
        // `tabs=timeline` est nécessaire : sans onglet, le module ne porte pas l'identifiant (mesuré).
        $url = 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($href) . '&tabs=timeline&width=340&locale=fr_FR';
        [$code, $html] = self::$transport
            ? (self::$transport)('GET-HTML', $url, [])
            : self::curlHtml($url);
        if ($code < 200 || $code >= 300 || !is_string($html)) {
            throw new MetaIndisponible('page publique injoignable (HTTP ' . $code . ') : ' . $nom);
        }
        if (!preg_match('/"pageID":"(\d{5,})"/', $html, $m)) {
            throw new MetaIndisponible('page introuvable ou non publique : ' . $nom);
        }
        $titre = preg_match('/"name":"([^"\\\\]{2,120})"/', $html, $t) ? json_decode('"' . $t[1] . '"') : null;
        return ['id' => $m[1], 'nom' => is_string($titre) && $titre !== '' ? $titre : $nom, 'source' => 'public'];
    }

    private static function curlHtml(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36']);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $code, $raw === false ? null : (string) $raw];
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

    /* --- publication (portail Marketing) ------------------------------------------ */

    /**
     * Publie sur une page Facebook, avec le token DE LA PAGE (tokensPages()).
     *
     *  - sans visuel : POST /{page}/feed {message, link?} ;
     *  - un visuel : POST /{page}/photos {url, caption} — Meta va chercher l'image à
     *    son adresse, elle doit donc être publique ;
     *  - plusieurs : chaque photo est déposée NON publiée, puis un seul post les
     *    réunit (POST /{page}/feed {message, attached_media[i]={"media_fbid":…}}).
     * Rend l'identifiant du post (page_postid) — celui que Brand Guard retrouvera en
     * relisant la page — et son lien permanent quand Meta le donne.
     *
     * @param list<string> $images adresses publiques des visuels
     * @return array{id:string, lien:?string}
     */
    public static function publierPage(string $pageId, string $token, string $message, ?string $lien, array $images): array
    {
        $images = array_values(array_filter($images, static fn ($u) => is_string($u) && preg_match('#^https?://#', $u)));
        if (count($images) === 1) {
            $r = self::http('POST', $pageId . '/photos', ['url' => $images[0], 'caption' => $message, 'access_token' => $token]);
            $id = (string) ($r['post_id'] ?? ($r['id'] ?? ''));
        } else {
            $p = ['message' => $message, 'access_token' => $token];
            if ($images) {
                foreach ($images as $i => $u) {
                    $ph = self::http('POST', $pageId . '/photos', ['url' => $u, 'published' => 'false', 'access_token' => $token]);
                    if (empty($ph['id'])) { throw new MetaIndisponible('photo ' . ($i + 1) . ' refusée par Meta'); }
                    $p['attached_media[' . $i . ']'] = json_encode(['media_fbid' => (string) $ph['id']]);
                }
            } elseif ($lien !== null && $lien !== '') {
                $p['link'] = $lien;
            }
            $r = self::http('POST', $pageId . '/feed', $p);
            $id = (string) ($r['id'] ?? '');
        }
        if ($id === '') { throw new MetaIndisponible('Meta n’a pas rendu d’identifiant de post'); }
        return ['id' => $id, 'lien' => self::permalien($id, $token, 'permalink_url')];
    }

    /** Le compte Instagram professionnel relié à une page, ou null s'il n'y en a pas. */
    public static function instagramDePage(string $pageId, string $token): ?string
    {
        $r = self::http('GET', $pageId, ['fields' => 'instagram_business_account', 'access_token' => $token]);
        $id = $r['instagram_business_account']['id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Publie sur un compte Instagram professionnel — en deux temps, comme l'impose Meta :
     * un conteneur (POST /{ig}/media), puis sa publication (POST /{ig}/media_publish).
     * Un carrousel (2 à 10 visuels) : un conteneur par visuel (is_carousel_item), puis
     * un conteneur CAROUSEL qui les réunit. Instagram n'accepte pas de post sans visuel,
     * et ses visuels doivent être des JPEG à une adresse publique.
     *
     * @param list<string> $images
     * @return array{id:string, lien:?string}
     */
    public static function publierInstagram(string $igId, string $token, string $legende, array $images): array
    {
        $images = array_values(array_filter($images, static fn ($u) => is_string($u) && preg_match('#^https?://#', $u)));
        if (!$images) { throw new MetaIndisponible('Instagram exige au moins un visuel à une adresse publique'); }
        if (count($images) === 1) {
            $c = self::http('POST', $igId . '/media', ['image_url' => $images[0], 'caption' => $legende, 'access_token' => $token]);
        } else {
            $enfants = [];
            foreach (array_slice($images, 0, 10) as $u) {
                $e = self::http('POST', $igId . '/media', ['image_url' => $u, 'is_carousel_item' => 'true', 'access_token' => $token]);
                if (empty($e['id'])) { throw new MetaIndisponible('visuel refusé par Instagram'); }
                $enfants[] = (string) $e['id'];
            }
            $c = self::http('POST', $igId . '/media', ['media_type' => 'CAROUSEL', 'children' => implode(',', $enfants), 'caption' => $legende, 'access_token' => $token]);
        }
        $conteneur = (string) ($c['id'] ?? '');
        if ($conteneur === '') { throw new MetaIndisponible('Instagram n’a pas créé le conteneur'); }
        // Un conteneur se prépare côté Meta (téléchargement du visuel) : on attend qu'il soit prêt.
        for ($i = 0; $i < 6; $i++) {
            $st = self::http('GET', $conteneur, ['fields' => 'status_code', 'access_token' => $token]);
            $code = (string) ($st['status_code'] ?? 'FINISHED');
            if ($code === 'FINISHED') { break; }
            if ($code === 'ERROR' || $code === 'EXPIRED') { throw new MetaIndisponible('Instagram a refusé le visuel (' . $code . ')'); }
            self::attendre(2);
        }
        $r = self::http('POST', $igId . '/media_publish', ['creation_id' => $conteneur, 'access_token' => $token]);
        $id = (string) ($r['id'] ?? '');
        if ($id === '') { throw new MetaIndisponible('Instagram n’a pas publié le conteneur'); }
        return ['id' => $id, 'lien' => self::permalien($id, $token, 'permalink')];
    }

    /** Le lien permanent d'un post publié — un plus, jamais une raison d'échouer. */
    private static function permalien(string $id, string $token, string $champ): ?string
    {
        try {
            $r = self::http('GET', $id, ['fields' => $champ, 'access_token' => $token]);
            return isset($r[$champ]) && is_string($r[$champ]) ? $r[$champ] : null;
        } catch (Throwable $e) { return null; }
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
