<?php
declare(strict_types=1);

/**
 * Brand Guard — contrôle de marque des posts Facebook du réseau.
 *
 * Depuis le 1er septembre 2026, toute demande de post d'un franchisé passe
 * par l'application, puis Collectif Marketing publie ; un post publié en
 * direct, hors application, est un « post sauvage ». Le module fait trois
 * choses, et rien d'autre :
 *
 *  - AVANT publication, il relit la demande contre la charte (texte + visuels)
 *    et rend un verdict : conforme, à corriger, bloqué. Un post bloqué ne part
 *    pas chez Collectif Marketing : le franchisé voit chaque écart et la
 *    correction attendue, corrige, resoumet. Le franchiseur peut forcer — et
 *    c'est tracé (qui, quand, pourquoi) ;
 *  - APRÈS publication, il lit les pages Facebook (webhook dans la minute,
 *    cron du lundi en filet), contrôle chaque post, et marque sauvage tout
 *    post qu'aucune demande ne porte ;
 *  - il COMPTE : conformité, score, sauvages, règles violées, par boutique et
 *    par période — et l'écrit au journal et au rapport du lundi.
 *
 * La charte vit en base (`brand_guard_rules`), éditable : le prompt se
 * construit à partir des règles actives et chaque verdict enregistre la
 * VERSION de charte qui l'a produit — un verdict de juin se relit avec la
 * charte de juin. La règle de décision est appliquée ICI, après le modèle :
 * un bloquant ⇒ bloqué, des majeurs ⇒ à corriger, sinon conforme. Le modèle
 * propose, le code tranche.
 *
 * Deux moteurs, un verdict. Les règles mécaniques de `fbcontrole.php`
 * (majuscules, superlatifs, doublon…) restent : elles sont certaines et
 * gratuites. Claude juge le reste de la charte, images comprises. Sans clé
 * Anthropic, le verdict repose sur les règles mécaniques seules, et le dit.
 *
 * Aucun secret en base : tokens Meta et clé Anthropic vivent en variables
 * d'environnement ou dans le réglage chiffré existant. Le module ne publie
 * ni ne supprime jamais rien sur Facebook.
 */

const BG_GRAVITES = ['bloquant' => 3, 'majeur' => 2, 'mineur' => 1];

/* --- tables --------------------------------------------------------------------- */

/**
 * Les trois tables, créées à la première utilisation — comme le reste du
 * cockpit, qui n'a pas de migration : une base déjà en service ne rejoue pas
 * schema.sql. Idempotent.
 */
function bgTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS brand_guard_pages ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'shop_id VARCHAR(8) NULL,'
        . 'boutique VARCHAR(80) NOT NULL,'
        . 'franchise VARCHAR(120) NOT NULL DEFAULT \'\','
        . 'page_url VARCHAR(300) NOT NULL DEFAULT \'\','
        . 'page_id VARCHAR(40) NULL,'
        . 'page_nom VARCHAR(160) NULL,'
        . "statut_connexion ENUM('a_connecter','connectee','erreur') NOT NULL DEFAULT 'a_connecter',"
        . 'derniere_erreur VARCHAR(300) NULL,'
        . 'derniere_sync DATETIME NULL,'
        . 'webhook_abonne TINYINT(1) NOT NULL DEFAULT 0,'
        . 'actif TINYINT(1) NOT NULL DEFAULT 1,'
        . 'KEY idx_page (page_id), KEY idx_shop (shop_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS brand_guard_rules ('
        . 'code VARCHAR(40) PRIMARY KEY,'
        . 'famille VARCHAR(60) NOT NULL,'
        . 'libelle VARCHAR(160) NOT NULL,'
        . 'description TEXT NOT NULL,'
        . "gravite ENUM('bloquant','majeur','mineur') NOT NULL DEFAULT 'majeur',"
        . 'actif TINYINT(1) NOT NULL DEFAULT 1,'
        . 'rang SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'maj_le DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS brand_guard_checks ('
        . 'id BIGINT AUTO_INCREMENT PRIMARY KEY,'
        . 'post_id VARCHAR(16) NULL,'
        . 'fb_post_id VARCHAR(64) NULL,'
        . 'shop_id VARCHAR(8) NULL,'
        . 'boutique VARCHAR(80) NULL,'
        . "source ENUM('avant','apres') NOT NULL,"
        . 'sauvage TINYINT(1) NOT NULL DEFAULT 0,'
        . "statut ENUM('conforme','a_corriger','bloque') NOT NULL,"
        . 'score TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'ecarts_json JSON NULL,'
        . 'message TEXT NULL,'
        . 'charte_version CHAR(12) NOT NULL,'
        . "moteur VARCHAR(20) NOT NULL DEFAULT 'claude',"
        . 'modele VARCHAR(60) NULL,'
        . 'images_json JSON NULL,'
        . 'lien VARCHAR(400) NULL,'
        . 'publie_le DATETIME NULL,'
        . 'texte TEXT NULL,'
        . 'notifie_le DATETIME NULL,'
        . 'created_at DATETIME NOT NULL,'
        . 'KEY idx_fb (fb_post_id), KEY idx_post (post_id), KEY idx_shop (shop_id, created_at), KEY idx_sauvage (sauvage, created_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    bgSemer();
}

/** La charte de démonstration et les six pages, posées si les tables sont vides. */
function bgSemer(): void
{
    $n = (int) (Db::row('SELECT COUNT(*) AS n FROM brand_guard_rules')['n'] ?? 0);
    if ($n === 0) {
        $rang = 0;
        foreach (bgCharteDemo() as $r) {
            Db::exec('INSERT INTO brand_guard_rules (code, famille, libelle, description, gravite, actif, rang, maj_le) VALUES (?,?,?,?,?,1,?,?)',
                [$r[0], $r[1], $r[2], $r[3], $r[4], ++$rang, date('Y-m-d H:i:s')]);
        }
    }
    $n = (int) (Db::row('SELECT COUNT(*) AS n FROM brand_guard_pages')['n'] ?? 0);
    if ($n === 0) {
        foreach (bgPagesReseau() as $p) {
            $shop = Db::row('SELECT id FROM ceo_shop WHERE name LIKE ? ORDER BY id LIMIT 1', ['%' . $p[0] . '%']);
            Db::exec('INSERT INTO brand_guard_pages (shop_id, boutique, franchise, page_url) VALUES (?,?,?,?)',
                [$shop['id'] ?? null, $p[0], $p[1], $p[2]]);
        }
    }
}

/** Les six pages du réseau — données de configuration, éditables dans l'admin. */
function bgPagesReseau(): array
{
    return [
        ['Halle',     'Nathan Charlot (Neutralle SRL)', 'https://www.facebook.com/XXXX'],
        ['Corbais',   'Berlo',                          'https://www.facebook.com/XXXX'],
        ['Gembloux',  'Berlo',                          'https://www.facebook.com/XXXX'],
        ['Sombreffe', 'Harmonie Thiry',                 'https://www.facebook.com/XXXX'],
        ['Gosselies', 'Max & Sandra',                   'https://www.facebook.com/XXXX'],
        ['Wavre',     '',                               'https://www.facebook.com/XXXX'],
    ];
}

