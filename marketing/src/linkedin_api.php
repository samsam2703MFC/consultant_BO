<?php
declare(strict_types=1);

/**
 * LinkedIn — publier au nom de la page entreprise de la marque (portail Marketing, 13/10/2026).
 *
 * L'API « Posts » (REST, versionnée) : POST /rest/posts au nom d'une organisation
 * (`urn:li:organization:{id}`), avec un jeton d'un administrateur de la page qui
 * porte le droit `w_organization_social` (produit « Community Management API » de
 * l'application LinkedIn). Un visuel se dépose d'abord : POST /rest/images?action=
 * initializeUpload → une adresse de dépôt et un `urn:li:image:…`, PUT des octets,
 * puis le post le cite.
 *
 * Comme pour Meta :
 *  - le JETON est un secret : variable d'environnement `LINKEDIN_ACCESS_TOKEN`, ou le
 *    bloc `linkedin` de config/config.php (hors Git). Jamais en base, jamais au
 *    navigateur. Un jeton LinkedIn vit 60 jours : `expireLe` (config) permet de le
 *    dire à l'écran avant qu'il ne tombe ;
 *  - l'ORGANISATION et la VERSION d'API ne sont pas secrètes : réglage du portail
 *    (`socialPublication`), sinon la config. LinkedIn date ses versions (AAAAMM) et
 *    retire les plus anciennes : par défaut, celle d'il y a deux mois ;
 *  - tout passe par `http()` : 401 = jeton expiré ou révoqué, 403 = droit manquant,
 *    429 = rejoué avec un délai qui double ; `$transport` s'injecte pour les tests.
 */
final class LinkedInTokenExpire extends RuntimeException {}
final class LinkedInIndisponible extends RuntimeException {}

final class LinkedInApi
{
    /** @var null|callable(string $methode, string $url, list<string> $entetes, ?string $corps): array{0:int,1:array<string,string>,2:string} */
    public static $transport = null;
    /** @var null|callable(int): void */
    public static $attendre = null;

