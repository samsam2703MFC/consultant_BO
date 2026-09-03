<?php
declare(strict_types=1);

/**
 * Connecteur Google — la réputation des fiches de magasin.
 *
 * Deux API existent pour lire des avis Google, et le choix n'est pas neutre :
 *
 *  - **Business Profile API** : les avis complets, et la possibilité d'y
 *    répondre. Elle exige OAuth2 avec un compte propriétaire des fiches, un
 *    jeton de rafraîchissement à entretenir, et une inscription approuvée par
 *    Google. Ce n'est pas une clé, c'est un dossier ;
 *  - **Places API (New)** : une simple clé serveur, la note, le nombre total
 *    d'avis, le lien de la fiche, et jusqu'à CINQ avis. C'est exactement ce que
 *    l'écran affiche.
 *
 * Le cockpit prend la seconde. Ce qu'elle ne sait pas faire est dit franchement
 * plutôt que masqué :
 *
 *  - elle ne rend jamais plus de cinq avis, et l'historique complet n'est pas
 *    accessible. Les avis sont donc ACCUMULÉS chez nous à chaque
 *    synchronisation (clé d'unicité `external_id`) : Google fait tourner sa
 *    sélection, nous gardons ce qui est passé ;
 *  - elle ne dit pas si le magasin a répondu à un avis. `replied_at` reste donc
 *    nul, et l'écran n'affiche « Répondu » que lorsqu'il le sait — il ne
 *    prétend jamais « Sans réponse », ce qui serait une affirmation fausse.
 *
 * Le scouting commercial (notes des boulangeries concurrentes) passe par le même
 * connecteur et la même clé : le serveur interroge Places, l'écran ne saisit rien.
 *
 * La clé ne quitte jamais le serveur (même règle que la clé Anthropic et que le
 * compte ERP) : elle vit dans `ceo_app_setting`, et l'écran n'en reçoit qu'une
 * empreinte.
 */
final class GoogleApi
{
    public static ?string $lastError = null;

    private const BASE = 'https://places.googleapis.com/v1';

    /** Champs demandés à Google — facturés à la sélection, donc explicites. */
    private const CHAMPS_LIEU = 'id,displayName,formattedAddress,rating,userRatingCount,googleMapsUri,reviews';
    private const CHAMPS_RECHERCHE = 'places.id,places.displayName,places.formattedAddress,places.rating,places.userRatingCount';
    private const CHAMPS_NOTE = 'places.displayName,places.rating,places.userRatingCount';

    public static function config(): array
    {
        $s = setting('google');
        if (!is_array($s)) { $s = []; }
        $cle = trim((string) ($s['cle'] ?? ''));
        if ($cle === '') { $cle = (string) (Db::config()['googleApiKey'] ?? ''); }
        if ($cle === '') { $cle = (string) (getenv('GOOGLE_API_KEY') ?: ''); }
        return ['cle' => $cle, 'langue' => trim((string) ($s['langue'] ?? '')) ?: 'fr'];
    }

    public static function configured(): bool
    {
        return self::config()['cle'] !== '';
    }

    /** État pour l'écran — jamais la clé, seulement son existence. */
    public static function statut(): array
    {
        $c = self::config();
        return ['configure' => $c['cle'] !== '', 'langue' => $c['langue'],
            'empreinte' => $c['cle'] !== '' ? substr($c['cle'], 0, 6) . '…' . substr($c['cle'], -4) : null];
    }

    /** La fiche d'un lieu : note, nombre d'avis, lien, et jusqu'à cinq avis. */
    public static function lieu(string $placeId): ?array
    {
        if ($placeId === '') { self::$lastError = 'identifiant de fiche vide'; return null; }
        $c = self::config();
        if ($c['cle'] === '') { self::$lastError = 'clé Google absente'; return null; }
        [$code, $json] = self::http('GET', self::BASE . '/places/' . rawurlencode($placeId)
            . '?languageCode=' . rawurlencode($c['langue']), self::CHAMPS_LIEU, $c['cle'], null);
        if ($code !== 200 || !is_array($json)) { self::$lastError = self::erreur($code, $json); return null; }
        return googleLieuNormalise($json);
    }

    /** Recherche textuelle — les candidats proposés pour raccorder une fiche. */
    public static function chercher(string $texte): ?array
    {
        $texte = trim($texte);
        if ($texte === '') { self::$lastError = 'recherche vide'; return null; }
        $c = self::config();
        if ($c['cle'] === '') { self::$lastError = 'clé Google absente'; return null; }
        [$code, $json] = self::http('POST', self::BASE . '/places:searchText', self::CHAMPS_RECHERCHE, $c['cle'],
            ['textQuery' => $texte, 'languageCode' => $c['langue'], 'maxResultCount' => 8]);
        if ($code !== 200 || !is_array($json)) { self::$lastError = self::erreur($code, $json); return null; }
        return googleCandidats($json);
    }