/**
 * La charte de démonstration — [code, famille, libellé, description, gravité].
 *
 * Elle tient lieu de brand book tant que le vrai n'est pas saisi : chaque
 * règle se lit, se désactive et se réécrit dans l'admin. C'est elle qui fait
 * la qualité du contrôle — la version livrée est un point de départ.
 */
function bgCharteDemo(): array
{
    return [
        ['marque-nom', 'Charte de marque', 'La marque écrite en entier',
            "Le nom de la marque s'écrit « L'Atelier by » suivi du nom de la boutique (ex. « L'Atelier by Halle »). Ni abréviation, ni « Atelier » seul, ni majuscules intégrales.", 'majeur'],
        ['marque-logo', 'Charte de marque', 'Logo intact',
            "Quand le logo apparaît sur un visuel, il n'est ni déformé, ni recoloré, ni recadré, ni posé sur un fond qui le rend illisible.", 'majeur'],
        ['marque-couleurs', 'Charte de marque', 'Palette de la marque',
            "Les visuels créés (affiches, montages, textes sur image) utilisent la palette de la marque : bordeaux, crème, noir. Pas de dégradés fluo, pas de couleurs saturées étrangères à la marque.", 'mineur'],
        ['marque-tierce', 'Charte de marque', 'Aucune marque tierce mise en avant',
            "Un post ne fait pas la promotion d'une marque concurrente ni d'un produit tiers non référencé par le réseau ; un logo de fournisseur n'apparaît qu'avec l'accord du franchiseur.", 'bloquant'],
        ['legal-promo', 'Mentions légales', 'Promotion avec ses conditions',
            "Toute promotion (remise, prix barré, offre) indique sa période de validité et la mention « dans la limite des stocks disponibles » ou renvoie à des conditions.", 'bloquant'],
        ['legal-prix', 'Mentions légales', 'Prix TTC',
            "Un prix annoncé est toutes taxes comprises, en euros, sans ambiguïté sur ce qu'il couvre (unité, pièce, kilo).", 'majeur'],
        ['legal-alcool', 'Mentions légales', 'Alcool et publics protégés',
            "Aucune mise en avant d'alcool sans la mention « Notre savoir-faire se déguste avec sagesse » ; aucun contenu ciblant des mineurs.", 'bloquant'],
        ['legal-image', 'Mentions légales', 'Droit à l\'image',
            "Les personnes reconnaissables sur un visuel (clients, enfants, équipe) y figurent avec leur accord ; pas de photo de clients prise à leur insu.", 'bloquant'],
        ['redac-ton', 'Rédaction', 'Ton chaleureux, jamais agressif',
            "Le ton est artisanal, chaleureux et sobre. Pas d'injonctions criées (« VENEZ VITE »), pas de dénigrement, pas de polémique politique ou religieuse.", 'majeur'],
        ['redac-majuscules', 'Rédaction', 'Pas de cri en majuscules',
            "Pas de mots ni de phrases entièrement en majuscules, pas de suites de points d'exclamation ou d'interrogation.", 'mineur'],
        ['redac-superlatifs', 'Rédaction', 'Pas de superlatif absolu',
            "Pas de « le meilleur », « imbattable », « unique au monde », « n°1 » : la qualité se montre, elle ne se proclame pas.", 'majeur'],
        ['redac-ortho', 'Rédaction', 'Orthographe et accents',
            "Le texte est relu : accents présents, pas de faute visible, ponctuation française (espace avant ; : ! ?).", 'mineur'],
        ['redac-hashtag', 'Rédaction', 'Hashtag de marque',
            "Le post porte #latelierby et le hashtag de la boutique (#latelierbyhalle…). Pas plus de six hashtags.", 'mineur'],
        ['visuel-produit', 'Visuel', 'Photo nette, produit réel',
            "Les visuels montrent des produits réels de la boutique, nets, bien éclairés, à hauteur de comptoir ou en gros plan. Pas de photo floue, sombre ou prise de trop loin.", 'majeur'],
        ['visuel-stock', 'Visuel', 'Pas de photo de banque d\'images',
            "Pas d'image de stock ni d'image générée qui ne montre pas les produits ou le lieu réels de la boutique.", 'majeur'],
        ['visuel-texte', 'Visuel', 'Texte sur image limité',
            "Le texte incrusté sur un visuel reste discret (moins d'un cinquième de la surface) et lisible ; pas de bloc de texte qui remplace la photo.", 'mineur'],
        ['diffusion-ancrage', 'Diffusion', 'Le post parle de SA boutique',
            "Un post de page boutique parle de cette boutique : son nom, ses horaires, son équipe, ses produits. Il ne relaie pas une offre d'une autre boutique ni du réseau sans le dire.", 'mineur'],
        ['diffusion-horaires', 'Diffusion', 'Horaires et lieu exacts',
            "Les horaires, adresses et dates cités sont exacts et cohérents avec la fiche de la boutique.", 'majeur'],
    ];
}

/* --- charte ----------------------------------------------------------------------- */

/** Les règles actives, dans l'ordre d'affichage. */
function bgRegles(bool $toutes = false): array
{
    bgTables();
    $rows = Db::rows('SELECT * FROM brand_guard_rules' . ($toutes ? '' : ' WHERE actif = 1') . ' ORDER BY rang, code');
    return array_map(static fn ($r) => ['code' => (string) $r['code'], 'famille' => (string) $r['famille'],
        'libelle' => (string) $r['libelle'], 'description' => (string) $r['description'],
        'gravite' => (string) $r['gravite'], 'actif' => (bool) $r['actif'], 'rang' => (int) $r['rang']], $rows);
}

/** La version de la charte : l'empreinte des règles actives. Change dès qu'une règle bouge. */
function bgCharteVersion(?array $regles = null): string
{
    $regles = $regles ?? bgRegles();
    $s = '';
    foreach ($regles as $r) { $s .= $r['code'] . '|' . $r['libelle'] . '|' . $r['description'] . '|' . $r['gravite'] . "\n"; }
    return substr(sha1($s), 0, 12);
}

/** Le texte de la charte tel que le modèle le lit : une règle numérotée par ligne, sa gravité dite. */
function bgCharteTexte(array $regles): string
{
    $parFam = [];
    foreach ($regles as $r) { $parFam[$r['famille']][] = $r; }
    $out = [];
    foreach ($parFam as $fam => $rs) {
        $out[] = '## ' . $fam;
        foreach ($rs as $r) {
            $out[] = '- [' . $r['code'] . '] ' . $r['libelle'] . ' (gravité par défaut : ' . $r['gravite'] . ') — ' . $r['description'];
        }
        $out[] = '';
    }
    return trim(implode("\n", $out));
}

