<?php
declare(strict_types=1);

/**
 * La publication — étape 4 du flux marketing (demande du 13/10/2026).
 *
 * Un post se rédige, l'agent le contrôle, la marque le valide ; jusqu'ici il fallait
 * ensuite le recopier à la main sur Facebook et revenir cliquer « Marquer publié ».
 * Le portail le publie lui-même, sur chacun des réseaux choisis :
 *  - Facebook : la page du magasin (mar_bg_page, résolue par Brand Guard) ou celle
 *    de la marque pour un post réseau ;
 *  - Instagram : le compte professionnel relié à cette même page ;
 *  - LinkedIn : la page entreprise de la marque — un post réseau seulement.
 *
 * Une ligne par post et par réseau dans mar_social_publication : programmée à
 * l'heure prévue du post, publiée par l'horloge (bin/social_cron.sh, toutes les
 * 5 minutes) ou tout de suite à la demande. Quand TOUS les réseaux d'un post sont
 * publiés, le post passe « publié », avec l'identifiant Facebook — celui que Brand
 * Guard reconnaîtra en relisant la page : plus de faux « post sauvage ».
 *
 * Trois verrous, dans l'ordre :
 *  1. la publication RÉELLE est un réglage, éteint par défaut — comme l'envoi des
 *     newsletters. Éteint : rien ne part, et l'écran le dit ;
 *  2. un post ne se publie que VALIDÉ, et chaque réseau vérifie d'abord ce qu'il
 *     exige (Instagram : un visuel ; LinkedIn : un post de la marque ; longueurs) ;
 *  3. les secrets (System User Meta, jeton LinkedIn) vivent en variables
 *     d'environnement ou dans config/config.php — jamais en base, jamais au navigateur.
 */

const SP_RESEAUX = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn'];
const SP_STATUTS = ['programme', 'en_cours', 'publie', 'erreur', 'annule'];
const SP_ESSAIS_MAX = 3;          // au-delà, l'horloge ne réessaie plus : un humain regarde
const SP_LOT = 20;                // publications par passage d'horloge
const SP_MEDIA_MAX = 8 * 1024 * 1024;

/** La table, et deux colonnes de plus sur les posts : leurs réseaux et leur campagne. */
function ensureSocialPublication(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS mar_social_publication ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'post_id VARCHAR(16) NOT NULL,'
        . 'reseau VARCHAR(12) NOT NULL,'                       // facebook | instagram | linkedin
        . "statut VARCHAR(10) NOT NULL DEFAULT 'programme',"   // programme | en_cours | publie | erreur | annule
        . 'prevu_le DATETIME NOT NULL,'
        . 'publie_le DATETIME NULL,'
        . 'cible VARCHAR(80) NULL,'                            // la page, le compte, l'organisation
        . 'externe_id VARCHAR(120) NULL,'                      // l'identifiant rendu par le réseau
        . 'lien VARCHAR(400) NULL,'
        . 'erreur VARCHAR(400) NULL,'
        . 'tentatives TINYINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'cree_le DATETIME NOT NULL,'
        . 'maj_le DATETIME NULL,'
        . 'UNIQUE KEY u_post_reseau (post_id, reseau),'
        . 'KEY k_du (statut, prevu_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    foreach (["reseaux VARCHAR(40) NOT NULL DEFAULT 'facebook'", 'campagne_id INT NULL', 'KEY k_campagne (campagne_id)'] as $col) {
        try { Db::exec('ALTER TABLE mar_social_post ADD ' . (str_starts_with($col, 'KEY') ? '' : 'COLUMN ') . $col); } catch (Throwable $e) { /* déjà là */ }
    }
    if ((string) setting('socialJeton', '') === '') {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value', ['socialJeton', json_encode(bin2hex(random_bytes(24)))]);
    }
}

