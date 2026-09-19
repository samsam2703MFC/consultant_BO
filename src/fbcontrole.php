<?php
declare(strict_types=1);

/**
 * Cockpit CEO — l'agent de contrôle des posts Facebook.
 *
 * Un franchisé soumet un post ; l'agent le relit AVANT publication et rend
 * deux choses : une liste d'écarts (règle, famille, type, gravité, extrait) et
 * une note de 1 à 5. Le CEO tranche ensuite — l'agent ne publie ni ne refuse
 * jamais seul, il prépare la décision.
 *
 * Trois choix de fond :
 *
 *  - Le moteur est DÉTERMINISTE et local : des règles lisibles, aucun appel
 *    réseau, aucun modèle de langue. Un refus doit pouvoir être justifié au
 *    franchisé règle par règle, et donner deux fois le même verdict sur le
 *    même texte.
 *  - Les règles sont un RÉGLAGE (`ceo_app_setting.fbControle`), pas des
 *    constantes : la charte du réseau bouge plus souvent que le code. Le seuil,
 *    les gravités, les mots déclencheurs et le lexique s'éditent par
 *    `PUT /parametres/fbControle`.
 *  - L'échelle de notes est CELLE des tâches consultants (réglage
 *    `signalement`) : « majeur » doit vouloir dire la même chose pour un
 *    livrable de consultant et pour un post de franchisé, sinon les deux
 *    suivis ne s'additionnent pas.
 */

/** Le pack de règles en service — réglage d'abord, défaut livré ensuite. */
function fbRegles(): array
{
    $c = setting('fbControle', null);
    if (!is_array($c) || !isset($c['regles'])) {
        return fbReglesDefaut();
    }
    // Un pack partiel (édité à la main, ou écrit par une version antérieure)
    // ne doit pas faire tomber l'agent : on complète par le défaut.
    return $c + fbReglesDefaut();
}

/** Le seuil de publication — sous cette note, le post repart au franchisé. */
function fbSeuil(): int
{
    $r = fbRegles();
    $s = isset($r['seuil']) ? (int) $r['seuil'] : 4;
    return $s >= 1 && $s <= 5 ? $s : 4;
}

/**
 * Les règles livrées.
 *
 * Chaque règle porte sa gravité (1 mineur, 2 majeur, 3 critique) et ses
 * paramètres. Rien n'est codé en dur dans les fonctions de contrôle : elles
 * lisent `params`, pour qu'un seuil se change sans déploiement.
 */