/** Le prompt système, construit à partir des règles actives — rien en dur. */
function bgSystem(array $regles): string
{
    return "Tu es le contrôleur de marque du réseau L'Atelier by.\n"
        . "Tu juges un post Facebook d'une boutique franchisée contre la charte ci-dessous, rien d'autre.\n"
        . "Règles de jugement :\n"
        . "- Un seul écart bloquant => statut \"bloque\". Écarts majeurs sans bloquant => \"a_corriger\". Sinon \"conforme\".\n"
        . "- Cite toujours le code de la règle concernée (entre crochets dans la charte). Pas de règle => pas d'écart.\n"
        . "- La gravité d'un écart est celle de la règle, sauf circonstance manifeste que tu expliques dans le constat.\n"
        . "- Sur la typographie, ne certifie pas une police exacte : signale seulement un style clairement hors charte.\n"
        . "- Ne juge des visuels que ce qui est VISIBLE. Si une image ne permet pas de conclure, ne l'inventes pas.\n"
        . "- Correction = consigne concrète et exécutable par le franchisé, pas un conseil vague.\n"
        . "- Le message au franchisé : deux à trois phrases directes, en français, sans flatterie.\n\n"
        . "<charte>\n" . bgCharteTexte($regles) . "\n</charte>";
}

/* --- verdict -------------------------------------------------------------------- */

/**
 * La règle de décision, appliquée après le modèle et jamais déléguée :
 * un bloquant ⇒ bloqué ; un majeur sans bloquant ⇒ à corriger ; sinon conforme.
 * Le score est borné 0..100 ; sans score utilisable, il se déduit des écarts.
 */
function bgDecision(array $ecarts, $score = null): array
{
    $pire = 0;
    foreach ($ecarts as $e) {
        if (($e['statut'] ?? 'ouvert') === 'ignore') { continue; }
        $pire = max($pire, BG_GRAVITES[$e['gravite'] ?? 'mineur'] ?? 1);
    }
    $statut = $pire >= 3 ? 'bloque' : ($pire === 2 ? 'a_corriger' : 'conforme');
    $s = is_numeric($score) ? (int) round((float) $score) : null;
    if ($s === null) {
        $s = 100;
        foreach ($ecarts as $e) {
            if (($e['statut'] ?? 'ouvert') === 'ignore') { continue; }
            $s -= [3 => 40, 2 => 20, 1 => 7][BG_GRAVITES[$e['gravite'] ?? 'mineur'] ?? 1];
        }
    }
    // Un statut ne contredit jamais son score : un bloqué ne s'affiche pas à 95.
    $s = max(0, min(100, $s));
    if ($statut === 'bloque') { $s = min($s, 49); }
    elseif ($statut === 'a_corriger') { $s = min($s, 79); }
    return ['statut' => $statut, 'score' => $s];
}

/** Les écarts des règles mécaniques (`fbcontrole.php`), ramenés au format Brand Guard. */
function bgEcartsMecaniques(string $texte, array $medias, ?string $boutique): array
{
    if (!function_exists('fbControler')) { return []; }
    $post = ['message' => $texte, 'medias' => $medias, 'magasin' => $boutique, 'format' => 'Photo', 'publierLe' => null];
    $out = [];
    foreach (fbControler($post)['ecarts'] ?? [] as $e) {
        $out[] = ['regle' => 'meca:' . $e['code'], 'libelle' => $e['regle'], 'famille' => $e['famille'],
            'gravite' => [3 => 'bloquant', 2 => 'majeur', 1 => 'mineur'][(int) $e['gravite']] ?? 'mineur',
            'constat' => $e['message'], 'extrait' => $e['extrait'] ?? null,
            'correction' => 'Corrigez le passage cité, puis resoumettez.', 'statut' => 'ouvert', 'moteur' => 'regles'];
    }
    return $out;
}

/**
 * Le contrôle d'un post : les règles mécaniques, puis Claude sur la charte
 * (texte + jusqu'à dix images), puis la décision appliquée ici.
 *
 * @param list<string|array> $images  URL (Facebook ou locale) ou ['data'=>octets,'mime'=>…]
 */
function bgControler(string $texte, array $images, ?string $boutique, array $medias = []): array
{
    $regles = bgRegles();
    $version = bgCharteVersion($regles);
    $ecarts = bgEcartsMecaniques($texte, $medias, $boutique);
    $moteur = 'regles'; $modele = null; $message = null; $score = null; $erreur = null;

    if (class_exists('Anthropic') && Anthropic::configured() && $regles !== []) {
        $v = Anthropic::verdict(bgSystem($regles), $texte, array_slice($images, 0, 10), (string) $boutique);
        if (($v['erreur'] ?? null) !== null) {
            $erreur = (string) $v['erreur'];
        } else {
            $moteur = 'claude'; $modele = $v['modele'] ?? null;
            $message = isset($v['message_franchise']) ? trim((string) $v['message_franchise']) : null;
            $score = $v['score'] ?? null;
            $parCode = [];
            foreach ($regles as $r) { $parCode[$r['code']] = $r; }
            foreach ((array) ($v['ecarts'] ?? []) as $e) {
                $code = trim((string) ($e['regle'] ?? ''));
                $code = trim($code, '[] ');
                $r = $parCode[$code] ?? null;
                // Pas de règle => pas d'écart : un constat sans règle ne pèse pas.
                if ($r === null) {
                    foreach ($parCode as $c => $x) { if ($code !== '' && stripos($code, $c) !== false) { $r = $x; $code = $c; break; } }
                    if ($r === null) { continue; }
                }
                $g = (string) ($e['gravite'] ?? '');
                $ecarts[] = ['regle' => $code, 'libelle' => $r['libelle'], 'famille' => $r['famille'],
                    'gravite' => isset(BG_GRAVITES[$g]) ? $g : $r['gravite'],
                    'constat' => trim((string) ($e['constat'] ?? '')), 'extrait' => null,
                    'correction' => trim((string) ($e['correction'] ?? '')), 'statut' => 'ouvert', 'moteur' => 'claude'];
            }
        }
    }
    $d = bgDecision($ecarts, $score);
    if ($message === null) {
        $message = $d['statut'] === 'conforme'
            ? 'Post conforme à la charte, il part au contrôle du franchiseur.'
            : ($d['statut'] === 'bloque' ? 'Ce post ne peut pas partir en l’état : corrigez les points bloquants ci-dessous, puis resoumettez.'
                : 'Le post part avec des remarques : merci de corriger les écarts signalés.');
    }
    return ['statut' => $d['statut'], 'score' => $d['score'], 'ecarts' => $ecarts, 'message' => $message,
        'charteVersion' => $version, 'moteur' => $moteur, 'modele' => $modele, 'erreur' => $erreur];
}

