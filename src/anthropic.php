<?php
declare(strict_types=1);

/**
 * Assistance à la notation — API Anthropic.
 *
 * Le consultant note une photo de comptoir contre un attendu. Claude peut
 * PROPOSER un niveau et un commentaire ; il ne note jamais. Trois règles
 * tiennent cette limite :
 *
 *  1. la proposition n'écrit rien. Elle pré-remplit le formulaire, le
 *     consultant valide ou corrige. Une note automatique dans un dossier
 *     qualité engage la franchise sans que personne ne l'ait regardée ;
 *  2. la clé ne quitte jamais le serveur. Elle vit dans `ceo_app_setting`,
 *     hors dépôt, et le navigateur ne reçoit que le résultat ;
 *  3. la réponse est CONTRAINTE au barème réel du réseau (les cinq niveaux du
 *     réglage `signalement`). Un modèle qui inventerait « Correct » produirait
 *     une note hors barème, impossible à comparer aux autres.
 */
final class Anthropic
{
    public static ?string $lastError = null;

    /** Réglages : clé, modèle. Jamais la clé en clair vers l'écran. */
    public static function config(): array
    {
        $s = setting('anthropic');
        if (!is_array($s)) { $s = []; }
        $env = static fn (string $k): string => (string) (getenv($k) ?: '');
        $cle = trim((string) ($s['cle'] ?? '')) ?: $env('ANTHROPIC_API_KEY');
        return [
            'cle'    => $cle,
            'modele' => trim((string) ($s['modele'] ?? '')) ?: 'claude-sonnet-5',
            'actif'  => !empty($s['actif']) || $cle !== '',
        ];
    }

    public static function configured(): bool
    {
        return self::config()['cle'] !== '';
    }

    /** État pour l'écran Paramètres — jamais la clé, seulement son existence. */
    public static function statut(): array
    {
        $c = self::config();
        return ['modele' => $c['modele'], 'cleDefinie' => $c['cle'] !== '',
            'configure' => self::configured(),
            'empreinte' => $c['cle'] !== '' ? substr($c['cle'], 0, 7) . '…' . substr($c['cle'], -4) : null];
    }