function fbReglesDefaut(): array
{
    $marque = "L'Atelier by";
    return [
        'seuil'    => 4,
        'familles' => [
            ['nom' => 'Charte de marque', 'types' => ['Marque absente', 'Casse de marque', 'Hashtag de marque absent', 'Magasin non identifié', 'Ton non conforme']],
            ['nom' => 'Mentions légales', 'types' => ['Conditions de promotion absentes', 'Règlement de concours absent', 'Allégation santé', 'Superlatif non justifié']],
            ['nom' => 'Rédaction',        'types' => ['Faute de langue', 'Message trop court', 'Message trop long', 'Majuscules excessives', 'Ponctuation excessive', 'Hashtags excessifs']],
            ['nom' => 'Visuel',           'types' => ['Visuel absent', 'Texte alternatif absent', 'Résolution insuffisante']],
            ['nom' => 'Diffusion',        'types' => ['Créneau interdit', 'Lien non sécurisé', 'Domaine non autorisé', 'Publication dupliquée']],
            ['nom' => 'Autre',            'types' => ['À préciser']],
        ],
        'regles' => [
            ['code' => 'marque-absente', 'nom' => 'Marque citée', 'famille' => 'Charte de marque', 'type' => 'Marque absente', 'gravite' => 3, 'actif' => true,
                'aide' => 'Le post doit nommer la marque du réseau.', 'params' => ['marque' => $marque]],
            ['code' => 'marque-casse', 'nom' => 'Casse officielle de la marque', 'famille' => 'Charte de marque', 'type' => 'Casse de marque', 'gravite' => 1, 'actif' => true,
                'aide' => 'La marque s\'écrit « ' . $marque .' » — ni tout en capitales, ni sans apostrophe.', 'params' => ['marque' => $marque]],
            ['code' => 'hashtag-marque', 'nom' => 'Hashtag de marque', 'famille' => 'Charte de marque', 'type' => 'Hashtag de marque absent', 'gravite' => 1, 'actif' => true,
                'aide' => 'Le hashtag du réseau rend les posts des magasins retrouvables.', 'params' => ['hashtag' => '#latelierby']],
            ['code' => 'magasin-absent', 'nom' => 'Ancrage local', 'famille' => 'Charte de marque', 'type' => 'Magasin non identifié', 'gravite' => 2, 'actif' => true,
                'aide' => 'Un post de magasin doit dire de quel magasin il parle (ville ou quartier).', 'params' => []],
            ['code' => 'promo-conditions', 'nom' => 'Conditions de promotion', 'famille' => 'Mentions légales', 'type' => 'Conditions de promotion absentes', 'gravite' => 3, 'actif' => true,
                'aide' => 'Une promotion annoncée sans période de validité engage le réseau.',
                'params' => [
                    'mots'    => ['promo', 'promotion', 'gratuit', 'offert', 'remise', 'soldes', '2+1', '1+1'],
                    'motifs'  => ['/-\s?\d{1,2}\s?%/u'],
                    'preuves' => ["jusqu'au", 'valable', 'voir conditions', 'dans la limite des stocks', 'offre limitée à'],
                    'motifsPreuve' => ['/du \d{1,2}\s+\p{L}+\s+au \d{1,2}/u'],
                ]],
            ['code' => 'concours-reglement', 'nom' => 'Règlement de concours', 'famille' => 'Mentions légales', 'type' => 'Règlement de concours absent', 'gravite' => 3, 'actif' => true,
                'aide' => 'Un jeu-concours exige un règlement accessible.',
                'params' => ['mots' => ['concours', 'jeu-concours', 'tirage au sort', 'à gagner', 'gagnez'], 'preuves' => ['règlement', 'reglement', 'conditions du concours']]],
            ['code' => 'allegation-sante', 'nom' => 'Allégation santé', 'famille' => 'Mentions légales', 'type' => 'Allégation santé', 'gravite' => 2, 'actif' => true,
                'aide' => 'Les allégations nutritionnelles et de santé sont encadrées (règlement CE 1924/2006).',
                'params' => ['mots' => ['minceur', 'détox', 'detox', 'sans sucre', 'soigne', 'bon pour la santé', 'anti-cholestérol', 'brûle-graisse', 'fait maigrir', 'renforce l\'immunité']]],
            ['code' => 'superlatif', 'nom' => 'Superlatif non justifié', 'famille' => 'Mentions légales', 'type' => 'Superlatif non justifié', 'gravite' => 1, 'actif' => true,
                'aide' => 'Un superlatif comparatif doit être prouvé, sinon il relève de la publicité trompeuse.',
                'params' => ['mots' => ['le meilleur', 'la meilleure', 'n°1', 'numéro 1', 'le plus grand', 'unique en belgique', 'imbattable']]],
            ['code' => 'longueur-min', 'nom' => 'Message trop court', 'famille' => 'Rédaction', 'type' => 'Message trop court', 'gravite' => 1, 'actif' => true,
                'aide' => 'Sous ce seuil, le post ne porte pas de message.', 'params' => ['min' => 80]],
            ['code' => 'longueur-max', 'nom' => 'Message trop long', 'famille' => 'Rédaction', 'type' => 'Message trop long', 'gravite' => 1, 'actif' => true,
                'aide' => 'Au-delà, Facebook replie le texte et le message se perd.', 'params' => ['max' => 1200]],
            ['code' => 'majuscules', 'nom' => 'Majuscules excessives', 'famille' => 'Rédaction', 'type' => 'Majuscules excessives', 'gravite' => 1, 'actif' => true,
                'aide' => 'Écrire en capitales est hors charte de ton.', 'params' => ['ratioMax' => 0.30, 'minLettres' => 40, 'motMax' => 6]],
            ['code' => 'ponctuation', 'nom' => 'Ponctuation excessive', 'famille' => 'Rédaction', 'type' => 'Ponctuation excessive', 'gravite' => 1, 'actif' => true,
                'aide' => 'Trois points d\'exclamation suffisent rarement à convaincre.', 'params' => ['maxExclamations' => 3]],
            ['code' => 'hashtags-nb', 'nom' => 'Hashtags excessifs', 'famille' => 'Rédaction', 'type' => 'Hashtags excessifs', 'gravite' => 1, 'actif' => true,
                'aide' => 'Au-delà, la portée baisse et le post fait « spam ».', 'params' => ['max' => 8]],
            ['code' => 'lexique', 'nom' => 'Fautes du lexique', 'famille' => 'Rédaction', 'type' => 'Faute de langue', 'gravite' => 1, 'actif' => true,
                // Un lexique de fautes fréquentes, pas un correcteur orthographique :
                // il ne trouve que ce qu'on y met, et c'est ce qu'on attend de lui.
                'aide' => 'Liste de fautes fréquentes — à compléter au fil des relectures.',
                'params' => ['entrees' => [
                    ['mauvais' => 'parmis', 'bon' => 'parmi'],
                    ['mauvais' => 'malgrés', 'bon' => 'malgré'],
                    ['mauvais' => 'quelque soit', 'bon' => 'quel que soit'],
                    ['mauvais' => "d'avantage", 'bon' => 'davantage'],
                    ['mauvais' => 'notemment', 'bon' => 'notamment'],
                    ['mauvais' => 'evenement', 'bon' => 'événement'],
                    ['mauvais' => 'ci joint', 'bon' => 'ci-joint'],
                    ['mauvais' => 'sa va', 'bon' => 'ça va'],
                    ['mauvais' => 'aujourdhui', 'bon' => "aujourd'hui"],
                    ['mauvais' => 'artisanale fait maison', 'bon' => 'artisanal, fait maison'],
                ]]],
            ['code' => 'visuel-absent', 'nom' => 'Visuel attendu', 'famille' => 'Visuel', 'type' => 'Visuel absent', 'gravite' => 2, 'actif' => true,
                'aide' => 'Un post annoncé en photo, carrousel ou vidéo doit porter son média.', 'params' => ['formats' => ['Photo', 'Carrousel', 'Vidéo']]],
            ['code' => 'visuel-alt', 'nom' => 'Texte alternatif', 'famille' => 'Visuel', 'type' => 'Texte alternatif absent', 'gravite' => 1, 'actif' => true,
                'aide' => 'Sans texte alternatif, le visuel est invisible aux lecteurs d\'écran.', 'params' => []],
            ['code' => 'visuel-resolution', 'nom' => 'Résolution du visuel', 'famille' => 'Visuel', 'type' => 'Résolution insuffisante', 'gravite' => 1, 'actif' => true,
                'aide' => 'En dessous, Facebook recompresse et le visuel devient flou.', 'params' => ['largeurMin' => 1080]],
            ['code' => 'creneau', 'nom' => 'Créneau de publication', 'famille' => 'Diffusion', 'type' => 'Créneau interdit', 'gravite' => 1, 'actif' => true,
                'aide' => 'Hors de cette plage, le post sort sans audience et sans modération disponible.', 'params' => ['debut' => '06:00', 'fin' => '23:00']],
            ['code' => 'lien-http', 'nom' => 'Lien sécurisé', 'famille' => 'Diffusion', 'type' => 'Lien non sécurisé', 'gravite' => 2, 'actif' => true,
                'aide' => 'Un lien en http:// est signalé « non sécurisé » aux clients.', 'params' => []],
            ['code' => 'lien-domaine', 'nom' => 'Domaine autorisé', 'famille' => 'Diffusion', 'type' => 'Domaine non autorisé', 'gravite' => 1, 'actif' => true,
                'aide' => 'Les liens sortants hors réseau se valident au cas par cas.',
                'params' => ['domaines' => ['atelierby.be', 'www.atelierby.be', 'panel.atelierby.be', 'facebook.com', 'fb.me']]],
            ['code' => 'doublon', 'nom' => 'Publication dupliquée', 'famille' => 'Diffusion', 'type' => 'Publication dupliquée', 'gravite' => 1, 'actif' => true,
                'aide' => 'Le même texte publié par plusieurs magasins se voit — et se pénalise.', 'params' => ['jours' => 30, 'similarite' => 0.9]],
        ],
    ];
}