/** Un verdict enregistré. Rend l'identifiant du contrôle. */
function bgEnregistrer(array $v, array $ctx): int
{
    bgTables();
    Db::exec('INSERT INTO brand_guard_checks (post_id, fb_post_id, shop_id, boutique, source, sauvage, statut, score, ecarts_json, message, charte_version, moteur, modele, images_json, lien, publie_le, texte, created_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $ctx['postId'] ?? null, $ctx['fbPostId'] ?? null, $ctx['shopId'] ?? null, $ctx['boutique'] ?? null,
        $ctx['source'], !empty($ctx['sauvage']) ? 1 : 0, $v['statut'], (int) $v['score'],
        json_encode($v['ecarts'], JSON_UNESCAPED_UNICODE), $v['message'], $v['charteVersion'], $v['moteur'], $v['modele'],
        json_encode(array_values(array_filter($ctx['images'] ?? [], 'is_string')), JSON_UNESCAPED_UNICODE),
        $ctx['lien'] ?? null, $ctx['publieLe'] ?? null, mb_substr((string) ($ctx['texte'] ?? ''), 0, 4000), date('Y-m-d H:i:s'),
    ]);
    return (int) Db::pdo()->lastInsertId();
}

/** Un post est sauvage si aucune demande publiée ne porte son identifiant Facebook. */
function bgEstSauvage(string $fbPostId, array $idsConnus): bool
{
    if ($fbPostId === '') { return false; }
    // Meta rend « {page}_{post} » ; une demande peut n'avoir gardé que la partie post.
    $court = str_contains($fbPostId, '_') ? substr($fbPostId, strpos($fbPostId, '_') + 1) : $fbPostId;
    foreach ($idsConnus as $id) {
        $id = (string) $id;
        if ($id === '') { continue; }
        if ($id === $fbPostId || $id === $court) { return false; }
        $c2 = str_contains($id, '_') ? substr($id, strpos($id, '_') + 1) : $id;
        if ($c2 === $court) { return false; }
    }
    return true;
}

/* --- flux « avant » : la demande ---------------------------------------------- */

/**
 * Le contrôle d'une demande de post (ceo_fb_post) : appelé à la soumission et
 * à chaque resoumission, depuis le module existant.
 *
 *  - bloqué   ⇒ la demande est refusée par l'agent : elle ne part pas, le
 *               franchisé voit écarts et corrections, corrige, resoumet ;
 *  - à corriger ⇒ elle part au franchiseur avec les remarques visibles ;
 *  - conforme ⇒ elle part au franchiseur.
 * Le franchiseur peut toujours forcer (`PATCH /facebook/posts/{id}`), tracé.
 */
function bgControlerDemande(array $row): array
{
    $medias = $row['medias_json'] !== null ? (json_decode((string) $row['medias_json'], true) ?: []) : [];
    $images = [];
    foreach ($medias as $m) {
        $u = (string) ($m['url'] ?? '');
        if ($u !== '') { $images[] = bgImageLocale($u); }
    }
    $v = bgControler((string) $row['message'], $images, $row['shop_name'] ?? null, $medias);
    $id = bgEnregistrer($v, ['postId' => $row['id'], 'shopId' => $row['shop_id'], 'boutique' => $row['shop_name'] ?? null,
        'source' => 'avant', 'images' => array_map(static fn ($i) => is_string($i) ? $i : '(upload)', $images), 'texte' => $row['message']]);
    $v['id'] = $id;
    return $v;
}

/** Une URL de média : absolue telle quelle ; relative aux uploads du cockpit, lue sur disque. */
function bgImageLocale(string $u)
{
    if (preg_match('#^https?://#i', $u)) { return $u; }
    $rel = ltrim(str_replace(['..', "\0"], '', $u), '/');
    $chemin = __DIR__ . '/../public/' . $rel;
    if (!is_file($chemin)) { return $u; }
    $mime = (string) (mime_content_type($chemin) ?: 'image/jpeg');
    return ['data' => (string) file_get_contents($chemin), 'mime' => $mime];
}

/* --- flux « après » : les pages -------------------------------------------- */

/** Les identifiants Facebook que porte une demande de post publiée. */
function bgIdsConnus(): array
{
    try {
        return array_map(static fn ($r) => (string) $r['fb_post_id'],
            Db::rows('SELECT fb_post_id FROM ceo_fb_post WHERE fb_post_id IS NOT NULL AND fb_post_id <> \'\''));
    } catch (PDOException $e) { return []; }
}

/**
 * Résout les pages qui ne le sont pas encore : URL → page_id. Un échec
 * marque la page `a_connecter` avec son motif, sans bloquer les autres.
 */
function bgResoudrePages(bool $forcer = false): array
{
    bgTables();
    $out = [];
    if (!MetaGraph::configured()) { return ['motif' => 'META_SYSTEM_TOKEN absent : les pages ne peuvent pas être résolues', 'pages' => []]; }
    foreach (Db::rows('SELECT * FROM brand_guard_pages WHERE actif = 1') as $p) {
        if (!$forcer && $p['page_id'] !== null && $p['statut_connexion'] === 'connectee') { continue; }
        try {
            $r = MetaGraph::resoudrePage((string) $p['page_url']);
            Db::exec("UPDATE brand_guard_pages SET page_id = ?, page_nom = ?, statut_connexion = 'connectee', derniere_erreur = NULL WHERE id = ?",
                [$r['id'], $r['nom'], (int) $p['id']]);
            $out[] = ['boutique' => $p['boutique'], 'ok' => true, 'pageId' => $r['id']];
        } catch (Throwable $e) {
            Db::exec("UPDATE brand_guard_pages SET statut_connexion = 'a_connecter', derniere_erreur = ? WHERE id = ?",
                [mb_substr($e->getMessage(), 0, 300), (int) $p['id']]);
            journalAdd('Brand Guard', 'Connexion', $p['boutique'], 'Page Facebook non résolue : ' . $e->getMessage());
            $out[] = ['boutique' => $p['boutique'], 'ok' => false, 'erreur' => $e->getMessage()];
        }
    }
    return ['pages' => $out];
}

/**
 * Contrôle un post publié sur une page : verdict, sauvage ou non, journal,
 * alerte immédiate si sauvage. Un post déjà contrôlé ne l'est pas deux fois.
 */