    /**
     * La note d'un commerce désigné par son nom et sa position — le scouting
     * commercial enrichit ainsi les boulangeries OpenStreetMap. Un seul
     * résultat, biaisé vers la position : deux enseignes homonymes dans deux
     * villes ne se confondent pas. Note nulle = fiche trouvée sans note, ou
     * aucune fiche ; `null` = Google n'a pas répondu (voir $lastError).
     */
    public static function noteProche(string $texte, float $lat, float $lng, int $rayon = 600): ?array
    {
        $texte = trim($texte);
        if ($texte === '') { self::$lastError = 'recherche vide'; return null; }
        $c = self::config();
        if ($c['cle'] === '') { self::$lastError = 'clé Google absente'; return null; }
        [$code, $json] = self::http('POST', self::BASE . '/places:searchText', self::CHAMPS_NOTE, $c['cle'], [
            'textQuery' => $texte, 'languageCode' => $c['langue'], 'regionCode' => 'BE', 'maxResultCount' => 1,
            'locationBias' => ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng],
                'radius' => (float) max(50, min(50000, $rayon))]],
        ]);
        if ($code !== 200 || !is_array($json)) { self::$lastError = self::erreur($code, $json); return null; }
        $p = (array) (($json['places'] ?? [[]])[0] ?? []);
        return [
            'note' => isset($p['rating']) ? round((float) $p['rating'], 1) : null,
            'avis' => (int) ($p['userRatingCount'] ?? 0),
            'nom'  => $p['displayName']['text'] ?? null,
        ];
    }

    /** La clé part en en-tête, jamais dans l'URL : les URL se retrouvent en logs. */
    private static function http(string $methode, string $url, string $champs, string $cle, ?array $corps): array
    {
        $ch = curl_init($url);
        $entetes = ['X-Goog-Api-Key: ' . $cle, 'X-Goog-FieldMask: ' . $champs];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8];
        if ($methode === 'POST') {
            $entetes[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($corps ?? [], JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $entetes;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { self::$lastError = $err ?: 'appel impossible'; return [0, null]; }
        return [$code, json_decode((string) $raw, true)];
    }

    /** Le message de Google plutôt qu'un « HTTP 403 » muet. */
    private static function erreur(int $code, mixed $json): string
    {
        $m = is_array($json) ? ($json['error']['message'] ?? null) : null;
        return 'HTTP ' . $code . ($m ? ' — ' . $m : '');
    }
}

/**
 * La fiche Google, ramenée à ce que le cockpit stocke.
 *
 * Fonction pure : c'est elle qui porte la forme de la réponse Google, donc
 * c'est elle qui se teste. Le client, lui, ne fait qu'un appel HTTP.
 */
function googleLieuNormalise(array $json): array
{
    $avis = [];
    foreach ((array) ($json['reviews'] ?? []) as $r) {
        // `name` = « places/X/reviews/Y » : stable d'un appel à l'autre, c'est
        // notre clé d'unicité. Sans lui, chaque synchronisation dupliquerait.
        $id = trim((string) ($r['name'] ?? ''));
        if ($id === '') { continue; }
        $texte = $r['text']['text'] ?? ($r['originalText']['text'] ?? null);
        $quand = (string) ($r['publishTime'] ?? '');
        $avis[] = [
            'externalId' => $id,
            'auteur' => $r['authorAttribution']['displayName'] ?? null,
            'note' => (int) ($r['rating'] ?? 0),
            'texte' => $texte !== null && trim((string) $texte) !== '' ? trim((string) $texte) : null,
            // Google date en RFC 3339 (UTC) ; MySQL veut « Y-m-d H:i:s ».
            'le' => $quand !== '' ? gmdate('Y-m-d H:i:s', strtotime($quand) ?: time()) : gmdate('Y-m-d H:i:s'),
        ];
    }
    return [
        'placeId' => (string) ($json['id'] ?? ''),
        'nom' => $json['displayName']['text'] ?? null,
        'adresse' => $json['formattedAddress'] ?? null,
        'note' => isset($json['rating']) ? round((float) $json['rating'], 2) : null,
        'avis' => (int) ($json['userRatingCount'] ?? 0),
        'url' => $json['googleMapsUri'] ?? null,
        'derniers' => $avis,
    ];
}

/** Les candidats d'une recherche, dans l'ordre rendu par Google. */
function googleCandidats(array $json): array
{
    $out = [];
    foreach ((array) ($json['places'] ?? []) as $p) {
        $id = trim((string) ($p['id'] ?? ''));
        if ($id === '') { continue; }
        $out[] = [
            'placeId' => $id,
            'nom' => $p['displayName']['text'] ?? $id,
            'adresse' => $p['formattedAddress'] ?? '',
            'note' => isset($p['rating']) ? round((float) $p['rating'], 2) : null,
            'avis' => (int) ($p['userRatingCount'] ?? 0),
        ];
    }
    return $out;
}