/**
 * Le contrôle d'un post : les écarts, puis la note.
 *
 * `$ecartsIgnores` = codes de règle écartés à la main par le CEO sur ce post.
 * Ils restent affichés, mais ne pèsent plus sur la note : une dérogation
 * accordée ne doit pas se rejouer au contrôle suivant.
 */
function fbControler(array $post, array $ecartsIgnores = []): array
{
    $regles = fbRegles();
    $ecarts = [];
    foreach ($regles['regles'] as $r) {
        if (empty($r['actif'])) { continue; }
        foreach (fbAppliquer($r, $post) as $e) {
            $ecarts[] = [
                'code'    => (string) $r['code'],
                'regle'   => (string) $r['nom'],
                'famille' => (string) $r['famille'],
                'type'    => (string) $r['type'],
                'gravite' => (int) $r['gravite'],
                'message' => $e['message'],
                'extrait' => $e['extrait'] ?? null,
                'statut'  => in_array((string) $r['code'], $ecartsIgnores, true) ? 'ignore' : 'ouvert',
            ];
        }
    }
    $note = fbNote($ecarts);
    return ['note' => $note, 'resume' => fbResume($note, $ecarts), 'ecarts' => $ecarts];
}

/**
 * La note, depuis les écarts retenus.
 *
 *   aucun écart                  → 5  Exemplaire
 *   mineurs seulement, 1 ou 2    → 4  Conforme — détails signalés
 *   mineurs seulement, 3 et plus → 3  Non conforme — mineur
 *   au moins un majeur           → 2  Non conforme — majeur
 *   au moins un critique         → 1  Non conforme — critique
 *
 * Une accumulation de détails finit par valoir un écart : trois broutilles sur
 * un même post, ce n'est plus une broutille. Au-delà, la gravité de l'écart le
 * plus grave commande — une mention légale manquante ne se compense pas par
 * dix hashtags bien choisis.
 */