function bgAuditerPost(array $page, array $post, ?array $idsConnus = null): ?array
{
    if ($post['id'] === '') { return null; }
    $deja = Db::row('SELECT id FROM brand_guard_checks WHERE fb_post_id = ? AND source = \'apres\'', [$post['id']]);
    if ($deja !== null) { return null; }
    $idsConnus = $idsConnus ?? bgIdsConnus();
    $sauvage = bgEstSauvage($post['id'], $idsConnus);
    $v = bgControler($post['texte'], $post['images'], (string) $page['boutique']);
    $id = bgEnregistrer($v, ['fbPostId' => $post['id'], 'shopId' => $page['shop_id'], 'boutique' => $page['boutique'],
        'source' => 'apres', 'sauvage' => $sauvage, 'images' => $post['images'], 'lien' => $post['lien'],
        'publieLe' => $post['date'], 'texte' => $post['texte']]);
    journalAdd('Brand Guard', $sauvage ? 'Post sauvage' : 'Audit', (string) $page['boutique'],
        ($sauvage ? 'POST SAUVAGE — ' : '') . 'post du ' . substr($post['date'], 0, 16) . ' : ' . $v['statut'] . ' · ' . $v['score'] . '/100'
        . ($v['ecarts'] ? ' · ' . count($v['ecarts']) . ' écart(s)' : ''));
    if ($sauvage) { bgNotifierSauvage($id, $page, $post, $v); }
    return ['id' => $id, 'sauvage' => $sauvage] + $v;
}

/** L'alerte « post sauvage » : mail si le courrier est configuré, notification app dans tous les cas. */
function bgNotifierSauvage(int $checkId, array $page, array $post, array $v): void
{
    $s = setting('brandGuard');
    $a = is_array($s) ? trim((string) ($s['mailAlerte'] ?? '')) : '';
    $envoye = false;
    if ($a !== '' && class_exists('Smtp') && Smtp::configured()) {
        $html = '<p><b>Post sauvage</b> — ' . htmlspecialchars((string) $page['boutique']) . ', publié le ' . htmlspecialchars(substr($post['date'], 0, 16)) . ' hors application.</p>'
            . '<p>Verdict : <b>' . $v['statut'] . '</b> · ' . (int) $v['score'] . '/100 · ' . count($v['ecarts']) . ' écart(s).</p>'
            . '<blockquote>' . nl2br(htmlspecialchars(mb_substr($post['texte'], 0, 600))) . '</blockquote>'
            . ($post['lien'] ? '<p><a href="' . htmlspecialchars($post['lien']) . '">Voir le post</a></p>' : '')
            . '<p style="color:#666">Manquements de la boutique sur 90 jours : ' . bgSauvagesBoutique($page['shop_id'], $page['boutique'], 90) . '.</p>';
        $envoye = Smtp::envoyer($a, '[Brand Guard] Post sauvage — ' . $page['boutique'], $html);
    }
    // La notification d'application : le badge du rail et le journal, lus par tous.
    Db::exec('UPDATE brand_guard_checks SET notifie_le = ? WHERE id = ?', [date('Y-m-d H:i:s'), $checkId]);
    journalAdd('Brand Guard', 'Alerte', (string) $page['boutique'],
        'Alerte post sauvage ' . ($envoye ? 'envoyée par mail à ' . $a : ($a === '' ? '(pas d’adresse d’alerte : réglage brandGuard.mailAlerte)' : '(courrier non configuré)')));
}

/** Le compteur de manquements d'une boutique : ses posts sauvages sur la période. */
function bgSauvagesBoutique(?string $shopId, string $boutique, int $jours): int
{
    $depuis = date('Y-m-d H:i:s', time() - $jours * 86400);
    $r = $shopId !== null
        ? Db::row('SELECT COUNT(*) AS n FROM brand_guard_checks WHERE sauvage = 1 AND shop_id = ? AND created_at >= ?', [$shopId, $depuis])
        : Db::row('SELECT COUNT(*) AS n FROM brand_guard_checks WHERE sauvage = 1 AND boutique = ? AND created_at >= ?', [$boutique, $depuis]);
    return (int) ($r['n'] ?? 0);
}

/**
 * L'audit des pages : relit N jours de chaque page connectée et contrôle
 * tout post pas encore audité. C'est le filet du lundi — le webhook fait le
 * travail dans la minute, l'audit rattrape ce qu'il aurait manqué.
 */
