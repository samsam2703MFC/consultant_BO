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

    /**
     * Le verdict Brand Guard : un post (texte + jusqu'à dix images) jugé
     * contre la charte, en sortie STRUCTURÉE.
     *
     * Trois choix : la charte part en `system` avec `cache_control` — elle est
     * longue, identique d'un post à l'autre, elle ne se repaie pas à chaque
     * appel ; le verdict est FORCÉ par un outil (`tool_choice`), donc jamais
     * lu dans du texte libre ; et la règle de décision n'est pas ici — le
     * code appelant la réapplique sur les écarts rendus.
     *
     * @param list<string|array{data:string,mime:string}> $images  URL http(s) ou octets
     * @return array  le verdict de l'outil (+ `modele`), ou ['erreur' => …]
     */
    public static function verdict(string $system, string $texte, array $images, string $boutique = ''): array
    {
        $c = self::config();
        if ($c['cle'] === '') { return ['erreur' => 'clé Anthropic absente']; }
        $contenu = [];
        foreach (array_slice($images, 0, 10) as $img) {
            if (is_array($img) && isset($img['data'])) {
                $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64',
                    'media_type' => (string) ($img['mime'] ?? 'image/jpeg'), 'data' => base64_encode((string) $img['data'])]];
            } elseif (is_string($img) && preg_match('#^https?://#i', $img)) {
                $contenu[] = ['type' => 'image', 'source' => ['type' => 'url', 'url' => $img]];
            }
        }
        $contenu[] = ['type' => 'text', 'text' => 'Boutique : ' . ($boutique !== '' ? $boutique : '(non précisée)')
            . "\nTexte du post :\n" . ($texte !== '' ? $texte : '(aucun texte)')];
        $outil = [
            'name' => 'verdict',
            'description' => "Rend le verdict de conformité d'un post à la charte.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'statut' => ['type' => 'string', 'enum' => ['conforme', 'a_corriger', 'bloque']],
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'ecarts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'regle' => ['type' => 'string', 'description' => 'Code de la règle de la charte, tel quel'],
                        'gravite' => ['type' => 'string', 'enum' => ['bloquant', 'majeur', 'mineur']],
                        'constat' => ['type' => 'string'],
                        'correction' => ['type' => 'string', 'description' => 'Consigne claire et exécutable pour le franchisé'],
                    ], 'required' => ['regle', 'gravite', 'constat', 'correction']]],
                    'message_franchise' => ['type' => 'string', 'description' => '2-3 phrases directes adressées au franchisé'],
                ],
                'required' => ['statut', 'score', 'ecarts', 'message_franchise'],
            ],
        ];
        $corps = [
            'model' => $c['modele'],
            'max_tokens' => 1500,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => [$outil],
            'tool_choice' => ['type' => 'tool', 'name' => 'verdict'],
            'messages' => [['role' => 'user', 'content' => $contenu]],
        ];
        [$code, $rep] = self::http($corps, $c['cle']);
        if ($code < 200 || $code >= 300) {
            $det = is_array($rep) ? (string) ($rep['error']['message'] ?? json_encode($rep, JSON_UNESCAPED_UNICODE)) : '';
            return ['erreur' => 'API Anthropic → HTTP ' . $code . ($det !== '' ? ' : ' . substr($det, 0, 240) : '')];
        }
        foreach (($rep['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'tool_use' && is_array($b['input'] ?? null)) {
                return $b['input'] + ['modele' => $c['modele']];
            }
        }
        return ['erreur' => 'le modèle n’a pas rendu de verdict structuré'];
    }

    /* -----------------------------------------------------------------------------
       Benchmark concurrents (14/11/2026) : lire les prix d'un concurrent, dans un PDF
       (tarif, folder, carte des fêtes, scan) ou sur internet (recherche web). Le modèle
       RELÈVE ce qui est écrit ; le rapprochement avec nos produits, les comparaisons et
       l'écriture dans le benchmark sont calculés par le cockpit (src/benchmark_import.php),
       et rien n'est écrit sans le clic « Encoder ».
       ----------------------------------------------------------------------------- */

    /** Le modèle des relevés : réglage `anthropic.modeleBenchmark`, sinon Claude Opus 5.5. */
    public static function modeleBenchmark(): string
    {
        $s = setting('anthropic');
        return is_array($s) && trim((string) ($s['modeleBenchmark'] ?? '')) !== '' ? trim((string) $s['modeleBenchmark']) : 'claude-opus-5-5';
    }

    /** Le schéma d'un relevé : le même pour un PDF et pour la recherche web. Tout champ est requis, l'absence vaut null. */
    public static function schemaReleve(bool $avecSources = false): array
    {
        $txt = ['type' => 'string'];
        $num = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];
        $ent = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
        $ligne = ['type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'rubrique' => $txt + ['description' => 'Titre de la rubrique où la ligne apparaît (« Viennoiseries », « Tartes »), vide sinon'],
                'libelle' => $txt + ['description' => 'Le nom du produit tel qu’il est écrit'],
                'prix' => $num + ['description' => 'Prix TTC en euros tel qu’affiché (le prix du lot pour un lot), null si illisible'],
                'prix_promo' => $num + ['description' => 'Prix promotionnel en euros, null sans promotion'],
                'promo_texte' => $txt + ['description' => 'Le texte de la promotion (« 4 + 1 offert », « −20 % le mercredi »), vide sinon'],
                'poids_g' => $num + ['description' => 'Poids en grammes s’il est écrit (1000 pour un prix au kilo), null sinon'],
                'diametre_cm' => $num + ['description' => 'Diamètre en cm s’il est écrit, null sinon'],
                'pieces' => $ent + ['description' => 'Nombre de pièces du lot (« les 6 » → 6), null pour une pièce seule'],
                'parts' => $ent + ['description' => 'Nombre de parts ou de personnes (« 6 pers. » → 6), null sinon'],
                'page' => $ent + ['description' => 'Page du PDF, null pour une page web'],
                'source_url' => $txt + ['description' => 'L’adresse de la page où le prix est vu (recherche web), vide pour un PDF'],
            ],
            'required' => ['rubrique', 'libelle', 'prix', 'prix_promo', 'promo_texte', 'poids_g', 'diametre_cm', 'pieces', 'parts', 'page', 'source_url']];
        $s = ['type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'concurrent' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'nom' => $txt, 'type' => ['type' => 'string', 'enum' => ['artisan', 'chaine', 'supermarche', 'autre']],
                        'adresse' => $txt + ['description' => 'Rue et numéro, vide si inconnu'], 'code_postal' => $txt + ['description' => 'Code postal belge à 4 chiffres, vide si inconnu'],
                        'ville' => $txt, 'site' => $txt + ['description' => 'Site web ou webshop, vide si inconnu']],
                    'required' => ['nom', 'type', 'adresse', 'code_postal', 'ville', 'site']],
                'date_document' => $txt + ['description' => 'Date de validité ou d’édition du tarif au format AAAA-MM-JJ, vide si aucune'],
                'lignes' => ['type' => 'array', 'items' => $ligne],
                'remarques' => $txt + ['description' => 'Ce qui n’a pas pu être lu ou trouvé, en une ou deux phrases'],
            ],
            'required' => ['concurrent', 'date_document', 'lignes', 'remarques']];
        if ($avecSources) {
            $s['properties']['sources'] = ['type' => 'array', 'items' => $txt, 'description' => 'Les adresses des pages lues'];
            $s['required'][] = 'sources';
        }
        return $s;
    }

    private const RELEVE_SYSTEME = "Tu relèves les prix d'un concurrent pour le réseau de boulangeries-pâtisseries L'Atelier By (Belgique)."
        . " Tu recopies ce qui est écrit, tu n'inventes rien : un prix absent ou illisible vaut null, une information inconnue vaut une chaîne vide."
        . "\nRègles :\n- une ligne par produit et par prix ; un même produit en deux formats fait deux lignes ;"
        . "\n- le prix est le prix TTC affiché, en euros ; pour un lot (« les 6 pistolets 4,20 € »), le prix du lot et pieces = 6 ;"
        . "\n- un prix au kilo : prix pour 1 kg et poids_g = 1000 ; un poids, un diamètre, des parts écrits dans le libellé se recopient aussi dans leur champ ;"
        . "\n- une promotion : prix = prix normal s'il est écrit (sinon null), prix_promo = prix promotionnel, promo_texte = la mécanique ;"
        . "\n- les lignes qui ne sont pas des produits (conditions de commande, livraison, horaires) ne se relèvent pas : dis-le dans remarques ;"
        . "\n- une ligne de prix illisible se garde avec prix null et le libellé tel qu'il se devine.";

    /**
     * Le relevé d'un PDF : le document part tel quel (texte ou scan, le modèle lit les deux),
     * la réponse est contrainte au schéma (sortie structurée).
     *
     * @return array{releve:?array, erreur:?string, modele:string, usage:?array}
     */
    public static function releveDepuisPdf(string $octets, string $contexte = ''): array
    {
        $c = self::config();
        $modele = self::modeleBenchmark();
        if ($c['cle'] === '') { return ['releve' => null, 'erreur' => 'clé Anthropic absente (Paramètres › IA)', 'modele' => $modele, 'usage' => null]; }
        $corps = [
            'model' => $modele,
            'max_tokens' => 16000,
            'system' => self::RELEVE_SYSTEME,
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::schemaReleve(false)]],
            'fallbacks' => 'default',
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($octets)]],
                ['type' => 'text', 'text' => 'Relève tous les prix de ce document.' . ($contexte !== '' ? "\nContexte : " . $contexte : '')
                    . "\nLe concurrent est celui qui édite le document (en-tête, pied de page, cachet)."],
            ]]],
        ];
        [$code, $rep] = self::http($corps, $c['cle'], ['anthropic-beta: server-side-fallback-2026-07-01'], 240);
        $err = self::erreurReponse($code, $rep);
        if ($err !== null) { return ['releve' => null, 'erreur' => $err, 'modele' => $modele, 'usage' => is_array($rep) ? ($rep['usage'] ?? null) : null]; }
        $txt = '';
        foreach ((array) ($rep['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') { $txt .= (string) ($b['text'] ?? ''); } }
        $j = json_decode($txt, true);
        return is_array($j) ? ['releve' => $j, 'erreur' => null, 'modele' => (string) ($rep['model'] ?? $modele), 'usage' => $rep['usage'] ?? null]
            : ['releve' => null, 'erreur' => 'réponse non exploitable du modèle', 'modele' => $modele, 'usage' => $rep['usage'] ?? null];
    }

    /**
     * Le relevé par recherche web : le modèle cherche et lit les pages (outils serveur
     * web_search et web_fetch), puis rend son relevé par l'outil `enregistrer_releve`
     * (schéma strict). Un tour mis en pause (`pause_turn`) se reprend tel quel ; un tour
     * fini sans l'outil reçoit une seule relance.
     *
     * @return array{releve:?array, erreur:?string, modele:string, usage:?array}
     */
    public static function releveDepuisWeb(string $demande, string $ville = ''): array
    {
        $c = self::config();
        $modele = self::modeleBenchmark();
        if ($c['cle'] === '') { return ['releve' => null, 'erreur' => 'clé Anthropic absente (Paramètres › IA)', 'modele' => $modele, 'usage' => null]; }
        $lieu = ['type' => 'approximate', 'country' => 'BE', 'timezone' => 'Europe/Brussels'] + ($ville !== '' ? ['city' => mb_substr($ville, 0, 60)] : []);
        $outils = [
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 6, 'user_location' => $lieu],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 8],
            ['name' => 'enregistrer_releve', 'description' => 'Enregistre le relevé des prix trouvés. À appeler une fois, à la fin, même sans aucun prix trouvé (lignes vides).',
                'strict' => true, 'input_schema' => self::schemaReleve(true)],
        ];
        $messages = [['role' => 'user', 'content' => $demande
            . "\n\nCherche les prix que ce concurrent publie lui-même : son site, son webshop (Bakeronline, Bestelonline…), sa page Facebook, une carte en ligne."
            . " Relève uniquement des prix vus sur une page, avec son adresse dans source_url. Relève aussi son adresse et son code postal."
            . " Termine en appelant l'outil enregistrer_releve."]];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $relance = false;
        for ($tour = 0; $tour < 6; $tour++) {
            $corps = ['model' => $modele, 'max_tokens' => 16000, 'system' => self::RELEVE_SYSTEME, 'tools' => $outils, 'tool_choice' => ['type' => 'auto'],
                'output_config' => ['effort' => 'medium'], 'fallbacks' => 'default', 'messages' => $messages];
            [$code, $rep] = self::http($corps, $c['cle'], ['anthropic-beta: server-side-fallback-2026-07-01'], 240);
            foreach (['input_tokens', 'output_tokens'] as $k) { $usage[$k] += (int) (is_array($rep) ? ($rep['usage'][$k] ?? 0) : 0); }
            $err = self::erreurReponse($code, $rep);
            if ($err !== null) { return ['releve' => null, 'erreur' => $err, 'modele' => $modele, 'usage' => $usage]; }
            foreach ((array) ($rep['content'] ?? []) as $b) {
                if (($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'enregistrer_releve' && is_array($b['input'] ?? null)) {
                    return ['releve' => $b['input'], 'erreur' => null, 'modele' => (string) ($rep['model'] ?? $modele), 'usage' => $usage];
                }
            }
            // La réponse entière repart : blocs de réflexion, recherches et pages lues compris.
            $messages[] = ['role' => 'assistant', 'content' => $rep['content'] ?? []];
            $arret = (string) ($rep['stop_reason'] ?? '');
            if ($arret === 'pause_turn') { continue; }
            if ($relance || $arret === 'max_tokens') { break; }
            $relance = true;
            $messages[] = ['role' => 'user', 'content' => 'Appelle maintenant l’outil enregistrer_releve avec ce que tu as trouvé (lignes vides si aucun prix n’est publié).'];
        }
        return ['releve' => null, 'erreur' => 'le modèle n’a pas rendu de relevé', 'modele' => $modele, 'usage' => $usage];
    }

    /** Une réponse en échec, un refus ou une coupure : le message à afficher ; null si la réponse est bonne. */
    private static function erreurReponse(int $code, mixed $rep): ?string
    {
        if ($code < 200 || $code >= 300) {
            if ($code === 0) { return 'API Anthropic injoignable : ' . (self::$lastError ?? 'pas de réponse'); }
            $det = is_array($rep) ? (string) ($rep['error']['message'] ?? json_encode($rep, JSON_UNESCAPED_UNICODE)) : '';
            return 'API Anthropic → HTTP ' . $code . ($det !== '' ? ' : ' . mb_substr($det, 0, 240) : '');
        }
        if (!is_array($rep)) { return 'réponse illisible de l’API Anthropic'; }
        $arret = (string) ($rep['stop_reason'] ?? '');
        if ($arret === 'refusal') { return 'le modèle a refusé la demande' . (!empty($rep['stop_details']['explanation']) ? ' : ' . $rep['stop_details']['explanation'] : ''); }
        if ($arret === 'max_tokens' && !array_filter((array) ($rep['content'] ?? []), fn ($b) => ($b['type'] ?? '') === 'tool_use')) {
            $texte = array_filter((array) ($rep['content'] ?? []), fn ($b) => ($b['type'] ?? '') === 'text');
            if ($texte !== [] && json_decode((string) (array_values($texte)[0]['text'] ?? ''), true) === null) { return 'relevé trop long, coupé : découpez le PDF'; }
        }
        return null;
    }

    /** Les essais remplacent l'appel réseau par cette fonction : fn(array $corps, array $entetes): [code, réponse]. */
    public static ?Closure $transport = null;

    /** POST /v1/messages. La clé part en en-tête, jamais dans une URL ni un log. */
    private static function http(array $corps, string $cle, array $entetes = [], int $delai = 90): array
    {
        if (self::$transport !== null) { return (self::$transport)($corps, $entetes); }
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge([
                'Content-Type: application/json',
                'x-api-key: ' . $cle,
                'anthropic-version: 2023-06-01',
            ], $entetes),
            CURLOPT_POSTFIELDS     => json_encode($corps, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => $delai,
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