function fbNote(array $ecarts): int
{
    $retenus = array_filter($ecarts, fn ($e) => ($e['statut'] ?? 'ouvert') !== 'ignore');
    if (!$retenus) { return 5; }
    $pire = max(array_map(fn ($e) => (int) $e['gravite'], $retenus));
    if ($pire >= 3) { return 1; }
    if ($pire === 2) { return 2; }
    return count($retenus) >= 3 ? 3 : 4;
}

/** Une phrase pour la ligne repliée et le journal. */
function fbResume(int $note, array $ecarts): string
{
    $retenus = array_values(array_filter($ecarts, fn ($e) => ($e['statut'] ?? 'ouvert') !== 'ignore'));
    if (!$retenus) { return 'Aucun écart détecté — conforme à la charte.'; }
    $n = count($retenus);
    $parFamille = [];
    foreach ($retenus as $e) { $parFamille[$e['famille']] = ($parFamille[$e['famille']] ?? 0) + 1; }
    $libelles = [];
    foreach ($parFamille as $fam => $c) { $libelles[] = $c > 1 ? $fam . ' (' . $c . ')' : $fam; }
    $pire = max(array_map(fn ($e) => (int) $e['gravite'], $retenus));
    $mot = [1 => 'mineur', 2 => 'majeur', 3 => 'critique'][$pire];
    return $n . ' écart' . ($n > 1 ? 's' : '') . ' — plus grave : ' . $mot . ' · ' . implode(' · ', $libelles);
}