    /**
     * Propose un niveau pour une photo de tâche.
     *
     * @param string      $imageUrl  URL signée de la photo prise en boutique
     * @param string|null $refUrl    photo de référence (fiche technique), si elle existe
     * @param array       $niveaux   barème réel : [['n'=>5,'nom'=>…,'aide'=>…], …]
     * @return array{niveau:?int,nom:?string,commentaire:?string,constats:array,confiance:?string,erreur:?string}
     */
    public static function noterPhoto(string $tache, string $imageUrl, ?string $refUrl,
                                      array $niveaux, string $contexte = ''): array
    {
        $vide = ['niveau' => null, 'nom' => null, 'commentaire' => null,
            'constats' => [], 'confiance' => null, 'erreur' => null, 'modele' => null];
        $c = self::config();
        if ($c['cle'] === '') { return $vide + [] ; }

        [$img, $mime] = self::telecharger($imageUrl);
        if ($img === null) {
            $vide['erreur'] = 'photo illisible : ' . (self::$lastError ?? 'téléchargement impossible');
            return $vide;
        }
        $ref = null; $refMime = null;
        if ($refUrl !== null && $refUrl !== '') { [$ref, $refMime] = self::telecharger($refUrl); }

        $bareme = [];
        foreach ($niveaux as $n) {
            $bareme[] = '- ' . (int) $n['n'] . ' = ' . (string) $n['nom']
                . (!empty($n['aide']) ? ' (' . $n['aide'] . ')' : '');
        }

        $contenu = [];
        if ($ref !== null) {
            $contenu[] = ['type' => 'text', 'text' => "Photo de RÉFÉRENCE (l'attendu) :"];
            $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64',
                'media_type' => $refMime, 'data' => base64_encode($ref)]];
        }
        $contenu[] = ['type' => 'text', 'text' => 'Photo PRISE EN BOUTIQUE (à évaluer) :'];
        $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64',
            'media_type' => $mime, 'data' => base64_encode($img)]];
        $contenu[] = ['type' => 'text', 'text' =>
            "Tâche contrôlée : « " . $tache . " »."
            . ($contexte !== '' ? "\nContexte : " . $contexte : '')
            . "\n\nBarème du réseau, à utiliser TEL QUEL :\n" . implode("\n", $bareme)
            . "\n\nRéponds en JSON strict, sans texte autour :\n"
            . '{"niveau": <entier du barème>, "constats": ["…", "…"], '
            . '"commentaire": "<deux phrases maximum, à l\'attention du magasin>", '
            . '"confiance": "haute|moyenne|faible"}'
            . "\n\nRègles : ne juge que ce qui est VISIBLE sur la photo. Si la photo ne permet pas"
            . " de conclure (cadrage, flou, sujet absent), mets \"confiance\": \"faible\" et dis-le"
            . " dans les constats plutôt que de deviner. N'invente aucun niveau hors barème."];

        $corps = [
            'model' => $c['modele'],
            'max_tokens' => 700,
            'system' => "Tu assistes un consultant de réseau de boulangeries qui contrôle la qualité"
                . " en boutique. Tu PROPOSES une évaluation, tu ne la valides pas : un humain relit"
                . " et corrige. Sois factuel et bref, en français. Aucune flatterie, aucun préambule.",
            'messages' => [['role' => 'user', 'content' => $contenu]],
        ];

        [$code, $rep] = self::http($corps, $c['cle']);
        if ($code < 200 || $code >= 300) {
            $det = '';
            if (is_array($rep)) {
                $det = (string) ($rep['error']['message'] ?? json_encode($rep, JSON_UNESCAPED_UNICODE));
            }
            $vide['erreur'] = 'API Anthropic → HTTP ' . $code . ($det !== '' ? ' : ' . substr($det, 0, 240) : '');
            return $vide;
        }

        $txt = '';
        foreach (($rep['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') { $txt .= (string) ($b['text'] ?? ''); }
        }
        // Le modèle peut encadrer son JSON de ```json … ``` : on prend le bloc.
        if (preg_match('/\{.*\}/s', $txt, $m)) { $txt = $m[0]; }
        $j = json_decode($txt, true);
        if (!is_array($j)) {
            $vide['erreur'] = 'réponse non exploitable du modèle';
            return $vide;
        }
        // Le niveau est RAMENÉ au barème : hors barème, il est refusé, pas arrondi.
        $n = isset($j['niveau']) ? (int) $j['niveau'] : 0;
        $ok = null;
        foreach ($niveaux as $x) { if ((int) $x['n'] === $n) { $ok = $x; break; } }
        return [
            'niveau'      => $ok ? $n : null,
            'nom'         => $ok ? (string) $ok['nom'] : null,
            'commentaire' => isset($j['commentaire']) ? trim((string) $j['commentaire']) : null,
            'constats'    => array_values(array_filter(array_map(
                fn ($v) => trim((string) $v), (array) ($j['constats'] ?? [])))),
            'confiance'   => in_array($j['confiance'] ?? '', ['haute', 'moyenne', 'faible'], true)
                ? (string) $j['confiance'] : null,
            'erreur'      => $ok ? null : 'le modèle a rendu un niveau hors barème',
            'modele'      => $c['modele'],
        ];
    }

    /** POST /v1/messages. La clé part en en-tête, jamais dans une URL ni un log. */
    private static function http(array $corps, string $cle): array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $cle,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($corps, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { self::$lastError = $err; return [0, null]; }
        return [$code, json_decode((string) $raw, true)];
    }

    /** Télécharge une image et rend [octets, type MIME]. */
    private static function telecharger(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            self::$lastError = 'HTTP ' . $code;
            return [null, null];
        }
        // L'API n'accepte qu'une courte liste de types : on normalise plutôt que
        // d'envoyer un « application/octet-stream » qui serait refusé.
        $mime = 'image/jpeg';
        foreach (['image/png' => 'image/png', 'image/webp' => 'image/webp',
                  'image/gif' => 'image/gif', 'image/jpeg' => 'image/jpeg'] as $k => $v) {
            if (stripos($ct, $k) !== false) { $mime = $v; break; }
        }
        // 5 Mo : au-delà l'appel échouerait côté API, autant le dire ici.
        if (strlen((string) $raw) > 5 * 1024 * 1024) {
            self::$lastError = 'photo trop lourde (' . round(strlen((string) $raw) / 1048576, 1) . ' Mo)';
            return [null, null];
        }
        return [(string) $raw, $mime];
    }
}