/** Les réglages du portail — rien de secret : les jetons sont ailleurs. */
function spReglages(): array
{
    $s = setting('socialPublication', []);
    if (!is_array($s)) { $s = []; }
    return ['reelle' => !empty($s['reelle']), 'auto' => !empty($s['auto']),
        'pageMarque' => preg_replace('/\D/', '', (string) ($s['pageMarque'] ?? '')),
        'linkedinOrganisation' => preg_replace('/\D/', '', (string) ($s['linkedinOrganisation'] ?? '')),
        'linkedinVersion' => preg_match('/^\d{6}$/', (string) ($s['linkedinVersion'] ?? '')) ? (string) $s['linkedinVersion'] : ''];
}

/** Les réseaux d'un post (colonne `reseaux`, « facebook » par défaut). */
function spReseauxDe(array $post): array
{
    $brut = $post['reseaux'] ?? 'facebook';
    $l = is_array($brut) ? $brut : explode(',', (string) $brut);
    $l = array_values(array_intersect(array_keys(SP_RESEAUX), array_map('trim', $l)));
    return $l ?: ['facebook'];
}

/** Les visuels d'un post : les adresses publiques portées par ses médias. */
function spImages(array $medias): array
{
    $out = [];
    foreach ($medias as $m) {
        $u = is_array($m) ? (string) ($m['url'] ?? '') : '';
        if (preg_match('#^https?://#', $u)) { $out[] = ['url' => $u, 'alt' => is_array($m) ? ($m['alt'] ?? null) : null]; }
    }
    return $out;
}

/**
 * Ce qu'un réseau exige, vérifié AVANT d'appeler qui que ce soit. Fonction pure :
 * `problemes` empêche la publication, `avertissements` la laisse passer en le disant.
 *
 * @param array{message:string, lien:?string, medias:array, magasinId:?string} $post
 */
function spPreparer(array $post, string $reseau): array
{
    $p = []; $a = [];
    $txt = trim((string) ($post['message'] ?? ''));
    $images = spImages((array) ($post['medias'] ?? []));
    $nHash = preg_match_all('/#[\p{L}\p{N}_]+/u', $txt);
    $long = mb_strlen($txt, 'UTF-8');
    if ($reseau === 'facebook') {
        if ($txt === '' && !$images) { $p[] = 'un texte ou un visuel'; }
        if (count((array) ($post['medias'] ?? [])) > count($images)) { $a[] = 'un visuel sans adresse ne partira pas — téléversez-le'; }
    } elseif ($reseau === 'instagram') {
        if (!$images) { $p[] = 'Instagram exige un visuel téléversé (adresse publique)'; }
        if (count($images) > 10) { $p[] = '10 visuels au plus (carrousel)'; }
        if ($long > 2200) { $p[] = 'la légende dépasse 2 200 caractères (' . $long . ')'; }
        if ($nHash > 30) { $p[] = '30 hashtags au plus (' . $nHash . ')'; }
        if (!empty($post['lien'])) { $a[] = 'le lien ne sera pas cliquable sur Instagram'; }
    } elseif ($reseau === 'linkedin') {
        if (!empty($post['magasinId'])) { $p[] = 'LinkedIn publie sur la page de la marque : un post de magasin n’y part pas'; }
        if ($long > 3000) { $p[] = 'le texte dépasse 3 000 caractères (' . $long . ')'; }
        if ($txt === '') { $p[] = 'un texte'; }
        if (count($images) > 20) { $p[] = '20 visuels au plus'; }
    } else {
        $p[] = 'réseau inconnu';
    }
    return ['ok' => $p === [], 'problemes' => $p, 'avertissements' => $a];
}

/** Le post tel que la publication le lit (colonnes de la table → champs). */
function spPost(string $id): ?array
{
    $r = Db::row('SELECT p.*, s.name AS shop_name FROM mar_social_post p LEFT JOIN ceo_shop s ON s.id = p.shop_id WHERE p.id = ?', [$id]);
    if ($r === null) { return null; }
    return ['id' => $r['id'], 'magasinId' => $r['shop_id'], 'magasin' => $r['shop_name'], 'message' => (string) $r['message'],
        'lien' => $r['link'], 'medias' => $r['medias_json'] !== null ? (json_decode((string) $r['medias_json'], true) ?: []) : [],
        'prevuLe' => $r['planned_at'], 'statut' => $r['status'], 'reseaux' => spReseauxDe($r), 'brut' => $r];
}