function bgAuditer(int $jours = 7): array
{
    bgTables();
    if (!MetaGraph::configured()) { return ['motif' => 'META_SYSTEM_TOKEN absent : audit impossible', 'pages' => 0, 'controles' => 0, 'sauvages' => 0]; }
    bgResoudrePages();
    $tokens = [];
    try { $tokens = MetaGraph::tokensPages(); }
    catch (MetaTokenExpire $e) {
        journalAdd('Brand Guard', 'Connexion', null, 'Token System User expiré (190) : ' . $e->getMessage());
        return ['motif' => 'token expiré : ' . $e->getMessage(), 'pages' => 0, 'controles' => 0, 'sauvages' => 0];
    }
    $ids = bgIdsConnus();
    $n = 0; $c = 0; $s = 0; $erreurs = [];
    foreach (Db::rows("SELECT * FROM brand_guard_pages WHERE actif = 1 AND statut_connexion = 'connectee' AND page_id IS NOT NULL") as $p) {
        $tk = $tokens[(string) $p['page_id']]['token'] ?? null;
        if ($tk === null) {
            Db::exec("UPDATE brand_guard_pages SET statut_connexion = 'erreur', derniere_erreur = ? WHERE id = ?",
                ['la page n’est pas rattachée au System User (absente de /me/accounts)', (int) $p['id']]);
            $erreurs[] = $p['boutique'] . ' : page non rattachée au Business Manager';
            continue;
        }
        $n++;
        try {
            foreach (MetaGraph::posts((string) $p['page_id'], $tk, $jours) as $post) {
                $r = bgAuditerPost($p, $post, $ids);
                if ($r === null) { continue; }
                $c++; if ($r['sauvage']) { $s++; }
            }
            Db::exec("UPDATE brand_guard_pages SET derniere_sync = ?, derniere_erreur = NULL WHERE id = ?", [date('Y-m-d H:i:s'), (int) $p['id']]);
        } catch (MetaTokenExpire $e) {
            Db::exec("UPDATE brand_guard_pages SET statut_connexion = 'erreur', derniere_erreur = ? WHERE id = ?", ['token expiré : ' . mb_substr($e->getMessage(), 0, 250), (int) $p['id']]);
            $erreurs[] = $p['boutique'] . ' : token expiré';
        } catch (Throwable $e) {
            Db::exec("UPDATE brand_guard_pages SET derniere_erreur = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 300), (int) $p['id']]);
            $erreurs[] = $p['boutique'] . ' : ' . $e->getMessage();
        }
    }
    journalAdd('Brand Guard', 'Audit', null, 'Audit ' . $jours . ' j : ' . $n . ' page(s), ' . $c . ' post(s) contrôlé(s), ' . $s . ' sauvage(s)' . ($erreurs ? ' · ' . count($erreurs) . ' erreur(s)' : ''));
    return ['pages' => $n, 'controles' => $c, 'sauvages' => $s, 'erreurs' => $erreurs];
}

/* --- endpoints ------------------------------------------------------------------ */

/** POST /marketing/brand-guard/checks — contrôle d'une demande ({ postId }) ou d'un texte libre ({ texte, images[], magasinId }). */
function wr_bg_check(): array
{
    bgTables();
    $b = body();
    if (($b['postId'] ?? '') !== '') {
        $row = fbPostRow((string) $b['postId']);
        if ($row === null) { http_response_code(404); return ['error' => 'demande inconnue']; }
        return ['ok' => true, 'verdict' => bgControlerDemande($row)];
    }
    $texte = trim((string) ($b['texte'] ?? ''));
    if ($texte === '' && empty($b['images'])) { http_response_code(422); return ['error' => 'texte ou images attendus']; }
    $boutique = null; $shopId = null;
    if (($b['magasinId'] ?? '') !== '') {
        $s = Db::row('SELECT id, name FROM ceo_shop WHERE id = ?', [(string) $b['magasinId']]);
        if ($s !== null) { $shopId = (string) $s['id']; $boutique = (string) $s['name']; }
    }
    $images = array_values(array_filter((array) ($b['images'] ?? []), 'is_string'));
    $v = bgControler($texte, $images, $boutique);
    $v['id'] = bgEnregistrer($v, ['shopId' => $shopId, 'boutique' => $boutique, 'source' => 'avant', 'images' => $images, 'texte' => $texte]);
    return ['ok' => true, 'verdict' => $v];
}

/** GET /marketing/brand-guard/checks?shop=&from=&to=&statut=&sauvage=&source= */
function ep_bg_checks(): array
{
    bgTables();
    $w = []; $a = [];
    if (($_GET['shop'] ?? '') !== '') { $w[] = 'shop_id = ?'; $a[] = (string) $_GET['shop']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? ''))) { $w[] = 'created_at >= ?'; $a[] = $_GET['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? ''))) { $w[] = 'created_at < ?'; $a[] = date('Y-m-d', strtotime($_GET['to'] . ' +1 day')) . ' 00:00:00'; }
    if (in_array($_GET['statut'] ?? '', ['conforme', 'a_corriger', 'bloque'], true)) { $w[] = 'statut = ?'; $a[] = $_GET['statut']; }
    if (($_GET['sauvage'] ?? '') !== '') { $w[] = 'sauvage = ?'; $a[] = in_array($_GET['sauvage'], ['1', 'true', 'oui'], true) ? 1 : 0; }
    if (in_array($_GET['source'] ?? '', ['avant', 'apres'], true)) { $w[] = 'source = ?'; $a[] = $_GET['source']; }
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
    $rows = Db::rows('SELECT * FROM brand_guard_checks' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit, $a);
    return ['checks' => array_map('bgCheckLigne', $rows), 'charteVersion' => bgCharteVersion()];
}

function bgCheckLigne(array $r): array
{
    return ['id' => (int) $r['id'], 'postId' => $r['post_id'], 'fbPostId' => $r['fb_post_id'], 'magasinId' => $r['shop_id'],
        'boutique' => $r['boutique'], 'source' => $r['source'], 'sauvage' => (bool) $r['sauvage'], 'statut' => $r['statut'],
        'score' => (int) $r['score'], 'ecarts' => json_decode((string) ($r['ecarts_json'] ?? '[]'), true) ?: [],
        'message' => $r['message'], 'charteVersion' => $r['charte_version'], 'moteur' => $r['moteur'], 'modele' => $r['modele'],
        'images' => json_decode((string) ($r['images_json'] ?? '[]'), true) ?: [], 'lien' => $r['lien'],
        'publieLe' => $r['publie_le'], 'texte' => $r['texte'], 'quand' => $r['created_at']];
}

/** GET /marketing/brand-guard/rules — la charte, règle par règle, et sa version. */
function ep_bg_rules(): array
{
    return ['regles' => bgRegles(true), 'charteVersion' => bgCharteVersion(), 'gravites' => array_keys(BG_GRAVITES)];
}

/** PUT /marketing/brand-guard/rules — { regles: [{code, famille, libelle, description, gravite, actif}] } : remplace la charte. */
function wr_bg_rules_put(): array
{
    bgTables();
    $b = body();
    $regles = $b['regles'] ?? null;
    if (!is_array($regles) || $regles === []) { http_response_code(422); return ['error' => 'regles attendues (liste non vide)']; }
    $avant = bgCharteVersion();
    $pdo = Db::pdo(); $pdo->beginTransaction();
    try {
        Db::exec('DELETE FROM brand_guard_rules');
        $rang = 0;
        foreach ($regles as $r) {
            $code = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($r['code'] ?? '')));
            if ($code === '') { continue; }
            Db::exec('INSERT INTO brand_guard_rules (code, famille, libelle, description, gravite, actif, rang, maj_le) VALUES (?,?,?,?,?,?,?,?)', [
                $code, mb_substr(trim((string) ($r['famille'] ?? 'Autre')), 0, 60), mb_substr(trim((string) ($r['libelle'] ?? $code)), 0, 160),
                (string) ($r['description'] ?? ''), isset(BG_GRAVITES[$r['gravite'] ?? '']) ? $r['gravite'] : 'majeur',
                array_key_exists('actif', $r) && !$r['actif'] ? 0 : 1, ++$rang, date('Y-m-d H:i:s')]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $apres = bgCharteVersion();
    if ($apres !== $avant) { journalAdd((string) ($b['par'] ?? 'CEO'), 'Charte', null, 'Charte Brand Guard modifiée : version ' . $avant . ' → ' . $apres); }
    return ['ok' => true, 'charteVersion' => $apres, 'regles' => bgRegles(true)];
}

/** GET /marketing/brand-guard/pages — les pages, leur connexion, l'état des secrets. */
function ep_bg_pages(): array
{
    bgTables();
    return ['pages' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'magasinId' => $p['shop_id'], 'boutique' => $p['boutique'],
            'franchise' => $p['franchise'], 'pageUrl' => $p['page_url'], 'pageId' => $p['page_id'], 'pageNom' => $p['page_nom'],
            'statut' => $p['statut_connexion'], 'erreur' => $p['derniere_erreur'], 'derniereSync' => $p['derniere_sync'],
            'webhook' => (bool) $p['webhook_abonne'], 'actif' => (bool) $p['actif']], Db::rows('SELECT * FROM brand_guard_pages ORDER BY boutique')),
        'meta' => MetaGraph::statut(), 'anthropic' => class_exists('Anthropic') ? Anthropic::statut() : null,
        'mailAlerte' => (string) ((setting('brandGuard') ?: [])['mailAlerte'] ?? '')];
}