    public static function config(): array
    {
        $s = function_exists('setting') ? setting('socialPublication') : null;
        if (!is_array($s)) { $s = []; }
        $cfg = [];
        try { $cfg = (array) ((Db::config()['linkedin'] ?? []) ?: []); } catch (Throwable $e) { $cfg = []; }
        return [
            'jeton' => trim((string) (getenv('LINKEDIN_ACCESS_TOKEN') ?: ($cfg['accessToken'] ?? ''))),
            'expireLe' => trim((string) ($cfg['expireLe'] ?? '')) ?: null,
            'organisation' => preg_replace('/\D/', '', (string) (($s['linkedinOrganisation'] ?? '') ?: ($cfg['organisation'] ?? ''))),
            'version' => preg_match('/^\d{6}$/', (string) ($s['linkedinVersion'] ?? '')) ? (string) $s['linkedinVersion']
                : (preg_match('/^\d{6}$/', (string) ($cfg['version'] ?? '')) ? (string) $cfg['version'] : date('Ym', strtotime('first day of -2 months'))),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['jeton'] !== '' && $c['organisation'] !== '';
    }

    /** État pour l'écran — l'existence du jeton, jamais sa valeur. */
    public static function statut(): array
    {
        $c = self::config();
        $jours = $c['expireLe'] !== null ? (int) floor((strtotime($c['expireLe'] . ' 23:59:59') - time()) / 86400) : null;
        return ['jetonDefini' => $c['jeton'] !== '', 'organisation' => $c['organisation'] ?: null, 'version' => $c['version'],
            'expireLe' => $c['expireLe'], 'joursRestants' => $jours, 'configure' => self::configured()];
    }

    /** L'auteur des posts : la page entreprise. */
    public static function auteur(): string
    {
        return 'urn:li:organization:' . self::config()['organisation'];
    }

    /**
     * Un appel REST. `$corps` : tableau (JSON) ou chaîne brute (le dépôt d'une image).
     * @return array{code:int, entetes:array<string,string>, json:?array}
     */
    public static function http(string $methode, string $url, array|string|null $corps = null, bool $rest = true): array
    {
        $c = self::config();
        if ($c['jeton'] === '') { throw new LinkedInIndisponible('jeton LinkedIn absent (LINKEDIN_ACCESS_TOKEN)'); }
        $url = str_starts_with($url, 'http') ? $url : 'https://api.linkedin.com/rest/' . ltrim($url, '/');
        $entetes = ['Authorization: Bearer ' . $c['jeton']];
        if ($rest) {
            $entetes[] = 'LinkedIn-Version: ' . $c['version'];
            $entetes[] = 'X-Restli-Protocol-Version: 2.0.0';
        }
        $brut = null;
        if (is_array($corps)) { $brut = json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $entetes[] = 'Content-Type: application/json'; }
        elseif (is_string($corps)) { $brut = $corps; $entetes[] = 'Content-Type: application/octet-stream'; }
        $delai = 1;
        for ($essai = 0; $essai < 5; $essai++) {
            [$code, $h, $txt] = self::$transport ? (self::$transport)($methode, $url, $entetes, $brut) : self::curl($methode, $url, $entetes, $brut);
            $json = $txt !== '' ? json_decode($txt, true) : null;
            if ($code >= 200 && $code < 300) { return ['code' => $code, 'entetes' => $h, 'json' => is_array($json) ? $json : null]; }
            $msg = is_array($json) ? (string) ($json['message'] ?? '') : '';
            if ($code === 401) { throw new LinkedInTokenExpire('jeton LinkedIn refusé (expiré ou révoqué)' . ($msg !== '' ? ' : ' . $msg : '')); }
            if ($code === 429 && $essai < 4) { self::pause($delai); $delai *= 2; continue; }
            if ($code === 403) { throw new LinkedInIndisponible('droit refusé par LinkedIn — le jeton doit porter w_organization_social, au nom d’un administrateur de la page' . ($msg !== '' ? ' (' . $msg . ')' : '')); }
            throw new LinkedInIndisponible(($msg !== '' ? $msg : 'réponse HTTP ' . $code) . ' (LinkedIn)');
        }
        throw new LinkedInIndisponible('LinkedIn limite les appels — réessayez plus tard');
    }

    private static function pause(int $s): void
    {
        if (self::$attendre) { (self::$attendre)($s); return; }
        sleep($s);
    }

    private static function curl(string $methode, string $url, array $entetes, ?string $corps): array
    {
        $h = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($c, $ligne) use (&$h) {
                $p = strpos($ligne, ':');
                if ($p !== false) { $h[strtolower(trim(substr($ligne, 0, $p)))] = trim(substr($ligne, $p + 1)); }
                return strlen($ligne);
            }]);
        if ($corps !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $corps); }
        $txt = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$txt === false ? 0 : $code, $h, $txt === false ? '' : (string) $txt];
    }

    /** Dépose un visuel (ses octets) et rend son `urn:li:image:…`. */
    public static function deposerImage(string $octets): string
    {
        $r = self::http('POST', 'images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => self::auteur()]]);
        $v = $r['json']['value'] ?? [];
        $adresse = (string) ($v['uploadUrl'] ?? ''); $urn = (string) ($v['image'] ?? '');
        if ($adresse === '' || $urn === '') { throw new LinkedInIndisponible('LinkedIn n’a pas ouvert de dépôt pour le visuel'); }
        self::http('PUT', $adresse, $octets, false);
        return $urn;
    }

    /**
     * Publie au nom de la page. Sans visuel : un texte, avec l'aperçu du lien s'il y en a un ;
     * un visuel : `media` ; plusieurs (2 à 20) : `multiImage`.
     *
     * @param list<array{octets:string, alt:?string}> $images
     * @return array{id:string, lien:string}
     */
    public static function publier(string $texte, array $images, ?string $lien, ?string $titreLien = null): array
    {
        $post = ['author' => self::auteur(), 'commentary' => $texte, 'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
        $media = [];
        foreach (array_slice($images, 0, 20) as $im) {
            $media[] = array_filter(['id' => self::deposerImage($im['octets']), 'altText' => $im['alt'] ?? null], static fn ($v) => $v !== null && $v !== '');
        }
        if (count($media) === 1) { $post['content'] = ['media' => $media[0]]; }
        elseif (count($media) > 1) { $post['content'] = ['multiImage' => ['images' => $media]]; }
        elseif ($lien !== null && $lien !== '') {
            $post['content'] = ['article' => ['source' => $lien, 'title' => mb_substr($titreLien ?: (string) (parse_url($lien, PHP_URL_HOST) ?: $lien), 0, 200)]];
        }
        $r = self::http('POST', 'posts', $post);
        $id = (string) ($r['entetes']['x-restli-id'] ?? ($r['json']['id'] ?? ''));
        if ($id === '') { throw new LinkedInIndisponible('LinkedIn n’a pas rendu l’identifiant du post'); }
        return ['id' => $id, 'lien' => 'https://www.linkedin.com/feed/update/' . $id . '/'];
    }

    /** « Est-ce que ça marche ? » : la page se lit-elle avec ce jeton ? */
    public static function tester(): array
    {
        $c = self::config();
        if ($c['jeton'] === '') { return [false, 'jeton absent (LINKEDIN_ACCESS_TOKEN ou config linkedin.accessToken)']; }
        if ($c['organisation'] === '') { return [false, 'identifiant de la page entreprise à renseigner (Paramètres → Publication)']; }
        try {
            $r = self::http('GET', 'organizations/' . $c['organisation']);
            $nom = (string) ($r['json']['localizedName'] ?? '');
            return [true, 'page lue' . ($nom !== '' ? ' : ' . $nom : '') . ' · version ' . $c['version']];
        } catch (LinkedInIndisponible $e) {
            // Lire la page demande un autre droit (r_organization_admin) que publier : un 403 ici
            // ne dit pas que la publication échouera — l'écran le dit tel quel.
            return [false, $e->getMessage()];
        } catch (Throwable $e) { return [false, $e->getMessage()]; }
    }
}