/** La page Facebook où publier : celle du magasin, ou celle de la marque pour un post réseau. */
function spPageDe(?string $magasinId): string
{
    if ($magasinId === null || $magasinId === '') {
        $p = spReglages()['pageMarque'];
        if ($p === '') { throw new RuntimeException('page Facebook de la marque à renseigner (Paramètres → Publication)'); }
        return $p;
    }
    $r = Db::row('SELECT page_id FROM mar_bg_page WHERE shop_id = ? AND actif = 1 AND page_id IS NOT NULL AND page_id <> \'\' ORDER BY id LIMIT 1', [$magasinId]);
    if ($r === null) { throw new RuntimeException('page Facebook du magasin non résolue (Paramètres → Pages Facebook → Résoudre)'); }
    return (string) $r['page_id'];
}

/** Le token d'une page, demandé à Meta au moment de s'en servir (rien de secret en base). */
function spTokenPage(string $pageId): string
{
    static $tokens = null;
    if ($tokens === null) { $tokens = MetaGraph::tokensPages(); }
    if (!isset($tokens[$pageId])) { throw new RuntimeException('le System User Meta n’a pas accès à la page ' . $pageId . ' (à lui attribuer dans le Business Manager)'); }
    return $tokens[$pageId]['token'];
}

/** Les octets d'un visuel, pour LinkedIn : nos fichiers se lisent sur disque, les autres se téléchargent. */
function spOctets(string $url): string
{
    if (isset($GLOBALS['spLecteur']) && is_callable($GLOBALS['spLecteur'])) { return (string) ($GLOBALS['spLecteur'])($url); }
    if (preg_match('#/uploads/social/([a-f0-9]{8,40}\.jpg)$#', $url, $m)) {
        $f = dirname(__DIR__) . '/public/uploads/social/' . $m[1];
        if (is_file($f)) { return (string) file_get_contents($f); }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_MAXFILESIZE => SP_MEDIA_MAX]);
    $o = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($o === false || $code < 200 || $code >= 300) { throw new RuntimeException('visuel injoignable : ' . $url); }
    return (string) $o;
}