/** PUT /marketing/brand-guard/pages — { pages: [{id?, magasinId, boutique, franchise, pageUrl, actif}], resoudre?: true, mailAlerte? } */
function wr_bg_pages_put(): array
{
    bgTables();
    $b = body();
    foreach ((array) ($b['pages'] ?? []) as $p) {
        $url = trim((string) ($p['pageUrl'] ?? ''));
        $vals = [($p['magasinId'] ?? '') !== '' ? (string) $p['magasinId'] : null, mb_substr(trim((string) ($p['boutique'] ?? '')), 0, 80),
            mb_substr(trim((string) ($p['franchise'] ?? '')), 0, 120), mb_substr($url, 0, 300), !empty($p['actif']) || !isset($p['actif']) ? 1 : 0];
        if (($p['id'] ?? 0) > 0) {
            $anc = Db::row('SELECT page_url FROM brand_guard_pages WHERE id = ?', [(int) $p['id']]);
            // Une URL qui change invalide la résolution : la page se relira.
            $reset = $anc !== null && (string) $anc['page_url'] !== $url ? ", page_id = NULL, statut_connexion = 'a_connecter', derniere_erreur = NULL" : '';
            Db::exec('UPDATE brand_guard_pages SET shop_id = ?, boutique = ?, franchise = ?, page_url = ?, actif = ?' . $reset . ' WHERE id = ?', array_merge($vals, [(int) $p['id']]));
        } elseif ($vals[1] !== '') {
            Db::exec('INSERT INTO brand_guard_pages (shop_id, boutique, franchise, page_url, actif) VALUES (?,?,?,?,?)', $vals);
        }
    }
    if (array_key_exists('mailAlerte', $b)) {
        $s = setting('brandGuard') ?: [];
        $s['mailAlerte'] = trim((string) $b['mailAlerte']);
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['brandGuard', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    }
    $res = !empty($b['resoudre']) ? bgResoudrePages(true) : null;
    return ['ok' => true, 'resolution' => $res] + ep_bg_pages();
}

/** GET /marketing/brand-guard/stats?jours=7|30|90 — le tableau de bord franchiseur. */
function ep_bg_stats(): array
{
    bgTables();
    $periodes = [7, 30, 90];
    $out = ['periodes' => [], 'charteVersion' => bgCharteVersion(), 'meta' => MetaGraph::statut(),
        'anthropic' => class_exists('Anthropic') ? Anthropic::statut() : null];
    foreach ($periodes as $j) { $out['periodes'][(string) $j] = bgStatsPeriode($j); }
    $out['sauvagesATraiter'] = (int) (Db::row('SELECT COUNT(*) AS n FROM brand_guard_checks WHERE sauvage = 1 AND created_at >= ?', [date('Y-m-d H:i:s', time() - 30 * 86400)])['n'] ?? 0);
    return $out;
}

/** Conformité, score, sauvages, top 3 des règles — par boutique, sur N jours. */
function bgStatsPeriode(int $jours): array
{
    $depuis = date('Y-m-d H:i:s', time() - $jours * 86400);
    $rows = Db::rows('SELECT shop_id, boutique, source, sauvage, statut, score, ecarts_json FROM brand_guard_checks WHERE created_at >= ?', [$depuis]);
    $par = []; $regles = []; $tot = ['n' => 0, 'conformes' => 0, 'score' => 0, 'sauvages' => 0];
    foreach ($rows as $r) {
        $k = (string) ($r['boutique'] ?? '—');
        $par[$k] ??= ['magasinId' => $r['shop_id'], 'boutique' => $k, 'n' => 0, 'conformes' => 0, 'score' => 0, 'sauvages' => 0, 'regles' => []];
        $b = &$par[$k];
        $b['n']++; $tot['n']++;
        if ($r['statut'] === 'conforme') { $b['conformes']++; $tot['conformes']++; }
        $b['score'] += (int) $r['score']; $tot['score'] += (int) $r['score'];
        if ((int) $r['sauvage'] === 1) { $b['sauvages']++; $tot['sauvages']++; }
        foreach (json_decode((string) ($r['ecarts_json'] ?? '[]'), true) ?: [] as $e) {
            $c = (string) ($e['regle'] ?? '?'); $l = (string) ($e['libelle'] ?? $c);
            $b['regles'][$c] = ['code' => $c, 'libelle' => $l, 'n' => ($b['regles'][$c]['n'] ?? 0) + 1];
            $regles[$c] = ['code' => $c, 'libelle' => $l, 'n' => ($regles[$c]['n'] ?? 0) + 1];
        }
        unset($b);
    }
    $top = static function (array $rs): array { usort($rs, static fn ($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['code'], $b['code'])); return array_slice(array_values($rs), 0, 3); };
    $boutiques = [];
    foreach ($par as $b) {
        $boutiques[] = ['magasinId' => $b['magasinId'], 'boutique' => $b['boutique'], 'controles' => $b['n'],
            'tauxConformite' => $b['n'] ? (int) round(100 * $b['conformes'] / $b['n']) : null,
            'scoreMoyen' => $b['n'] ? (int) round($b['score'] / $b['n']) : null, 'sauvages' => $b['sauvages'], 'topRegles' => $top($b['regles'])];
    }
    // Du pire au meilleur : les sauvages d'abord, puis le taux le plus bas.
    usort($boutiques, static fn ($a, $b) => ($b['sauvages'] <=> $a['sauvages']) ?: (($a['tauxConformite'] ?? 101) <=> ($b['tauxConformite'] ?? 101)));
    return ['jours' => $jours, 'controles' => $tot['n'], 'tauxConformite' => $tot['n'] ? (int) round(100 * $tot['conformes'] / $tot['n']) : null,
        'scoreMoyen' => $tot['n'] ? (int) round($tot['score'] / $tot['n']) : null, 'sauvages' => $tot['sauvages'], 'topRegles' => $top($regles), 'boutiques' => $boutiques];
}

/* --- webhook Meta ------------------------------------------------------------------ */

/** GET /marketing/brand-guard/webhook/meta — la vérification d'abonnement (challenge). */
function ep_bg_webhook_verif(): void
{
    $c = MetaGraph::config();
    $mode = (string) ($_GET['hub_mode'] ?? '');
    $tok = (string) ($_GET['hub_verify_token'] ?? '');
    if ($mode === 'subscribe' && $c['verifyToken'] !== '' && hash_equals($c['verifyToken'], $tok)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo (string) ($_GET['hub_challenge'] ?? '');
        return;
    }
    http_response_code(403);
    echo 'verify token invalide';
}

/**
 * POST /marketing/brand-guard/webhook/meta — un événement `feed`.
 * Signature vérifiée sur le corps brut ; sans signature valable, 403 et rien
 * n'est lu. Meta attend un 200 rapide : le contrôle se fait dans la foulée
 * mais chaque post est protégé contre le double passage.
 */
function wr_bg_webhook(string $corps, ?string $signature): array
{
    if (!MetaGraph::signatureOk($corps, $signature)) { http_response_code(403); return ['error' => 'signature invalide']; }
    $payload = json_decode($corps, true);
    if (!is_array($payload)) { http_response_code(400); return ['error' => 'corps illisible']; }
    bgTables();
    $faits = [];
    $tokens = null; $ids = null;
    foreach (MetaGraph::postsDuWebhook($payload) as $ev) {
        $page = Db::row("SELECT * FROM brand_guard_pages WHERE page_id = ? AND actif = 1", [$ev['page']]);
        if ($page === null) { $faits[] = ['post' => $ev['post'], 'ignore' => 'page inconnue ' . $ev['page']]; continue; }
        try {
            $tokens = $tokens ?? MetaGraph::tokensPages();
            $tk = $tokens[$ev['page']]['token'] ?? null;
            if ($tk === null) { $faits[] = ['post' => $ev['post'], 'ignore' => 'page non rattachée au System User']; continue; }
            $raw = MetaGraph::http('GET', $ev['post'], ['access_token' => $tk,
                'fields' => 'id,message,created_time,permalink_url,full_picture,attachments{media,subattachments{media}}']);
            $ids = $ids ?? bgIdsConnus();
            $r = bgAuditerPost($page, MetaGraph::normaliser($raw), $ids);
            $faits[] = ['post' => $ev['post'], 'controle' => $r === null ? 'déjà contrôlé' : $r['statut'], 'sauvage' => $r['sauvage'] ?? null];
            Db::exec('UPDATE brand_guard_pages SET webhook_abonne = 1, derniere_sync = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $page['id']]);
        } catch (Throwable $e) {
            journalAdd('Brand Guard', 'Webhook', (string) $page['boutique'], 'Post ' . $ev['post'] . ' non contrôlé : ' . $e->getMessage());
            $faits[] = ['post' => $ev['post'], 'erreur' => $e->getMessage()];
        }
    }
    return ['ok' => true, 'faits' => $faits];
}

/* --- cron et rapport du lundi ------------------------------------------------------ */

/**
 * GET /marketing/brand-guard/cron?jeton=… — appelé chaque heure par
 * bin/rapports_cron.sh (même jeton que les rapports). Fait deux choses le
 * lundi à 7 h (heure de Bruxelles) : l'audit des sept derniers jours, puis
 * le rapport par mail. `?forcer=1` pour une exécution à la main.
 */
function ep_bg_cron(): array
{
    $jeton = (string) setting('rapportsJeton', '');
    if ($jeton === '' || !hash_equals($jeton, (string) ($_GET['jeton'] ?? ''))) {
        http_response_code(403); return ['error' => 'jeton absent ou invalide'];
    }
    bgTables();
    $tz = new DateTimeZone('Europe/Brussels');
    $now = new DateTime('now', $tz);
    $forcer = !empty($_GET['forcer']);
    $lundi7h = (int) $now->format('N') === 1 && (int) $now->format('G') === 7;
    if (!$forcer && !$lundi7h) { return ['fait' => false, 'motif' => 'rien à faire avant lundi 7 h (Europe/Brussels)']; }
    $s = setting('brandGuard') ?: [];
    $cle = 'rapport:' . $now->format('Y-m-d');
    if (!$forcer && ($s['dernierRapport'] ?? '') === $cle) { return ['fait' => false, 'motif' => 'rapport du jour déjà envoyé']; }
    $audit = bgAuditer(7);
    $rapport = bgRapportHebdo();
    $s['dernierRapport'] = $cle;
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['brandGuard', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return ['fait' => true, 'audit' => $audit, 'rapport' => $rapport];
}

/** Le rapport hebdomadaire : 7 jours, du pire au meilleur, un lien par post. */
function bgRapportHebdo(): array
{
    $st = bgStatsPeriode(7);
    $depuis = date('Y-m-d H:i:s', time() - 7 * 86400);
    $rows = Db::rows('SELECT * FROM brand_guard_checks WHERE created_at >= ? ORDER BY sauvage DESC, score ASC, created_at DESC LIMIT 200', [$depuis]);
    $h = '<h2 style="font-family:sans-serif">Brand Guard — semaine au ' . date('d/m/Y') . '</h2>'
        . '<p>' . $st['controles'] . ' post(s) contrôlé(s) · conformité ' . ($st['tauxConformite'] ?? '—') . ' % · score moyen ' . ($st['scoreMoyen'] ?? '—') . '/100 · <b>' . $st['sauvages'] . ' post(s) sauvage(s)</b>.</p>';
    if ($st['boutiques']) {
        $h .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:sans-serif;font-size:13px"><tr><th>Boutique</th><th>Contrôles</th><th>Conformité</th><th>Score</th><th>Sauvages</th><th>Règles les plus violées</th></tr>';
        foreach ($st['boutiques'] as $b) {
            $h .= '<tr><td>' . htmlspecialchars($b['boutique']) . '</td><td>' . $b['controles'] . '</td><td>' . ($b['tauxConformite'] ?? '—') . ' %</td><td>' . ($b['scoreMoyen'] ?? '—') . '</td><td>' . $b['sauvages'] . '</td><td>'
                . htmlspecialchars(implode(' · ', array_map(static fn ($r) => $r['libelle'] . ' (' . $r['n'] . ')', $b['topRegles']))) . '</td></tr>';
        }
        $h .= '</table>';
    }
    if ($rows) {
        $h .= '<h3 style="font-family:sans-serif">Les posts, du pire au meilleur</h3><ul style="font-family:sans-serif;font-size:13px">';
        foreach ($rows as $r) {
            $h .= '<li>' . ((int) $r['sauvage'] === 1 ? '<b style="color:#C0182B">SAUVAGE</b> · ' : '') . htmlspecialchars((string) $r['boutique']) . ' · ' . $r['statut'] . ' ' . (int) $r['score'] . '/100 · ' . substr((string) ($r['publie_le'] ?? $r['created_at']), 0, 16)
                . ($r['lien'] ? ' · <a href="' . htmlspecialchars((string) $r['lien']) . '">voir le post</a>' : '') . '</li>';
        }
        $h .= '</ul>';
    }
    $s = setting('brandGuard') ?: [];
    $a = trim((string) ($s['mailAlerte'] ?? ''));
    $envoye = $a !== '' && class_exists('Smtp') && Smtp::configured() ? Smtp::envoyer($a, '[Brand Guard] Rapport hebdomadaire — ' . $st['sauvages'] . ' sauvage(s), conformité ' . ($st['tauxConformite'] ?? '—') . ' %', $h) : false;
    journalAdd('Brand Guard', 'Rapport', null, 'Rapport hebdomadaire ' . ($envoye ? 'envoyé à ' . $a : 'produit (pas d’envoi : ' . ($a === '' ? 'adresse absente' : 'courrier non configuré') . ')'));
    return ['envoye' => $envoye, 'a' => $a, 'stats' => $st];
}