/** Normalisation de comparaison : minuscules, apostrophes droites, espaces resserrés. */
function fbNorm(string $s): string
{
    $s = str_replace(['’', '‘', '´', "\u{00A0}"], ["'", "'", "'", ' '], $s);
    $s = mb_strtolower($s, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/**
 * Une règle appliquée à un post → 0, 1 ou plusieurs écarts.
 *
 * Le `match` sur le code est volontaire : une règle inconnue (pack édité à la
 * main, code retiré d'une version à l'autre) ne lève rien et ne casse pas le
 * contrôle des autres.
 */
function fbAppliquer(array $r, array $post): array
{
    $p    = is_array($r['params'] ?? null) ? $r['params'] : [];
    $txt  = (string) ($post['message'] ?? '');
    $norm = fbNorm($txt);
    $medias = is_array($post['medias'] ?? null) ? $post['medias'] : [];

    switch ((string) $r['code']) {
        case 'marque-absente':
            $m = fbNorm((string) ($p['marque'] ?? "L'Atelier by"));
            return str_contains($norm, $m) ? [] : [fbEcart('La marque « ' . ($p['marque'] ?? '') . ' » n\'apparaît pas dans le message.')];

        case 'marque-casse':
            $marque = (string) ($p['marque'] ?? "L'Atelier by");
            if (!str_contains($norm, fbNorm($marque))) { return []; }   // absence : c'est l'autre règle
            // On cherche la marque telle qu'écrite, en tolérant l'apostrophe typographique.
            $motif = '/' . str_replace("'", "['’]", preg_quote($marque, '/')) . '/u';
            if (preg_match($motif, $txt)) { return []; }
            preg_match('/l\s?[\'’]?\s?atelier\s+by/iu', $txt, $m2);
            return [fbEcart('La marque n\'est pas écrite « ' . $marque . ' ».', $m2[0] ?? null)];

        case 'hashtag-marque':
            $h = fbNorm((string) ($p['hashtag'] ?? '#latelierby'));
            return str_contains($norm, $h) ? [] : [fbEcart('Le hashtag ' . ($p['hashtag'] ?? '') . ' est absent.')];

        case 'magasin-absent':
            // Un post réseau (sans magasin) n'a pas d'ancrage local à porter.
            if (($post['shopNom'] ?? null) === null) { return []; }
            foreach (fbAncrages((string) $post['shopNom']) as $a) {
                if (str_contains($norm, fbNorm($a))) { return []; }
            }
            return [fbEcart('Ni la ville ni le quartier de « ' . $post['shopNom'] . ' » n\'apparaissent dans le message.')];

        case 'promo-conditions':
            $trouve = fbTrouve($norm, $p['mots'] ?? [], $p['motifs'] ?? []);
            if ($trouve === null) { return []; }
            if (fbTrouve($norm, $p['preuves'] ?? [], $p['motifsPreuve'] ?? []) !== null) { return []; }
            return [fbEcart('Promotion annoncée sans période de validité ni renvoi aux conditions.', $trouve)];

        case 'concours-reglement':
            $trouve = fbTrouve($norm, $p['mots'] ?? [], $p['motifs'] ?? []);
            if ($trouve === null) { return []; }
            if (fbTrouve($norm, $p['preuves'] ?? [], []) !== null) { return []; }
            if (($post['lien'] ?? '') !== '') { return []; }             // le règlement peut vivre derrière le lien
            return [fbEcart('Jeu-concours sans règlement cité ni lien vers celui-ci.', $trouve)];

        case 'allegation-sante':
        case 'superlatif':
            $trouve = fbTrouve($norm, $p['mots'] ?? [], $p['motifs'] ?? []);
            return $trouve === null ? [] : [fbEcart(
                (string) $r['code'] === 'allegation-sante'
                    ? 'Allégation santé à retirer ou à justifier réglementairement.'
                    : 'Superlatif comparatif à retirer ou à prouver.', $trouve)];

        case 'longueur-min':
            $min = (int) ($p['min'] ?? 80);
            $n = mb_strlen(trim($txt), 'UTF-8');
            return $n >= $min ? [] : [fbEcart('Message de ' . $n . ' caractères, minimum attendu ' . $min . '.')];

        case 'longueur-max':
            $max = (int) ($p['max'] ?? 1200);
            $n = mb_strlen(trim($txt), 'UTF-8');
            return $n <= $max ? [] : [fbEcart('Message de ' . $n . ' caractères, maximum conseillé ' . $max . '.')];

        case 'majuscules':
            return fbMajuscules($txt, $p);

        case 'ponctuation':
            $max = (int) ($p['maxExclamations'] ?? 3);
            $n = preg_match_all('/!/u', $txt);
            if ($n > $max) { return [fbEcart($n . ' points d\'exclamation, maximum ' . $max . '.')]; }
            if (preg_match('/(\?!|!\?|\?\?)/u', $txt, $m3)) { return [fbEcart('Ponctuation empilée.', $m3[0])]; }
            return [];

        case 'hashtags-nb':
            $max = (int) ($p['max'] ?? 8);
            $n = preg_match_all('/#[\p{L}\p{N}_]+/u', $txt);
            return $n <= $max ? [] : [fbEcart($n . ' hashtags, maximum ' . $max . '.')];

        case 'lexique':
            $out = [];
            foreach ($p['entrees'] ?? [] as $e) {
                $mauvais = fbNorm((string) ($e['mauvais'] ?? ''));
                if ($mauvais !== '' && str_contains($norm, $mauvais)) {
                    $out[] = fbEcart('« ' . $e['mauvais'] . ' » → « ' . ($e['bon'] ?? '') . ' ».', (string) $e['mauvais']);
                }
            }
            return $out;

        case 'visuel-absent':
            $formats = $p['formats'] ?? ['Photo', 'Carrousel', 'Vidéo'];
            if (!in_array((string) ($post['format'] ?? ''), $formats, true)) { return []; }
            return $medias ? [] : [fbEcart('Format « ' . $post['format'] . ' » annoncé sans média joint.')];

        case 'visuel-alt':
            $sans = 0;
            foreach ($medias as $m4) { if (trim((string) ($m4['alt'] ?? '')) === '') { $sans++; } }
            return $sans === 0 ? [] : [fbEcart($sans . ' visuel' . ($sans > 1 ? 's' : '') . ' sans texte alternatif.')];

        case 'visuel-resolution':
            $min = (int) ($p['largeurMin'] ?? 1080);
            $petits = [];
            foreach ($medias as $m5) {
                $l = (int) ($m5['largeur'] ?? 0);
                if ($l > 0 && $l < $min) { $petits[] = ($m5['nom'] ?? 'visuel') . ' — ' . $l . ' px'; }
            }
            return $petits ? [fbEcart('Largeur sous ' . $min . ' px : ' . implode(', ', $petits) . '.')] : [];

        case 'creneau':
            $quand = (string) ($post['publierLe'] ?? '');
            if ($quand === '') { return []; }
            $h = substr($quand, 11, 5);
            if ($h === '' || $h === false) { return []; }
            $d = (string) ($p['debut'] ?? '06:00'); $f = (string) ($p['fin'] ?? '23:00');
            return ($h >= $d && $h <= $f) ? [] : [fbEcart('Publication prévue à ' . $h . ', hors plage ' . $d . '–' . $f . '.')];

        case 'lien-http':
            $lien = (string) ($post['lien'] ?? '');
            return str_starts_with(strtolower($lien), 'http://') ? [fbEcart('Lien non sécurisé.', $lien)] : [];

        case 'lien-domaine':
            $lien = (string) ($post['lien'] ?? '');
            if ($lien === '') { return []; }
            $host = strtolower((string) (parse_url($lien, PHP_URL_HOST) ?: ''));
            if ($host === '') { return []; }
            foreach ($p['domaines'] ?? [] as $d2) {
                $d2 = strtolower((string) $d2);
                if ($host === $d2 || str_ends_with($host, '.' . $d2)) { return []; }
            }
            return [fbEcart('Domaine « ' . $host . ' » hors liste autorisée.', $lien)];

        case 'doublon':
            return fbDoublon($post, $p, $norm);
    }
    return [];
}

function fbEcart(string $message, ?string $extrait = null): array
{
    return ['message' => $message, 'extrait' => $extrait !== null && $extrait !== '' ? mb_substr($extrait, 0, 180, 'UTF-8') : null];
}

/** Ville et quartier d'un nom de magasin (« Bruxelles — Châtelain »). */
function fbAncrages(string $nomMagasin): array
{
    $parts = preg_split('/\s*[—–-]\s*/u', $nomMagasin) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if (mb_strlen($part, 'UTF-8') >= 3) { $out[] = $part; }
    }
    return $out ?: [$nomMagasin];
}

/** Premier mot ou motif trouvé dans le texte normalisé, sinon null. */
function fbTrouve(string $norm, array $mots, array $motifs): ?string
{
    foreach ($mots as $m) {
        $m = fbNorm((string) $m);
        if ($m !== '' && str_contains($norm, $m)) { return $m; }
    }
    foreach ($motifs as $motif) {
        if (@preg_match((string) $motif, $norm, $found) === 1) { return $found[0]; }
    }
    return null;
}

/** Capitales : ratio global, et mots entiers criés. */
function fbMajuscules(string $txt, array $p): array
{
    $ratioMax = (float) ($p['ratioMax'] ?? 0.30);
    $minLettres = (int) ($p['minLettres'] ?? 40);
    $motMax = (int) ($p['motMax'] ?? 6);

    $lettres = preg_match_all('/\p{L}/u', $txt);
    $hautes  = preg_match_all('/\p{Lu}/u', $txt);
    if ($lettres >= $minLettres && $hautes / $lettres > $ratioMax) {
        return [fbEcart(round($hautes / $lettres * 100) . ' % de capitales, maximum ' . round($ratioMax * 100) . ' %.')];
    }
    // Un mot long tout en capitales suffit : « OUVERTURE EXCEPTIONNELLE » crie
    // autant dans un texte de 600 caractères que dans un de 60.
    if (preg_match_all('/\p{Lu}{' . max(2, $motMax) . ',}/u', $txt, $mots2)) {
        $cries = array_values(array_filter($mots2[0], fn ($m) => mb_strtolower($m, 'UTF-8') !== 'atelier'));
        if ($cries) { return [fbEcart('Mot' . (count($cries) > 1 ? 's' : '') . ' en capitales : ' . implode(', ', array_slice($cries, 0, 3)) . '.', $cries[0])]; }
    }
    return [];
}

/**
 * Le même texte, déjà passé ailleurs.
 *
 * On ne compare qu'aux posts VALIDÉS ou PUBLIÉS d'un AUTRE magasin sur la
 * fenêtre : un franchisé qui reprend son propre post d'il y a trois semaines
 * fait de la répétition, pas de la duplication de réseau.
 */
function fbDoublon(array $post, array $p, string $norm): array
{
    if ($norm === '' || ($post['shopId'] ?? null) === null) { return []; }
    $jours = (int) ($p['jours'] ?? 30);
    $seuil = (float) ($p['similarite'] ?? 0.9);
    $depuis = date('Y-m-d H:i:s', strtotime(($post['publierLe'] ?: date('Y-m-d H:i:s')) . ' -' . $jours . ' days'));
    $rows = Db::rows(
        "SELECT p.id, p.message, s.name AS shop
           FROM ceo_fb_post p LEFT JOIN ceo_shop s ON s.id = p.shop_id
          WHERE p.id <> ? AND p.shop_id <> ? AND p.status IN ('valide','publie')
            AND COALESCE(p.published_at, p.planned_at) >= ?",
        [(string) ($post['id'] ?? ''), (string) $post['shopId'], $depuis]);
    foreach ($rows as $row) {
        similar_text($norm, fbNorm((string) $row['message']), $pct);
        if ($pct / 100 >= $seuil) {
            return [fbEcart('Texte identique à ' . round($pct) . ' % à un post de ' . ($row['shop'] ?? 'un autre magasin') . '.')];
        }
    }
    return [];
}