/** Publie UNE ligne (un post sur un réseau). La ligne dit toujours où l'on en est. */
function spPublierUne(int $pubId): array
{
    $pub = Db::row('SELECT * FROM mar_social_publication WHERE id = ?', [$pubId]);
    if ($pub === null) { throw new RuntimeException('publication inconnue'); }
    if ($pub['statut'] === 'publie') { return $pub; }
    $post = spPost((string) $pub['post_id']);
    if ($post === null) { throw new RuntimeException('post inconnu'); }
    // Le post a changé d'état depuis la programmation (publié à la main, refusé) : on ne publie plus.
    if ($post['statut'] !== 'valide') {
        Db::exec("UPDATE mar_social_publication SET statut = 'annule', erreur = ?, maj_le = ? WHERE id = ?", ['post ' . $post['statut'] . ' entre-temps', date('Y-m-d H:i:s'), $pubId]);
        return Db::row('SELECT * FROM mar_social_publication WHERE id = ?', [$pubId]) ?? $pub;
    }
    Db::exec("UPDATE mar_social_publication SET statut = 'en_cours', tentatives = tentatives + 1, maj_le = ? WHERE id = ?", [date('Y-m-d H:i:s'), $pubId]);
    $reseau = (string) $pub['reseau'];
    try {
        $prep = spPreparer($post, $reseau);
        if (!$prep['ok']) { throw new RuntimeException(implode(' ; ', $prep['problemes'])); }
        $images = spImages($post['medias']);
        if ($reseau === 'linkedin') {
            $cible = LinkedInApi::config()['organisation'];
            $r = LinkedInApi::publier($post['message'], array_map(static fn ($i) => ['octets' => spOctets($i['url']), 'alt' => $i['alt']], $images), $post['lien']);
        } else {
            $page = spPageDe($post['magasinId']);
            $token = spTokenPage($page);
            if ($reseau === 'instagram') {
                $cible = MetaGraph::instagramDePage($page, $token);
                if ($cible === null) { throw new RuntimeException('aucun compte Instagram professionnel relié à la page ' . $page); }
                $r = MetaGraph::publierInstagram($cible, $token, $post['message'], array_column($images, 'url'));
            } else {
                $cible = $page;
                $r = MetaGraph::publierPage($page, $token, $post['message'], $post['lien'], array_column($images, 'url'));
            }
        }
        Db::exec("UPDATE mar_social_publication SET statut = 'publie', publie_le = ?, cible = ?, externe_id = ?, lien = ?, erreur = NULL, maj_le = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), (string) $cible, $r['id'], $r['lien'], date('Y-m-d H:i:s'), $pubId]);
        journalAdd('Publication', 'Publication', $post['magasin'] ?? 'Réseau', 'Post « ' . fbTitre($post['message']) . ' » publié sur ' . SP_RESEAUX[$reseau]);
    } catch (Throwable $e) {
        $msg = mb_substr($e->getMessage(), 0, 380);
        Db::exec("UPDATE mar_social_publication SET statut = 'erreur', erreur = ?, maj_le = ? WHERE id = ?", [$msg, date('Y-m-d H:i:s'), $pubId]);
        journalAdd('Publication', 'Erreur', $post['magasin'] ?? 'Réseau', 'Post « ' . fbTitre($post['message']) . ' » non publié sur ' . (SP_RESEAUX[$reseau] ?? $reseau) . ' : ' . $msg);
    }
    spConsolider((string) $pub['post_id']);
    return Db::row('SELECT * FROM mar_social_publication WHERE id = ?', [$pubId]) ?? $pub;
}

/**
 * Le post passe « publié » quand chacun de ses réseaux l'est — avec l'identifiant
 * Facebook, pour que Brand Guard le reconnaisse en relisant la page.
 */
function spConsolider(string $postId): void
{
    $l = Db::rows("SELECT reseau, statut, publie_le, externe_id FROM mar_social_publication WHERE post_id = ? AND statut <> 'annule'", [$postId]);
    if (!$l || array_filter($l, static fn ($r) => $r['statut'] !== 'publie')) { return; }
    $fb = null; $le = null;
    foreach ($l as $r) {
        if ($r['reseau'] === 'facebook') { $fb = $r['externe_id']; }
        if ($le === null || $r['publie_le'] < $le) { $le = $r['publie_le']; }
    }
    Db::exec("UPDATE mar_social_post SET status = 'publie', published_at = ?, fb_post_id = COALESCE(?, fb_post_id) WHERE id = ? AND status <> 'publie'", [$le, $fb, $postId]);
}

/**
 * Programme (ou publie tout de suite) un post validé sur ses réseaux.
 * @param list<string>|null $reseaux null : ceux du post
 */
function spProgrammer(string $postId, ?array $reseaux, bool $maintenant): array
{
    $reg = spReglages();
    if (!$reg['reelle']) {
        http_response_code(409);
        return ['error' => 'Publication réelle désactivée — Paramètres → Publication. Rien n’est parti.'];
    }
    $post = spPost($postId);
    if ($post === null) { http_response_code(404); return ['error' => 'post inconnu']; }
    if ($post['statut'] !== 'valide') { http_response_code(409); return ['error' => 'seul un post validé se publie (statut : ' . $post['statut'] . ')']; }
    $cibles = $reseaux === null ? $post['reseaux'] : array_values(array_intersect($post['reseaux'], $reseaux));
    if (!$cibles) { http_response_code(422); return ['error' => 'aucun réseau à publier pour ce post']; }
    $prets = []; $bloques = [];
    foreach ($cibles as $r) {
        $prep = spPreparer($post, $r);
        if ($prep['ok']) { $prets[] = $r; } else { $bloques[$r] = implode(', ', $prep['problemes']); }
    }
    // Rien de prêt, ou un réseau demandé nommément qui ne l'est pas : rien ne part.
    if (!$prets || ($reseaux !== null && $bloques)) {
        http_response_code(422);
        return ['error' => 'à corriger avant publication — ' . implode(' · ', array_map(static fn ($r, $pb) => SP_RESEAUX[$r] . ' : ' . $pb, array_keys($bloques), $bloques))];
    }
    $quand = $maintenant || $post['prevuLe'] === null || $post['prevuLe'] < date('Y-m-d H:i:s') ? date('Y-m-d H:i:s') : (string) $post['prevuLe'];
    // Sinon les réseaux prêts partent ; celui qui ne peut pas suivre (Instagram sans visuel…)
    // est annulé avec sa raison, lisible sur la carte : un réseau ne retient pas les autres.
    foreach ($bloques as $r => $pb) {
        Db::exec("INSERT INTO mar_social_publication (post_id, reseau, statut, prevu_le, erreur, cree_le) VALUES (?, ?, 'annule', ?, ?, ?)
                  ON DUPLICATE KEY UPDATE statut = IF(statut = 'publie', statut, 'annule'), erreur = IF(statut = 'publie', erreur, VALUES(erreur)), maj_le = VALUES(cree_le)",
            [$postId, $r, $quand, mb_substr($pb, 0, 250), date('Y-m-d H:i:s')]);
    }
    $cibles = $prets;
    foreach ($cibles as $r) {
        Db::exec("INSERT INTO mar_social_publication (post_id, reseau, statut, prevu_le, cree_le) VALUES (?, ?, 'programme', ?, ?)
                  ON DUPLICATE KEY UPDATE statut = IF(statut = 'publie', statut, 'programme'), prevu_le = IF(statut = 'publie', prevu_le, VALUES(prevu_le)), erreur = IF(statut = 'publie', erreur, NULL), maj_le = VALUES(cree_le)",
            [$postId, $r, $quand, date('Y-m-d H:i:s')]);
    }
    journalAdd('Publication', 'Publication', $post['magasin'] ?? 'Réseau', 'Post « ' . fbTitre($post['message']) . ' » ' . ($maintenant ? 'publié maintenant' : 'programmé le ' . substr($quand, 0, 16)) . ' sur ' . implode(', ', array_map(static fn ($r) => SP_RESEAUX[$r], $cibles))
        . ($bloques ? ' — non publié sur ' . implode(', ', array_map(static fn ($r, $pb) => SP_RESEAUX[$r] . ' (' . $pb . ')', array_keys($bloques), $bloques)) : ''));
    if ($maintenant) {
        $ids = array_column(Db::rows("SELECT id FROM mar_social_publication WHERE post_id = ? AND statut = 'programme'", [$postId]), 'id');
        foreach ($ids as $id) { spPublierUne((int) $id); }
    }
    return ['ok' => true, 'publications' => spPublications([$postId])[$postId] ?? []];
}

/** Programme automatiquement à la validation, si le réglage le demande. Jamais une raison d'échouer. */
function spAutoProgrammer(string $postId): void
{
    $reg = spReglages();
    if (!$reg['reelle'] || !$reg['auto']) { return; }
    try {
        $r = spProgrammer($postId, null, false);
        if (isset($r['error'])) { http_response_code(200); journalAdd('Publication', 'Erreur', '—', 'Programmation automatique impossible : ' . $r['error']); }
    } catch (Throwable $e) {
        journalAdd('Publication', 'Erreur', '—', 'Programmation automatique impossible : ' . $e->getMessage());
    }
}

/** Les publications de plusieurs posts : post_id → lignes. */
function spPublications(array $postIds): array
{
    if (!$postIds) { return []; }
    $out = [];
    $place = implode(',', array_fill(0, count($postIds), '?'));
    foreach (Db::rows('SELECT * FROM mar_social_publication WHERE post_id IN (' . $place . ') ORDER BY FIELD(reseau, \'facebook\', \'instagram\', \'linkedin\')', array_values($postIds)) as $r) {
        $out[(string) $r['post_id']][] = ['id' => (int) $r['id'], 'reseau' => $r['reseau'], 'statut' => $r['statut'],
            'prevuLe' => substr((string) $r['prevu_le'], 0, 16), 'publieLe' => $r['publie_le'] !== null ? substr((string) $r['publie_le'], 0, 16) : null,
            'lien' => $r['lien'], 'externeId' => $r['externe_id'], 'erreur' => $r['erreur'], 'tentatives' => (int) $r['tentatives']];
    }
    return $out;
}

/* --- routes -------------------------------------------------------------------------- */

/** POST /marketing/social/posts/{id}/publier {quand: maintenant|programme, reseaux?} */
function wr_social_publier(string $id): array
{
    $b = body();
    $reseaux = isset($b['reseaux']) && is_array($b['reseaux']) ? array_values(array_filter($b['reseaux'], 'is_string')) : null;
    return spProgrammer($id, $reseaux, ($b['quand'] ?? 'programme') === 'maintenant');
}

/** DELETE /marketing/social/posts/{id}/publier — annule ce qui n'est pas encore parti. */
function wr_social_annuler(string $id): array
{
    $n = Db::exec("UPDATE mar_social_publication SET statut = 'annule', maj_le = ? WHERE post_id = ? AND statut IN ('programme', 'erreur')", [date('Y-m-d H:i:s'), $id]);
    $post = spPost($id);
    journalAdd('Publication', 'Publication', $post['magasin'] ?? 'Réseau', 'Programmation annulée (' . $n . ' réseau(x)) pour « ' . fbTitre((string) ($post['message'] ?? '')) . ' »');
    return ['ok' => true, 'annulees' => $n, 'publications' => spPublications([$id])[$id] ?? []];
}

/**
 * GET /marketing/publication/cron?jeton= — l'horloge, toutes les 5 minutes.
 * Publie ce qui est dû ; réessaie une erreur au plus SP_ESSAIS_MAX fois, 15 minutes après.
 */
function ep_social_cron(): array
{
    if (!hash_equals((string) setting('socialJeton', ''), (string) ($_GET['jeton'] ?? ''))) { http_response_code(403); return ['error' => 'jeton']; }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['socialCronDernier', json_encode(date('Y-m-d H:i'))]);
    if (!spReglages()['reelle']) { return ['fait' => false, 'motif' => 'publication réelle désactivée']; }
    @set_time_limit(280);
    $dus = Db::rows("SELECT id FROM mar_social_publication
                      WHERE (statut = 'programme' AND prevu_le <= ?)
                         OR (statut = 'erreur' AND tentatives < ? AND maj_le <= ?)
                      ORDER BY prevu_le LIMIT " . SP_LOT, [date('Y-m-d H:i:s'), SP_ESSAIS_MAX, date('Y-m-d H:i:s', time() - 900)]);
    $faits = [];
    foreach ($dus as $d) { $r = spPublierUne((int) $d['id']); $faits[] = ['id' => (int) $d['id'], 'reseau' => $r['reseau'], 'statut' => $r['statut'], 'erreur' => $r['erreur']]; }
    return ['fait' => true, 'publications' => $faits];
}

/** GET /marketing/publication/etat — réglages, accès, et ce qu'il manque. Jamais un secret. */
function ep_social_etat(): array
{
    $reg = spReglages();
    $pages = [];
    try {
        foreach (Db::rows("SELECT boutique, shop_id, page_id, page_nom, statut_connexion FROM mar_bg_page WHERE actif = 1 ORDER BY boutique") as $p) {
            $pages[] = ['boutique' => $p['boutique'], 'magasinId' => $p['shop_id'], 'pageId' => $p['page_id'], 'pageNom' => $p['page_nom'], 'statut' => $p['statut_connexion']];
        }
    } catch (PDOException $e) { $pages = []; }
    $n = [];
    foreach (Db::rows('SELECT statut, COUNT(*) n FROM mar_social_publication GROUP BY statut') as $r) { $n[$r['statut']] = (int) $r['n']; }
    $meta = MetaGraph::statut();
    return ['reglages' => $reg, 'meta' => $meta, 'linkedin' => LinkedInApi::statut(), 'pages' => $pages,
        'reseaux' => ['facebook' => !empty($meta['configure']), 'instagram' => !empty($meta['configure']), 'linkedin' => LinkedInApi::configured()],
        'compteurs' => $n, 'cronDernier' => setting('socialCronDernier'), 'base' => function_exists('rapBaseUrl') ? rapBaseUrl() : ''];
}

/** PUT /marketing/publication/reglages {reelle?, auto?, pageMarque?, linkedinOrganisation?, linkedinVersion?} */
function wr_social_reglages(): array
{
    $b = body();
    $s = setting('socialPublication', []);
    if (!is_array($s)) { $s = []; }
    foreach (['reelle', 'auto'] as $k) { if (array_key_exists($k, $b)) { $s[$k] = (bool) $b[$k]; } }
    foreach (['pageMarque', 'linkedinOrganisation'] as $k) { if (array_key_exists($k, $b)) { $s[$k] = preg_replace('/\D/', '', (string) $b[$k]); } }
    if (array_key_exists('linkedinVersion', $b)) { $s['linkedinVersion'] = preg_match('/^\d{6}$/', (string) $b['linkedinVersion']) ? (string) $b['linkedinVersion'] : ''; }
    if (!empty($s['reelle']) && !MetaGraph::configured() && !LinkedInApi::configured()) {
        http_response_code(422);
        return ['error' => 'aucun accès configuré : ni System User Meta (META_SYSTEM_TOKEN), ni jeton LinkedIn — la publication réelle ne partirait nulle part'];
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['socialPublication', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    journalAdd((string) ($b['par'] ?? 'CEO'), 'Paramètre', 'Marketing', 'Publication : ' . (!empty($s['reelle']) ? 'réelle' : 'désactivée') . (!empty($s['auto']) ? ', programmée à la validation' : ''));
    return ['ok' => true] + ep_social_etat();
}

/** POST /marketing/publication/tester {reseau} — un vrai appel, le moins cher qui prouve quelque chose. */
function wr_social_tester(): array
{
    $reseau = (string) (body()['reseau'] ?? '');
    try {
        if ($reseau === 'linkedin') { [$ok, $msg] = LinkedInApi::tester(); return ['reseau' => $reseau, 'ok' => $ok, 'message' => $msg]; }
        if (!MetaGraph::configured()) { return ['reseau' => $reseau, 'ok' => false, 'message' => 'System User Meta absent (META_SYSTEM_TOKEN)']; }
        $tokens = MetaGraph::tokensPages();
        $pages = array_values(array_filter(array_merge([spReglages()['pageMarque']], array_column(Db::rows('SELECT page_id FROM mar_bg_page WHERE actif = 1 AND page_id IS NOT NULL'), 'page_id'))));
        $lignes = [];
        foreach (array_unique($pages) as $pid) {
            if (!isset($tokens[$pid])) { $lignes[] = ['page' => $pid, 'ok' => false, 'detail' => 'pas attribuée au System User']; continue; }
            if ($reseau === 'instagram') {
                $ig = MetaGraph::instagramDePage($pid, $tokens[$pid]['token']);
                $lignes[] = ['page' => $pid, 'nom' => $tokens[$pid]['nom'], 'ok' => $ig !== null, 'detail' => $ig !== null ? 'compte Instagram ' . $ig : 'aucun compte Instagram professionnel relié'];
            } else {
                $lignes[] = ['page' => $pid, 'nom' => $tokens[$pid]['nom'], 'ok' => true, 'detail' => 'publiable'];
            }
        }
        $ok = $lignes !== [] && !array_filter($lignes, static fn ($l) => !$l['ok']);
        return ['reseau' => $reseau, 'ok' => $ok, 'message' => count($tokens) . ' page(s) accessibles au System User', 'pages' => $lignes];
    } catch (Throwable $e) {
        return ['reseau' => $reseau, 'ok' => false, 'message' => $e->getMessage()];
    }
}

/**
 * POST /marketing/publication/media {data: "data:image/…;base64,…", nom} — un visuel téléversé.
 *
 * Réenregistré en JPEG (Instagram n'accepte que lui) sous public/uploads/social/,
 * nommé par son empreinte : le même visuel téléversé deux fois ne fait qu'un fichier.
 * L'adresse rendue est ABSOLUE : c'est Meta qui ira la chercher — elle doit donc être
 * publique, ce que l'écran rappelle quand le cockpit tourne sur une adresse privée.
 */
function wr_social_media(): array
{
    $b = body();
    if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#', (string) ($b['data'] ?? ''), $m)) { http_response_code(422); return ['error' => 'image JPEG, PNG ou WebP attendue']; }
    $octets = base64_decode(substr((string) $b['data'], strpos((string) $b['data'], ',') + 1), true);
    if ($octets === false || strlen($octets) > SP_MEDIA_MAX) { http_response_code(413); return ['error' => 'image illisible ou de plus de 8 Mo']; }
    $info = @getimagesizefromstring($octets);
    if ($info === false) { http_response_code(422); return ['error' => 'ce fichier n’est pas une image'] ; }
    [$l, $h] = $info;
    if ($info[2] !== IMAGETYPE_JPEG) {
        if (!function_exists('imagecreatefromstring')) { http_response_code(422); return ['error' => 'conversion en JPEG impossible sur ce serveur (GD absent) : téléversez un JPEG']; }
        $im = @imagecreatefromstring($octets);
        if ($im === false) { http_response_code(422); return ['error' => 'image illisible']; }
        $fond = imagecreatetruecolor($l, $h);
        imagefill($fond, 0, 0, imagecolorallocate($fond, 255, 255, 255));   // la transparence devient du blanc, pas du noir
        imagecopy($fond, $im, 0, 0, 0, 0, $l, $h);
        ob_start(); imagejpeg($fond, null, 88); $octets = (string) ob_get_clean();
    }
    $nom = substr(sha1($octets), 0, 20) . '.jpg';
    $dos = dirname(__DIR__) . '/public/uploads/social';
    if (!is_dir($dos) && !@mkdir($dos, 0775, true) && !is_dir($dos)) { http_response_code(500); return ['error' => 'dossier uploads/social non inscriptible']; }
    if (!is_file($dos . '/' . $nom) && @file_put_contents($dos . '/' . $nom, $octets) === false) { http_response_code(500); return ['error' => 'écriture du visuel impossible']; }
    $base = function_exists('rapBaseUrl') ? rapBaseUrl() : '';
    $av = [];
    $ratio = $h > 0 ? $l / $h : 1;
    if ($ratio < 0.8 || $ratio > 1.91) { $av[] = 'format ' . $l . '×' . $h . ' : Instagram n’accepte que du 4:5 au 1,91:1'; }
    if ($l < 1080) { $av[] = 'moins de 1 080 px de large : Facebook recompressera'; }
    $hote = (string) parse_url($base, PHP_URL_HOST);
    if ($hote === '' || $hote === 'localhost' || (filter_var($hote, FILTER_VALIDATE_IP) && !filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
        $av[] = 'adresse du cockpit non publique (' . ($hote ?: 'inconnue') . ') : Meta ne pourra pas lire ce visuel — réglez cockpitBase';
    }
    return ['ok' => true, 'url' => $base . '/uploads/social/' . $nom, 'chemin' => 'uploads/social/' . $nom, 'largeur' => $l, 'hauteur' => $h, 'poids' => strlen($octets), 'avertissements' => $av];
}
