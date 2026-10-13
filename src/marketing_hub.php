<?php
declare(strict_types=1);

/**
 * Le module Marketing (/marketing, demande du 13/10/2026) — tous les canaux en une lecture.
 *
 * Réseaux sociaux, emailing et campagnes vivaient dans quatre sections du rail
 * (Marque & marketing, ERP franchisé, Contrôle, Paramètres) et dans quatre
 * familles de tables qui ne se parlaient pas. Une campagne Saint-Nicolas se
 * lisait dans mar_campaign, sa newsletter dans ceo_nl_campagne, ses posts dans
 * ceo_fb_post : rien ne disait qu'une campagne en cours n'avait ni post ni
 * mail. Le module les pose côte à côte, sans rien recalculer à sa façon :
 *
 *  - campagnes : mar_campaign, mêmes lignes que GET /marketing ;
 *  - emailing : ceo_nl_campagne, mêmes statistiques que GET /newsletter
 *    (nlStatsReelles d'abord, stats_json en repli) ;
 *  - réseaux sociaux : ceo_fb_post, le cycle brouillon → contrôle → décision → publication ;
 *  - charte : brand_guard_checks sur 30 jours, mêmes chiffres que /marketing/brand-guard/stats ;
 *  - avis Google : GET /reputation tel quel (ceo_shop_reputation, ceo_shop_review).
 *
 * Chaque canal se lit À PART : une table absente rend { dispo:false, raison }
 * pour ce canal seul, l'écran garde les autres. Les écritures ne passent pas
 * par ici : le module appelle les routes existantes de chaque canal.
 *
 * ?shop= : la vue d'un magasin — ses posts, ses newsletters, les campagnes
 * réseau et celles qui le couvrent ; ni la charte du réseau, ni les sauvages
 * des autres.
 */

/** Statuts de campagne qui ne parlent pas aux clients : ni « en cours », ni « à venir ». */
const MH_CAMPAGNE_MORTE = ['draft', 'cancelled', 'canceled', 'archived'];

/** GET /marketing/hub?shop= */
function ep_marketing_hub(): array
{
    $shop = mhShop();
    $auj = date('Y-m-d');
    $magasins = mhCanal(static fn () => mhMagasins());
    $nomDe = [];
    foreach (($magasins['liste'] ?? []) as $m) { $nomDe[$m['id']] = $m['nom']; }

    $campagnes = mhCanal(static fn () => mhCampagnes($shop, $auj));
    $emailing  = mhCanal(static fn () => mhEmailing($shop, $auj));
    $social    = mhCanal(static fn () => mhSocial($shop, $auj));
    // La charte et les posts sauvages sont l'affaire du franchiseur : un
    // magasin voit les siens, pas le palmarès du réseau.
    $charte    = mhCanal(static fn () => mhCharte($shop));
    $avis      = mhCanal(static fn () => mhAvis($shop));

    return [
        'genere' => date('Y-m-d H:i'),
        'aujourdhui' => $auj,
        'shop' => $shop,
        'magasin' => $shop !== null ? ($nomDe[$shop] ?? null) : null,
        'magasins' => $magasins['liste'] ?? [],
        'campagnes' => $campagnes,
        'emailing' => $emailing,
        'social' => $social,
        'charte' => $charte,
        'avis' => $avis,
        'aFaire' => array_slice(array_merge(mhAFaire($campagnes, $emailing, $social, $charte, $shop, $auj), mhAFaireAvis($avis, $auj)), 0, 40),
    ];
}

/** Le magasin demandé, ou null pour la vue réseau. Un id de boutique, rien d'autre. */
function mhShop(): ?string
{
    $s = trim((string) ($_GET['shop'] ?? ''));
    return preg_match('/^[\w-]{1,16}$/', $s) && $s !== 'brand' && $s !== 'reseau' ? $s : null;
}

/**
 * Un canal lu à part. Une table absente ou une colonne en retard rendent le
 * canal indisponible, AVEC la raison — jamais un 500 qui emporterait les autres.
 */
function mhCanal(callable $lire): array
{
    try {
        return ['dispo' => true] + $lire();
    } catch (Throwable $e) {
        return ['dispo' => false, 'raison' => mhRaison($e)];
    }
}

function mhRaison(Throwable $e): string
{
    $m = $e->getMessage();
    if (preg_match("/Table '[^']*?\.?([\w]+)' doesn't exist/", $m, $t)) { return 'table ' . $t[1] . ' absente de la base'; }
    return mb_substr($m, 0, 200);
}

/** Les magasins ouverts, pour le sélecteur — la même liste que la newsletter et le contrôle des posts. */
function mhMagasins(): array
{
    $l = [];
    foreach (ep_stores() as $s) {
        if (!preg_match('/ouvert/i', (string) ($s['status'] ?? 'Ouvert'))) { continue; }
        // `franchise` signe les posts soumis depuis le magasin, comme dans le cockpit.
        $l[] = ['id' => (string) $s['id'], 'nom' => (string) $s['nom'], 'franchise' => (string) ($s['fr'] ?? '')];
    }
    return ['liste' => $l];
}

/* --- campagnes (mar_campaign) ---------------------------------------------------- */

function mhCampagnes(?string $shop, string $auj): array
{
    $parCamp = [];
    try {
        foreach (Db::rows('SELECT campaign_id, shop_id FROM mar_campaign_shop') as $r) {
            $parCamp[(int) $r['campaign_id']][] = (string) $r['shop_id'];
        }
    } catch (PDOException $e) { /* table de liaison absente : chaque campagne vaut pour le réseau */ }

    $rows = Db::rows('SELECT c.*, t.label AS type_label, t.color_hex AS type_color,
                             s.label AS status_label, s.text_hex, s.bg_rgba
                        FROM mar_campaign c
                        LEFT JOIN mar_campaign_type t ON t.id = c.type_id
                        LEFT JOIN mar_campaign_status s ON s.code = c.status_code
                       ORDER BY c.starts_on IS NULL, c.starts_on DESC, c.id DESC');
    $liste = [];
    foreach ($rows as $c) {
        $id = (int) $c['id'];
        $scope = (string) ($c['scope'] ?? '');
        // Même lecture du périmètre que la mesure : une campagne RÉSEAU couvre
        // tout le monde, quoi que porte la table de liaison.
        $reseau = $scope === '' || preg_match('/^(RESEAU|RÉSEAU|NETWORK)/u', $scope) === 1;
        $boutiques = $parCamp[$id] ?? [];
        if ($shop !== null && !$reseau && !in_array($shop, $boutiques, true)) { continue; }
        $statut = (string) $c['status_code'];
        $debut = $c['starts_on'] ?: null; $fin = $c['ends_on'] ?: null;
        $phase = mhPhase($statut, $debut, $fin, $auj);
        // Un brouillon de la marque n'est pas encore une campagne pour le
        // magasin : il la découvre quand elle est lancée, pas avant.
        if ($shop !== null && $phase === 'brouillon') { continue; }
        $liste[] = [
            'id' => $id, 'nom' => (string) $c['name'],
            'typeId' => $c['type_id'] !== null ? (int) $c['type_id'] : null,
            'type' => $c['type_label'], 'typeCouleur' => $c['type_color'],
            'reseau' => $reseau, 'nBoutiques' => count($boutiques),
            'statut' => $statut, 'statutNom' => $c['status_label'] ?? $statut,
            'statutTexte' => $c['text_hex'] ?? '#666666', 'statutFond' => $c['bg_rgba'] ?? 'rgba(120,116,110,.12)',
            'debut' => $debut, 'fin' => $fin,
            'budget' => (float) ($c['budget_amount'] ?? 0), 'depense' => (float) ($c['spent_amount'] ?? 0),
            'image' => ($c['image_url'] ?? '') ?: null,
            'phase' => $phase,
        ];
    }
    $n = static fn (string $p): int => count(array_filter($liste, static fn ($c) => $c['phase'] === $p));
    $vivantes = array_filter($liste, static fn ($c) => $c['phase'] !== 'brouillon');
    return [
        'liste' => $liste,
        'chiffres' => [
            'total' => count($liste), 'enCours' => $n('en_cours'), 'aVenir' => $n('a_venir'),
            'terminees' => $n('terminee'), 'brouillons' => $n('brouillon'),
            'budget' => round(array_sum(array_column($vivantes, 'budget')), 2),
            'depense' => round(array_sum(array_column($vivantes, 'depense')), 2),
        ],
    ];
}

/**
 * Où en est une campagne AUJOURD'HUI, d'après ses dates — le libellé de statut
 * varie d'une base à l'autre, le calendrier non. Brouillon, annulée ou archivée
 * ne parlent pas aux clients : elles restent « brouillon », quelles que soient leurs dates.
 */
function mhPhase(string $statut, ?string $debut, ?string $fin, string $auj): string
{
    if (in_array(strtolower($statut), MH_CAMPAGNE_MORTE, true)) { return 'brouillon'; }
    if ($debut !== null && $debut > $auj) { return 'a_venir'; }
    if ($fin !== null && $fin < $auj) { return 'terminee'; }
    if ($debut === null && $fin === null) { return 'sans_date'; }
    return 'en_cours';
}

/* --- emailing (ceo_nl_campagne) --------------------------------------------------- */

function mhEmailing(?string $shop, string $auj): array
{
    $role = $shop ?? 'brand';
    $liste = [];
    foreach (Db::rows('SELECT * FROM ceo_nl_campagne ORDER BY COALESCE(send_at, created_at) DESC, id DESC') as $r) {
        if ($shop !== null && (string) $r['created_by'] !== $shop && (string) $r['sender_shop_id'] !== $shop) { continue; }
        $c = function_exists('nlCampagneRow') ? nlCampagneRow($r) : ['stats' => json_decode((string) ($r['stats_json'] ?? ''), true) ?: []];
        $st = is_array($c['stats'] ?? null) ? $c['stats'] : [];
        $subjects = json_decode((string) ($r['subjects_json'] ?? ''), true);
        $liste[] = [
            'id' => (int) $r['id'], 'nom' => (string) $r['nom'],
            'objet' => is_array($subjects) ? (string) ($subjects['fr'] ?? reset($subjects) ?: '') : '',
            'statut' => (string) $r['statut'], 'canal' => (string) $r['channel'],
            'mode' => (string) $r['send_mode'], 'declencheur' => $r['trigger_rule'],
            'envoiLe' => $r['send_at'] !== null ? substr((string) $r['send_at'], 0, 16) : null,
            'expediteur' => (string) $r['sender_shop_id'],
            'testEnvoye' => (int) $r['test_sent'] === 1,
            'exemple' => (int) $r['exemple'] === 1,
            'envoyes' => (int) ($st['sent'] ?? 0), 'ouvertures' => (int) ($st['open'] ?? 0),
            'clics' => (int) ($st['click'] ?? 0), 'vouchers' => (int) ($st['vouchers'] ?? 0),
            'reel' => !empty($st['reel']),
        ];
    }
    // Les chiffres ne comptent pas le jeu d'essai : il fait vivre l'écran, il ne
    // dit rien des clients. L'écran dit combien il en écarte.
    $vraies = array_values(array_filter($liste, static fn ($c) => !$c['exemple']));
    $envoyes = array_sum(array_column($vraies, 'envoyes'));
    $ouv = array_sum(array_column($vraies, 'ouvertures'));
    $clics = array_sum(array_column($vraies, 'clics'));
    $mois = substr($auj, 0, 7);
    $optin = null;
    try {
        $optin = 0;
        foreach (Db::rows('SELECT shop_id, COUNT(*) n FROM ceo_nl_contact WHERE optin = 1 AND desinscrit_le IS NULL GROUP BY shop_id') as $r) {
            if ($shop === null || (string) $r['shop_id'] === $shop) { $optin += (int) $r['n']; }
        }
    } catch (PDOException $e) { $optin = null; /* aucun contact importé : le module newsletter garde ses chiffres d'essai */ }

    return [
        'liste' => $liste,
        'role' => $role,
        'test' => function_exists('nlDispatch') ? !nlDispatch() : true,
        'smtp' => class_exists('Smtp') && Smtp::configured(),
        'chiffres' => [
            'total' => count($vraies),
            'exemples' => count($liste) - count($vraies),
            'programmees' => count(array_filter($vraies, static fn ($c) => $c['statut'] === 'sched')),
            'automatisations' => count(array_filter($vraies, static fn ($c) => $c['mode'] === 'auto')),
            'envoyeesMois' => count(array_filter($vraies, static fn ($c) => in_array($c['statut'], ['sent', 'live'], true) && str_starts_with((string) $c['envoiLe'], $mois))),
            'envoyes' => $envoyes, 'ouvertures' => $ouv, 'clics' => $clics,
            'tauxOuverture' => $envoyes > 0 ? round(100 * $ouv / $envoyes, 1) : null,
            'tauxClic' => $envoyes > 0 ? round(100 * $clics / $envoyes, 1) : null,
            'optin' => $optin,
        ],
    ];
}

/* --- réseaux sociaux (ceo_fb_post) ------------------------------------------------- */

function mhSocial(?string $shop, string $auj): array
{
    $posts = ep_fb_posts();
    if ($shop !== null) {
        $posts = array_values(array_filter($posts, static fn ($p) => (string) ($p['magasinId'] ?? '') === $shop));
    }
    $par = array_fill_keys(['brouillon', 'a_controler', 'a_valider', 'valide', 'refuse', 'publie'], 0);
    foreach ($posts as $p) { if (isset($par[$p['statut']])) { $par[$p['statut']]++; } }
    $mois = substr($auj, 0, 7);
    $notes = array_values(array_filter(array_map(static fn ($p) => $p['decision']['note'] ?? null, $posts), static fn ($n) => $n !== null));
    $r = fbRegles();
    $sig = setting('signalement', []);
    $niveaux = (is_array($sig) && !empty($sig['niveaux'])) ? $sig['niveaux'] : signalementDefaut()['niveaux'];
    // La marque et son hashtag, tels que la charte les exige : un post préparé
    // depuis une campagne part déjà avec, au lieu d'être refusé pour leur absence.
    $param = static function (string $code, string $cle) use ($r): ?string {
        foreach ($r['regles'] ?? [] as $x) {
            if (($x['code'] ?? '') === $code && !empty($x['actif']) && isset($x['params'][$cle])) { return (string) $x['params'][$cle]; }
        }
        return null;
    };
    return [
        'liste' => $posts,
        'parStatut' => $par,
        'chiffres' => [
            'total' => count($posts),
            'aTraiter' => $par['a_controler'] + $par['a_valider'],
            'aPublier' => $par['valide'],
            'publiesMois' => count(array_filter($posts, static fn ($p) => str_starts_with((string) ($p['publie']['le'] ?? ''), $mois))),
            'refuses' => $par['refuse'],
            'noteMoyenne' => $notes ? round(array_sum($notes) / count($notes), 1) : null,
        ],
        // Ce qu'il faut pour décider sans un second appel : le seuil, les
        // familles d'écart (obligatoires sous le seuil ou en cas de refus) et
        // les cinq niveaux de l'échelle du réseau.
        'regles' => [
            'seuil' => fbSeuil(),
            'familles' => $r['familles'] ?? [],
            'niveaux' => array_map(static fn ($n) => ['n' => (int) $n['n'], 'nom' => (string) $n['nom'], 'couleur' => $n['couleur'] ?? null], $niveaux),
            'formats' => ['Photo', 'Carrousel', 'Vidéo', 'Texte', 'Lien', 'Événement'],
            'marque' => $param('marque-absente', 'marque'),
            'hashtag' => $param('hashtag-marque', 'hashtag'),
        ],
    ];
}

/* --- charte de marque (brand_guard_checks) ------------------------------------------ */

function mhCharte(?string $shop): array
{
    if (function_exists('bgTables')) { bgTables(); }
    $stats = bgStatsPeriode(30);
    if ($shop !== null) {
        $b = null;
        foreach ($stats['boutiques'] as $x) { if ((string) ($x['magasinId'] ?? '') === $shop) { $b = $x; } }
        $stats = ['jours' => 30, 'controles' => $b['controles'] ?? 0, 'tauxConformite' => $b['tauxConformite'] ?? null,
            'scoreMoyen' => $b['scoreMoyen'] ?? null, 'sauvages' => $b['sauvages'] ?? 0, 'topRegles' => $b['topRegles'] ?? [], 'boutiques' => []];
    }
    // Les posts sauvages : publiés sur la page sans passer par la demande.
    // Aucun écran ne les listait ; c'est la première chose à regarder.
    $w = 'sauvage = 1 AND created_at >= ?'; $a = [date('Y-m-d H:i:s', time() - 30 * 86400)];
    if ($shop !== null) { $w .= ' AND shop_id = ?'; $a[] = $shop; }
    $sauvages = array_map(static fn ($r) => [
        'id' => (int) $r['id'], 'magasinId' => $r['shop_id'], 'boutique' => $r['boutique'],
        'statut' => $r['statut'], 'score' => (int) $r['score'],
        'texte' => mb_substr((string) ($r['texte'] ?? ''), 0, 280), 'message' => $r['message'],
        'lien' => $r['lien'], 'publieLe' => $r['publie_le'] !== null ? substr((string) $r['publie_le'], 0, 16) : null,
        'quand' => substr((string) $r['created_at'], 0, 16),
        'ecarts' => array_map(static fn ($e) => ['libelle' => $e['libelle'] ?? ($e['regle'] ?? ''), 'gravite' => $e['gravite'] ?? 'mineur', 'correction' => $e['correction'] ?? ''],
            json_decode((string) ($r['ecarts_json'] ?? '[]'), true) ?: []),
    ], Db::rows('SELECT * FROM brand_guard_checks WHERE ' . $w . ' ORDER BY created_at DESC, id DESC LIMIT 60', $a));
    return [
        'stats' => $stats,
        'sauvages' => $sauvages,
        'charteVersion' => bgCharteVersion(),
        'meta' => class_exists('MetaGraph') ? MetaGraph::statut() : null,
    ];
}

/* --- avis Google (ceo_shop_reputation) ---------------------------------------------- */

/**
 * La réputation, telle que GET /reputation la sert : la note et le nombre d'avis
 * viennent de Google, la moyenne réseau est pondérée par les avis, « avis5Requis »
 * dit combien d'avis 5★ ramènent à la cible. Rien n'est recompté ici. En vue
 * magasin, le réseau reste affiché à côté : c'est le point de comparaison.
 */
function mhAvis(?string $shop): array
{
    $r = ep_reputation();
    if (!empty($r['indispo'])) { throw new RuntimeException((string) ($r['raison'] ?? 'réputation indisponible')); }
    $magasins = $r['magasins'] ?? [];
    if ($shop !== null) {
        $magasins = array_values(array_filter($magasins, static fn ($m) => (string) $m['id'] === $shop));
    }
    $co = $r['connecteur'] ?? [];
    return [
        'cible' => $r['cible'],
        'reseau' => $r['reseau'] ?? [],
        'magasins' => $magasins,
        // L'état du connecteur Google, sans la clé : configuré, fiches raccordées, dernière synchro.
        'connecteur' => ['configure' => !empty($co['configure']), 'raccordes' => (int) ($co['raccordes'] ?? 0),
            'derniereSynchro' => $co['derniereSynchro'] ?? null, 'etat' => $co['etat'] ?? null, 'erreur' => $co['erreur'] ?? null],
    ];
}

/** Ce que les avis demandent : une fiche à raccorder, un magasin sous la cible, une synchro trop vieille. */
function mhAFaireAvis(array $avis, string $auj): array
{
    if (!($avis['dispo'] ?? false)) { return []; }
    $out = [];
    $cible = (float) $avis['cible'];
    foreach ($avis['magasins'] as $m) {
        if (($m['placeId'] ?? null) === null) {
            $out[] = ['canal' => 'avis', 'niveau' => 'normal', 'titre' => 'Fiche Google à raccorder — ' . $m['nom'],
                'detail' => 'Sans fiche raccordée, la note du magasin ne se suit pas.', 'cible' => ['onglet' => 'social', 'vue' => 'avis']];
        } elseif ($m['note'] !== null && $m['note'] < $cible) {
            $out[] = ['canal' => 'avis', 'niveau' => 'normal', 'titre' => 'Note Google sous la cible — ' . $m['nom'],
                'detail' => number_format((float) $m['note'], 2, ',', '') . ' pour ' . number_format($cible, 1, ',', '')
                    . ($m['avis5Requis'] !== null ? ' · ' . $m['avis5Requis'] . ' avis 5★ pour l’atteindre' : ''),
                'cible' => ['onglet' => 'social', 'vue' => 'avis']];
        }
    }
    $s = $avis['connecteur']['derniereSynchro'] ?? null;
    if ($avis['connecteur']['configure'] && $avis['connecteur']['raccordes'] > 0 && ($s === null || substr($s, 0, 10) < date('Y-m-d', strtotime($auj . ' -7 days')))) {
        $out[] = ['canal' => 'avis', 'niveau' => 'normal', 'titre' => 'Avis Google non relus depuis ' . ($s === null ? 'toujours' : 'le ' . mhJour($s)),
            'detail' => 'La synchronisation est un geste (chaque fiche relue est facturée par Google).', 'cible' => ['onglet' => 'social', 'vue' => 'avis']];
    }
    return $out;
}

/* --- ce qui demande un geste, tous canaux confondus --------------------------------- */

/**
 * La liste « à faire » : ce qui attend quelqu'un, du plus pressé au moins pressé.
 *
 * La seule règle qui demande les trois canaux à la fois est la dernière : une
 * campagne en cours ou qui démarre sous sept jours sans aucun post ni aucune
 * newsletter sur sa période. Les tables ne relient pas un post à sa campagne ;
 * on rapproche donc par les DATES — un post prévu ou publié entre le début et
 * la fin, un envoi programmé ou parti dans la même fenêtre.
 */
function mhAFaire(array $camp, array $mail, array $soc, array $charte, ?string $shop, string $auj): array
{
    $out = [];
    $dans = static fn (?string $d, int $jours): bool => $d !== null && substr($d, 0, 10) <= date('Y-m-d', strtotime($auj . ' +' . $jours . ' days'));
    $item = static function (string $canal, string $niveau, string $titre, string $detail, array $cible = []) use (&$out): void {
        $out[] = ['canal' => $canal, 'niveau' => $niveau, 'titre' => $titre, 'detail' => $detail, 'cible' => $cible];
    };

    if ($soc['dispo'] ?? false) {
        foreach ($soc['liste'] as $p) {
            $quand = $p['publierLe'] ?? null;
            $titre = mhExtrait((string) $p['message']);
            $mag = $p['magasin'] ?? 'Réseau';
            if ($p['statut'] === 'a_valider' && $shop === null) {
                $item('social', $dans($quand, 2) ? 'urgent' : 'normal', 'Post à valider — ' . $mag,
                    '« ' . $titre . ' »' . ($p['agent']['note'] !== null ? ' · agent ' . $p['agent']['note'] . '/5' : '') . ($quand ? ' · prévu le ' . mhJour($quand) : ''),
                    ['onglet' => 'social', 'post' => $p['id']]);
            } elseif ($p['statut'] === 'a_controler') {
                $item('social', 'normal', 'Post en attente du contrôle — ' . $mag, '« ' . $titre . ' » : relancer l’agent', ['onglet' => 'social', 'post' => $p['id']]);
            } elseif ($p['statut'] === 'valide' && $quand !== null && substr($quand, 0, 10) <= $auj) {
                $item('social', 'urgent', 'Post validé, pas encore marqué publié — ' . $mag,
                    '« ' . $titre . ' » devait sortir le ' . mhJour($quand) . '. Marqué publié avec son id Facebook, Brand Guard ne le comptera pas comme sauvage.',
                    ['onglet' => 'social', 'post' => $p['id']]);
            } elseif ($p['statut'] === 'refuse' && $shop !== null && ($p['decision']['le'] ?? '') >= date('Y-m-d', strtotime($auj . ' -14 days'))) {
                $item('social', 'normal', 'Post refusé — à corriger', '« ' . $titre . ' » · ' . trim(($p['decision']['famille'] ?? '') . ' · ' . ($p['decision']['type'] ?? ''), ' ·'),
                    ['onglet' => 'social', 'post' => $p['id']]);
            }
        }
    }
    if (($charte['dispo'] ?? false) && $charte['sauvages']) {
        $n = count($charte['sauvages']);
        $item('charte', 'urgent', $n . ' post' . ($n > 1 ? 's' : '') . ' sauvage' . ($n > 1 ? 's' : '') . ' sur 30 jours',
            'Publiés sur la page sans passer par la demande de publication.', ['onglet' => 'social', 'vue' => 'sauvages']);
    }
    if ($mail['dispo'] ?? false) {
        $prog = array_values(array_filter($mail['liste'], static fn ($c) => !$c['exemple'] && $c['statut'] === 'sched' && $c['mode'] !== 'auto'));
        foreach ($prog as $c) {
            if (!$c['testEnvoye'] && $dans($c['envoiLe'], 7)) {
                $item('email', 'urgent', 'Newsletter sans envoi test — ' . $c['nom'], 'Programmée le ' . mhJour($c['envoiLe']) . ' : personne ne l’a encore reçue en test.', ['onglet' => 'email']);
            }
        }
        if ($prog && $mail['test']) {
            $item('email', 'normal', 'Mode test : aucune newsletter ne partira', count($prog) . ' programmée' . (count($prog) > 1 ? 's' : '') . ', mais l’envoi réel est coupé (Envois et bases).', ['onglet' => 'email']);
        }
    }
    if ($camp['dispo'] ?? false) {
        $posts = ($soc['dispo'] ?? false) ? $soc['liste'] : [];
        $mails = ($mail['dispo'] ?? false) ? array_filter($mail['liste'], static fn ($c) => !$c['exemple']) : [];
        foreach ($camp['liste'] as $c) {
            if ($c['phase'] === 'brouillon' && $c['debut'] !== null && $c['debut'] >= $auj && $dans($c['debut'], 14)) {
                $item('campagne', 'urgent', 'Campagne encore en brouillon — ' . $c['nom'], 'Elle démarre le ' . mhJour($c['debut']) . '.', ['onglet' => 'campagnes', 'campagne' => $c['id']]);
            }
            $proche = $c['phase'] === 'en_cours' || ($c['phase'] === 'a_venir' && $dans($c['debut'], 7));
            if (!$proche || $c['debut'] === null) { continue; }
            $fin = $c['fin'] ?? $c['debut'];
            $danz = static fn (?string $d): bool => $d !== null && substr($d, 0, 10) >= $c['debut'] && substr($d, 0, 10) <= $fin;
            $nPosts = count(array_filter($posts, static fn ($p) => $p['statut'] !== 'refuse' && $danz($p['publie']['le'] ?? $p['publierLe'] ?? null)));
            $nMails = count(array_filter($mails, static fn ($m) => $danz($m['envoiLe'])));
            if ($nPosts === 0 && $nMails === 0) {
                $item('campagne', 'normal', 'Aucune prise de parole pour « ' . $c['nom'] . ' »',
                    'Ni post ni newsletter entre le ' . mhJour($c['debut']) . ' et le ' . mhJour($fin) . '.', ['onglet' => 'campagnes', 'campagne' => $c['id']]);
            }
        }
    }
    usort($out, static fn ($a, $b) => ($a['niveau'] === 'urgent' ? 0 : 1) <=> ($b['niveau'] === 'urgent' ? 0 : 1));
    return array_slice($out, 0, 40);
}

function mhExtrait(string $t): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    return mb_strlen($t) > 70 ? mb_substr($t, 0, 70) . '…' : $t;
}

function mhJour(?string $d): string
{
    return $d === null || strlen($d) < 10 ? '—' : substr($d, 8, 2) . '/' . substr($d, 5, 2);
}
