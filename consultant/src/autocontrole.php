<?php
declare(strict_types=1);

/**
 * L'autocontrôle qualité des produits, à la tablette du magasin (24/11/2026).
 *
 * Sam, sur la fiche produit de l'app Cuisine : « un bouton autocontrôle qualité, 1. cuisson, 2. structure,
 * 3. organoleptique, avec des photos (oui / non), des corrections ; des boutons à variables (mou, très mou, dur,
 * grosseur de la croûte, retombé après cuisson) ; rapide, intuitif et complet », puis « est-ce que le produit est
 * semblable à la photo de référence ou au planogramme », puis « construire dans le cockpit consultant, avec un lien
 * test tablette ». Maquettes : formation/docs/maquettes/autocontrole-qualite/.
 *
 * - Les grilles : une par catégorie de produits, plus des critères communs à tous les produits, et des surcharges par
 *   produit (le poids du Cougnou Nature 400 g). Elles vivent dans deux réglages : le brouillon que le siège édite
 *   (autocontroleGrilles) et la version publiée que lisent les tablettes (autocontroleGrillesPubliees). Tant que rien
 *   n'est publié, les tablettes lisent les grilles d'exemple (src/data/autocontrole_grilles.json), marquées « à valider ».
 * - Le produit : le catalogue du cockpit (ep_prod_catalogue : nom, catégorie, groupe, réf.) ; sa place au planogramme
 *   standard (con_plano_std_emplacement, le jour même) ; sa photo de référence (psPhotosResoudre).
 * - Les contrôles : con_aq_controle (réponses, bilan recalculé ici, décision, suites) et con_aq_photo (photos rangées
 *   dans le dossier privé, servies par l'API au seul magasin du contrôle et au siège).
 * - Accès : /autocontrole/magasin/… au franchisé (son magasin, src/acces.php) ; le reste au siège.
 */

/* ── Les règles : sans base, sans réseau (testées par consultant/bin/tests_autocontrole.php) ─────────────── */

/** Les étapes du parcours, dans l'ordre ; les niveaux d'une réponse ; ce qu'on fait du lot. */
const AQ_ETAPES = ['cuisson', 'structure', 'organoleptique', 'presentation'];
const AQ_NIVEAUX = ['ok', 'mineur', 'majeur'];
const AQ_DECISIONS = ['vente', 'declasser', 'jeter'];
const AQ_PHOTOS = ['obligatoire', 'facultative', 'non'];

/** La réponse attendue d'un critère, sous la forme où la tablette l'envoie. */
function aqCible(array $c): string
{
    $type = (string) ($c['type'] ?? '');
    if ($type === 'oui_non') { return (string) ($c['attendu'] ?? 'oui'); }
    if ($type === 'mesure') { return (string) ($c['cible'] ?? $c['minimum'] ?? ''); }
    foreach ((array) ($c['options'] ?? []) as $o) {
        if (!empty($o['cible'])) { return (string) ($o['cle'] ?? ''); }
    }
    return '';
}

/**
 * Ce que vaut une réponse : son niveau, son libellé et, hors cible, sa correction (cause, maintenant, prochaine,
 * decision, sop, reclamation). null : pas de réponse, ou une réponse que la grille ne connaît pas.
 */
function aqEvaluer(array $c, mixed $v): ?array
{
    if ($v === null || $v === '') { return null; }
    $v = (string) $v;
    $type = (string) ($c['type'] ?? '');
    if ($type === 'oui_non') {
        if ($v !== 'oui' && $v !== 'non') { return null; }
        $lib = $v === 'oui' ? 'Oui' : 'Non';
        if ($v === (string) ($c['attendu'] ?? 'oui')) { return ['niveau' => 'ok', 'libelle' => $lib]; }
        return array_merge((array) ($c[$v] ?? []), ['libelle' => $lib]);
    }
    if ($type === 'mesure') {
        $txt = str_replace(',', '.', $v);
        if (!is_numeric($txt)) { return null; }
        $n = (float) $txt;
        $lib = rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.') . ' ' . (string) ($c['unite'] ?? '');
        $dedans = isset($c['minimum'])
            ? $n >= (float) $c['minimum']
            : abs($n - (float) ($c['cible'] ?? 0)) <= (float) ($c['tolerance'] ?? 0);
        return $dedans ? ['niveau' => 'ok', 'libelle' => trim($lib)] : array_merge((array) ($c['hors'] ?? []), ['libelle' => trim($lib)]);
    }
    foreach ((array) ($c['options'] ?? []) as $o) {
        if ((string) ($o['cle'] ?? '') === $v) { return $o; }
    }
    return null;
}

/**
 * Le bilan d'un contrôle : comptes par niveau, écarts, décision suggérée (la plus sévère des réponses hors cible),
 * réglages de la prochaine fournée sans doublon (une même consigne regroupe ses critères), critères obligatoires
 * sans réponse, et s'il faut proposer une réclamation (défaut du produit cru).
 */
function aqBilan(array $criteres, array $reponses): array
{
    $rang = ['vente' => 0, 'declasser' => 1, 'jeter' => 2];
    $n = ['ok' => 0, 'mineur' => 0, 'majeur' => 0];
    $ecarts = [];
    $actions = [];
    $manquants = [];
    $decision = 'vente';
    $reclamation = false;
    foreach ($criteres as $c) {
        $cle = (string) ($c['cle'] ?? '');
        $e = aqEvaluer($c, $reponses[$cle] ?? null);
        if ($e === null) {
            if (empty($c['facultatif'])) { $manquants[] = $cle; }
            continue;
        }
        $niv = in_array($e['niveau'] ?? '', AQ_NIVEAUX, true) ? $e['niveau'] : 'mineur';
        $n[$niv]++;
        if ($niv === 'ok') { continue; }
        $ecarts[] = ['cle' => $cle, 'libelle' => (string) ($c['libelle'] ?? $cle), 'etape' => (string) ($c['etape'] ?? ''),
            'reponse' => (string) ($e['libelle'] ?? ''), 'niveau' => $niv, 'cause' => (string) ($e['cause'] ?? ''),
            'maintenant' => (string) ($e['maintenant'] ?? ''), 'prochaine' => (string) ($e['prochaine'] ?? '')];
        $d = (string) ($e['decision'] ?? 'vente');
        if (isset($rang[$d]) && $rang[$d] > $rang[$decision]) { $decision = $d; }
        if (!empty($e['reclamation'])) { $reclamation = true; }
        $t = trim((string) ($e['prochaine'] ?? ''));
        if ($t !== '') {
            if (!isset($actions[$t])) { $actions[$t] = ['consigne' => $t, 'criteres' => []]; }
            $actions[$t]['criteres'][] = (string) ($c['libelle'] ?? $cle);
        }
    }
    return [
        'n' => $n, 'ecarts' => $ecarts, 'decision' => $decision, 'actions' => array_values($actions),
        'niveau' => $n['majeur'] ? 'majeur' : ($n['mineur'] ? 'mineur' : 'ok'),
        'manquants' => $manquants, 'reclamation' => $reclamation,
    ];
}

/**
 * La grille d'un produit : les critères de sa catégorie, puis ceux de tous les produits, chacun surchargé par les
 * valeurs propres au produit (ex. le poids du Cougnou Nature 400 g : cible 400, tolérance 20). Une surcharge ne touche
 * que des champs de valeur (cible, tolerance, minimum, unite, libelle, aide, facultatif), jamais la structure.
 */
function aqGrilleProduit(array $categorie, array $communs, array $surcharges = []): array
{
    $permis = ['cible', 'tolerance', 'minimum', 'unite', 'libelle', 'aide', 'facultatif'];
    $criteres = [];
    foreach (array_merge((array) ($categorie['criteres'] ?? []), (array) ($communs['criteres'] ?? [])) as $c) {
        $s = (array) ($surcharges[(string) ($c['cle'] ?? '')] ?? []);
        foreach ($permis as $k) {
            if (array_key_exists($k, $s)) { $c[$k] = $s[$k]; }
        }
        $criteres[] = $c;
    }
    usort($criteres, fn($a, $b) => array_search($a['etape'] ?? '', AQ_ETAPES, true) <=> array_search($b['etape'] ?? '', AQ_ETAPES, true));
    $photos = [];
    foreach (AQ_ETAPES as $e) { $photos[$e] = (string) ($categorie['photos'][$e] ?? 'facultative'); }
    return ['cle' => (string) ($categorie['cle'] ?? ''), 'libelle' => (string) ($categorie['libelle'] ?? ''),
        'quand' => (string) ($categorie['quand'] ?? ''), 'photos' => $photos,
        'consignes_photo' => (array) ($categorie['consignes_photo'] ?? []), 'criteres' => $criteres];
}

/**
 * Les erreurs d'une grille, en français, une par ligne ; [] : la grille est publiable. Vérifie ce que la tablette
 * suppose : clés uniques, étapes connues, une seule réponse attendue par échelle, et pour chaque réponse hors cible
 * une correction complète (cause, maintenant, prochaine fournée) et une décision.
 */
function aqValiderGrille(array $g, bool $communs = false): array
{
    $err = [];
    if (!$communs) {
        if (trim((string) ($g['libelle'] ?? '')) === '') { $err[] = 'La catégorie n\'a pas de nom.'; }
        foreach (AQ_ETAPES as $e) {
            if (!in_array($g['photos'][$e] ?? 'facultative', AQ_PHOTOS, true)) { $err[] = "Photo de l'étape « $e » : obligatoire, facultative ou non."; }
        }
    }
    $correction = function (array $o, string $ou) use (&$err): void {
        if (!in_array($o['niveau'] ?? '', ['mineur', 'majeur'], true)) { $err[] = "$ou : niveau « à surveiller » ou « non conforme »."; }
        foreach (['cause' => 'la cause probable', 'maintenant' => 'ce qu\'on fait maintenant', 'prochaine' => 'la correction de la prochaine fournée'] as $k => $l) {
            if (trim((string) ($o[$k] ?? '')) === '') { $err[] = "$ou : il manque $l."; }
        }
        if (!in_array($o['decision'] ?? '', AQ_DECISIONS, true)) { $err[] = "$ou : décision vente, déclasser ou jeter."; }
    };
    $vues = [];
    foreach ((array) ($g['criteres'] ?? []) as $i => $c) {
        $cle = (string) ($c['cle'] ?? '');
        $nom = trim((string) ($c['libelle'] ?? '')) ?: 'Critère ' . ($i + 1);
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $cle)) { $err[] = "$nom : clé invalide (a-z, 0-9, _)."; }
        elseif (isset($vues[$cle])) { $err[] = "$nom : la clé « $cle » est déjà prise."; }
        $vues[$cle] = true;
        if (trim((string) ($c['libelle'] ?? '')) === '') { $err[] = "$nom : pas de libellé."; }
        if (!in_array($c['etape'] ?? '', AQ_ETAPES, true)) { $err[] = "$nom : étape inconnue."; }
        $type = (string) ($c['type'] ?? '');
        if ($type === 'echelle') {
            $opts = (array) ($c['options'] ?? []);
            if (count($opts) < 2) { $err[] = "$nom : au moins deux réponses."; }
            $cibles = 0;
            $clesO = [];
            foreach ($opts as $o) {
                $co = (string) ($o['cle'] ?? '');
                $lo = trim((string) ($o['libelle'] ?? ''));
                if ($co === '' || isset($clesO[$co])) { $err[] = "$nom : chaque réponse a une clé unique."; }
                $clesO[$co] = true;
                if ($lo === '') { $err[] = "$nom : une réponse sans libellé."; }
                if (!empty($o['cible'])) { $cibles++; if (($o['niveau'] ?? '') !== 'ok') { $err[] = "$nom : la réponse attendue est « conforme »."; } }
                elseif (($o['niveau'] ?? '') !== 'ok') { $correction($o, "$nom › $lo"); }
            }
            if ($cibles !== 1) { $err[] = "$nom : une et une seule réponse attendue."; }
        } elseif ($type === 'oui_non') {
            $att = (string) ($c['attendu'] ?? '');
            if ($att !== 'oui' && $att !== 'non') { $err[] = "$nom : réponse attendue oui ou non."; }
            else { $correction((array) ($c[$att === 'oui' ? 'non' : 'oui'] ?? []), "$nom › " . ($att === 'oui' ? 'Non' : 'Oui')); }
        } elseif ($type === 'mesure') {
            if (trim((string) ($c['unite'] ?? '')) === '') { $err[] = "$nom : unité manquante."; }
            $bornes = isset($c['minimum']) ? is_numeric($c['minimum']) : (is_numeric($c['cible'] ?? null) && is_numeric($c['tolerance'] ?? null));
            if (!$bornes) { $err[] = "$nom : une cible et une tolérance, ou un minimum."; }
            $correction((array) ($c['hors'] ?? []), "$nom › hors tolérance");
        } else {
            $err[] = "$nom : type échelle, oui / non ou mesure.";
        }
    }
    if (!$communs && empty($g['criteres'])) { $err[] = 'La grille n\'a aucun critère.'; }
    return $err;
}

/**
 * Les défauts qui reviennent : pour chaque critère hors cible au moins $seuil fois dans les contrôles donnés (du plus
 * récent au plus ancien), le nombre de fois, les jours et la dernière correction proposée.
 */
function aqRecurrents(array $controles, int $seuil = 3): array
{
    $par = [];
    foreach ($controles as $k) {
        foreach ((array) ($k['ecarts'] ?? []) as $e) {
            $cle = (string) ($e['cle'] ?? '');
            if ($cle === '') { continue; }
            $par[$cle] ??= ['cle' => $cle, 'libelle' => (string) ($e['libelle'] ?? $cle), 'fois' => 0, 'jours' => [], 'prochaine' => (string) ($e['prochaine'] ?? '')];
            $par[$cle]['fois']++;
            $j = substr((string) ($k['cree_le'] ?? ''), 0, 10);
            if ($j !== '' && !in_array($j, $par[$cle]['jours'], true)) { $par[$cle]['jours'][] = $j; }
        }
    }
    $out = array_values(array_filter($par, fn($r) => $r['fois'] >= $seuil));
    usort($out, fn($a, $b) => $b['fois'] <=> $a['fois']);
    return $out;
}


/* ── Les grilles ────────────────────────────────────────────────────────────────────────────────────────── */

const AQ_REGLAGE = 'autocontroleGrilles';
const AQ_REGLAGE_PUBLIE = 'autocontroleGrillesPubliees';

/** Les grilles d'exemple livrées avec le module (à valider par la production). */
function aqGraine(): array
{
    static $g = null;
    if ($g !== null) { return $g; }
    $d = json_decode((string) @file_get_contents(__DIR__ . '/data/autocontrole_grilles.json'), true);
    if (!is_array($d)) { throw new RuntimeException('src/data/autocontrole_grilles.json illisible'); }
    return $g = $d;
}

/**
 * Les grilles. Publiées : celles que lisent les tablettes, ou à défaut l'exemple, marqué aValider. Brouillon : celui
 * que le siège édite, ou à défaut la version publiée.
 */
function aqGrilles(bool $publiees = true): array
{
    $pub = setting(AQ_REGLAGE_PUBLIE, null);
    $pub = is_array($pub) && !empty($pub['categories']) ? $pub : array_merge(aqGraine(), ['aValider' => true]);
    if ($publiees) { return $pub; }
    $br = setting(AQ_REGLAGE, null);
    return is_array($br) && !empty($br['categories']) ? $br : $pub;
}

/** Minuscules sans accents, pour rapprocher une catégorie du catalogue des mots-clés d'une grille. */
function aqNormaliser(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => "'"]);
    return (string) preg_replace('/\s+/', ' ', $s);
}

/**
 * La catégorie de grille d'un produit : celle que le siège lui a donnée, sinon la première grille dont un mot-clé
 * figure dans sa catégorie, son groupe ou son nom (l'ordre des grilles compte : « pain au chocolat » est une
 * viennoiserie, pas un pain). null : aucune grille, la tablette ne pose alors que les critères communs.
 */
function aqCategorieDe(array $grilles, array $produit): ?array
{
    $pid = (string) ($produit['id'] ?? '');
    $force = (string) ($grilles['produits'][$pid]['categorie'] ?? '');
    $cats = (array) ($grilles['categories'] ?? []);
    if ($force !== '') {
        foreach ($cats as $c) { if (($c['cle'] ?? '') === $force) { return $c; } }
    }
    $texte = ' ' . aqNormaliser(($produit['categorie'] ?? '') . ' ' . ($produit['groupe'] ?? '') . ' ' . ($produit['nom'] ?? '')) . ' ';
    foreach ($cats as $c) {
        foreach ((array) ($c['motsCles'] ?? []) as $m) {
            $m = trim(aqNormaliser((string) $m));
            if ($m !== '' && preg_match('/(?<![a-z])' . preg_quote($m, '/') . '/u', $texte)) { return $c; }
        }
    }
    return null;
}

/* ── Le produit : catalogue, planogramme, photo ─────────────────────────────────────────────────────────── */

/** Le catalogue du cockpit, réduit à ce que la tablette montre : [pwaId => produit]. */
function aqCatalogue(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $out = [];
    foreach ((array) ep_prod_catalogue() as $p) {
        $id = (int) ($p['pwaId'] ?? 0);
        if ($id <= 0) { continue; }
        $out[$id] = ['id' => $id, 'ref' => (string) ($p['ref'] ?? $id), 'nom' => (string) ($p['nom'] ?? ''),
            'categorie' => (string) ($p['categorie'] ?? ''), 'groupe' => (string) ($p['groupe'] ?? '')];
    }
    return $memo = $out;
}

/**
 * La place du produit au planogramme standard du jour : la section, son étage, les pièces en façade, et les autres
 * étages de la section avec ce qui y est posé (les voisins, pour reconnaître le meuble). null : pas posé.
 */
function aqPlanogramme(string $ref): ?array
{
    if (!function_exists('psLignes')) { return null; }
    try { $L = psLignes(date('Y-m-d')); } catch (PDOException $e) { return null; }
    $moi = null;
    foreach ($L as $cle => $rows) {
        foreach ($rows as $r) { if ((string) $r['ref'] === $ref) { $moi = [$cle, $r]; break 2; } }
    }
    if ($moi === null) { return null; }
    [$section, $niveau] = explode('|', $moi[0]);
    $zone = psZoneDe((int) $section);
    $noms = psNomsZones();
    $etages = [];
    foreach (PS_NIVEAUX as $n) {
        $rows = $L[$section . '|' . $n['k']] ?? null;
        if ($rows === null && !psDispo((int) $section, $n['k'])) { continue; }
        $etages[] = ['cle' => $n['k'], 'nom' => $n['nom'], 'moi' => $n['k'] === $niveau,
            'produits' => array_map(static fn ($r) => (string) ($r['nom'] ?? $r['ref']), (array) $rows)];
    }
    return ['zone' => $zone ? ($noms[$zone['id']] ?? $zone['nom']) : '', 'section' => (int) $section,
        'etage' => psNiveauNom($niveau), 'niveau' => $niveau,
        'facade' => $moi[1]['qte'] !== null ? (int) round((float) $moi[1]['qte']) : null, 'etages' => $etages];
}

/** La photo de référence du produit (celle du catalogue, gardée par le planogramme), ou null. */
function aqPhotoReference(string $ref, int $shop): ?string
{
    if (!function_exists('psPhotosResoudre')) { return null; }
    try { $r = psPhotosResoudre([$ref], $shop); } catch (Throwable $e) { return null; }
    $u = $r['photos'][$ref]['url'] ?? null;
    return is_string($u) && $u !== '' ? $u : null;
}

/* ── Les contrôles ───────────────────────────────────────────────────────────────────────────────────────── */

function aqTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS con_aq_controle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id VARCHAR(32) NOT NULL,
        produit_id INT NOT NULL,
        produit_nom VARCHAR(200) NOT NULL DEFAULT \'\',
        grille VARCHAR(40) NOT NULL DEFAULT \'\',
        fournee VARCHAR(5) NOT NULL DEFAULT \'\',
        pieces INT NULL,
        auteur VARCHAR(120) NOT NULL DEFAULT \'\',
        reponses MEDIUMTEXT NOT NULL,
        bilan MEDIUMTEXT NOT NULL,
        niveau VARCHAR(10) NOT NULL DEFAULT \'ok\',
        decision VARCHAR(10) NOT NULL DEFAULT \'vente\',
        suggestion VARCHAR(10) NOT NULL DEFAULT \'vente\',
        ecarts INT NOT NULL DEFAULT 0,
        prevenir TINYINT NOT NULL DEFAULT 0,
        reclamer TINYINT NOT NULL DEFAULT 0,
        essai TINYINT NOT NULL DEFAULT 0,
        cree_le DATETIME NOT NULL,
        KEY k_aq_shop (shop_id, produit_id, cree_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS con_aq_photo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        controle_id INT NOT NULL,
        etape VARCHAR(20) NOT NULL,
        fichier VARCHAR(80) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        KEY k_aq_controle (controle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Le dossier privé des photos (hors web, hors livraison). */
function aqDossier(): string
{
    static $d = null;
    if ($d !== null) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__, 2) . '/var/prive']) as $c) {
        $f = rtrim($c, '/') . '/autocontrole';
        if ((is_dir($f) || @mkdir($f, 0700, true)) && is_writable($f)) { return $d = $f; }
    }
    throw new RuntimeException('aucun dossier privé où ranger les photos de l\'autocontrôle');
}

/** Le magasin est-il celui de la session (le siège voit tout ; l'accès ouvert laisse passer) ? */
function aqShopOk(string $shop): bool
{
    if ($shop === '' || $shop === '0') { return false; }
    if (function_exists('accesMode') && accesMode() === 'ouvert') { return true; }
    $id = function_exists('accesIdentite') ? accesIdentite() : null;
    if (!is_array($id)) { return false; }
    return $id['role'] === 'siege' || in_array($shop, array_map('strval', (array) ($id['shops'] ?? [])), true);
}

/** Une photo en data-URL → [binaire, mime, extension], ou ['code' => 4xx, 'error' => …]. */
function aqImage(string $data): array
{
    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { return ['code' => 422, 'error' => 'photo illisible (data-URL attendue)']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 64) { return ['code' => 422, 'error' => 'photo illisible']; }
    if (strlen($bin) > 6 * 1024 * 1024) { return ['code' => 413, 'error' => 'photo trop lourde — 6 Mo au maximum']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { return ['code' => 415, 'error' => 'format non accepté — JPEG, PNG ou WebP']; }
    return [$bin, (string) $info['mime'], $ext];
}

/** Les contrôles d'un produit dans un magasin sur N jours, du plus récent au plus ancien (essais exclus). */
function aqHistorique(string $shop, int $produit, int $jours = 7): array
{
    aqTables();
    $depuis = date('Y-m-d 00:00:00', strtotime('-' . max(0, $jours - 1) . ' days'));
    $rows = Db::rows('SELECT id, fournee, auteur, niveau, decision, ecarts, bilan, cree_le FROM con_aq_controle
        WHERE shop_id = ? AND produit_id = ? AND essai = 0 AND cree_le >= ? ORDER BY cree_le DESC, id DESC', [$shop, $produit, $depuis]);
    return array_map(static function ($r) {
        $b = json_decode((string) $r['bilan'], true) ?: [];
        return ['id' => (int) $r['id'], 'fournee' => (string) $r['fournee'], 'auteur' => (string) $r['auteur'],
            'niveau' => (string) $r['niveau'], 'decision' => (string) $r['decision'], 'nbEcarts' => (int) $r['ecarts'],
            'ecarts' => (array) ($b['ecarts'] ?? []), 'cree_le' => (string) $r['cree_le']];
    }, $rows);
}

/* ── Les routes du magasin ──────────────────────────────────────────────────────────────────────────────── */

/** GET /autocontrole/magasin/produits?shop= — les produits du catalogue, leur grille et le dernier contrôle du jour. */
function ep_aq_produits(): array
{
    $shop = (string) ($_GET['shop'] ?? '');
    if (!aqShopOk($shop)) { http_response_code(403); return ['error' => 'magasin non autorisé']; }
    aqTables();
    $g = aqGrilles();
    $jour = [];
    foreach (Db::rows('SELECT produit_id, niveau, cree_le FROM con_aq_controle WHERE shop_id = ? AND essai = 0 AND cree_le >= ? ORDER BY cree_le',
        [$shop, date('Y-m-d 00:00:00')]) as $r) { $jour[(int) $r['produit_id']] = ['niveau' => (string) $r['niveau'], 'le' => substr((string) $r['cree_le'], 11, 5)]; }
    $out = [];
    foreach (aqCatalogue() as $p) {
        $c = aqCategorieDe($g, $p);
        $out[] = ['id' => $p['id'], 'nom' => $p['nom'], 'categorie' => $p['categorie'], 'groupe' => $p['groupe'],
            'grille' => $c['cle'] ?? null, 'grilleNom' => $c['libelle'] ?? null, 'aujourdhui' => $jour[$p['id']] ?? null];
    }
    usort($out, static fn ($a, $b) => [$a['grille'] === null, $a['nom']] <=> [$b['grille'] === null, $b['nom']]);
    return ['magasin' => ['id' => $shop, 'nom' => function_exists('accesNomMagasin') ? accesNomMagasin($shop) : $shop],
        'aValider' => !empty($g['aValider']), 'produits' => $out];
}

/** GET /autocontrole/magasin/produit?shop=&produit= — tout ce qu'il faut pour contrôler ce produit. */
function ep_aq_produit(): array
{
    $shop = (string) ($_GET['shop'] ?? '');
    $pid = (int) ($_GET['produit'] ?? 0);
    if (!aqShopOk($shop)) { http_response_code(403); return ['error' => 'magasin non autorisé']; }
    $p = aqCatalogue()[$pid] ?? null;
    if ($p === null) { http_response_code(404); return ['error' => 'produit inconnu du catalogue']; }
    $g = aqGrilles();
    $cat = aqCategorieDe($g, $p) ?? ['cle' => '', 'libelle' => 'Tous les produits', 'photos' => []];
    $grille = aqGrilleProduit($cat, (array) ($g['communs'] ?? []), (array) ($g['produits'][(string) $pid]['surcharges'] ?? []));
    $histo = aqHistorique($shop, $pid, 7);
    return ['magasin' => ['id' => $shop, 'nom' => function_exists('accesNomMagasin') ? accesNomMagasin($shop) : $shop],
        'produit' => $p + ['photo' => aqPhotoReference($p['ref'], (int) $shop)],
        'grille' => $grille, 'etapes' => (array) ($g['etapes'] ?? []), 'decisions' => (array) ($g['decisions'] ?? []),
        'aValider' => !empty($g['aValider']), 'planogramme' => aqPlanogramme($p['ref']),
        'historique' => $histo, 'recurrents' => aqRecurrents($histo, 3)];
}

/**
 * POST /autocontrole/magasin/controles {shop, produit, fournee, pieces, auteur, reponses, photos{etape: data-URL},
 * decision, prevenir, reclamer, essai} — enregistre le contrôle. Le bilan est recalculé ici à partir de la grille
 * publiée : le niveau et la décision suggérée ne viennent jamais de la tablette.
 */
function wr_aq_controle(): array
{
    $b = body();
    $shop = (string) ($b['shop'] ?? '');
    $pid = (int) ($b['produit'] ?? 0);
    if (!aqShopOk($shop)) { http_response_code(403); return ['error' => 'magasin non autorisé']; }
    $p = aqCatalogue()[$pid] ?? null;
    if ($p === null) { http_response_code(404); return ['error' => 'produit inconnu du catalogue']; }
    $g = aqGrilles();
    $cat = aqCategorieDe($g, $p) ?? ['cle' => '', 'photos' => []];
    $grille = aqGrilleProduit($cat, (array) ($g['communs'] ?? []), (array) ($g['produits'][(string) $pid]['surcharges'] ?? []));
    $reponses = [];
    foreach ($grille['criteres'] as $c) {
        $v = $b['reponses'][$c['cle']] ?? null;
        if (is_scalar($v) && (string) $v !== '') { $reponses[$c['cle']] = mb_substr((string) $v, 0, 40); }
    }
    $bilan = aqBilan($grille['criteres'], $reponses);
    if ($bilan['manquants']) { http_response_code(422); return ['error' => 'il reste des critères sans réponse', 'manquants' => $bilan['manquants']]; }
    $photos = is_array($b['photos'] ?? null) ? $b['photos'] : [];
    foreach (AQ_ETAPES as $e) {
        if (($grille['photos'][$e] ?? '') === 'obligatoire' && empty($photos[$e])) { http_response_code(422); return ['error' => "la photo de l'étape « $e » est obligatoire"]; }
    }
    $images = [];
    foreach ($photos as $e => $data) {
        if (!in_array($e, AQ_ETAPES, true) || !is_string($data) || $data === '') { continue; }
        $im = aqImage($data);
        if (isset($im['code'])) { http_response_code($im['code']); return ['error' => $im['error']]; }
        $images[$e] = $im;
    }
    $decision = in_array($b['decision'] ?? '', AQ_DECISIONS, true) ? (string) $b['decision'] : $bilan['decision'];
    $fournee = preg_match('/^\d{2}:\d{2}$/', (string) ($b['fournee'] ?? '')) ? (string) $b['fournee'] : '';
    $pieces = is_numeric($b['pieces'] ?? null) && (int) $b['pieces'] > 0 ? (int) $b['pieces'] : null;
    $auteur = trim(mb_substr((string) ($b['auteur'] ?? ''), 0, 120)) ?: 'Tablette';
    $essai = !empty($b['essai']) ? 1 : 0;
    $le = date('Y-m-d H:i:s');
    aqTables();
    Db::exec('INSERT INTO con_aq_controle (shop_id, produit_id, produit_nom, grille, fournee, pieces, auteur, reponses, bilan,
        niveau, decision, suggestion, ecarts, prevenir, reclamer, essai, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $shop, $pid, $p['nom'], $grille['cle'], $fournee, $pieces, $auteur,
        json_encode($reponses, JSON_UNESCAPED_UNICODE), json_encode($bilan, JSON_UNESCAPED_UNICODE),
        $bilan['niveau'], $decision, $bilan['decision'], count($bilan['ecarts']),
        !empty($b['prevenir']) ? 1 : 0, !empty($b['reclamer']) ? 1 : 0, $essai, $le]);
    $id = (int) (Db::row('SELECT MAX(id) id FROM con_aq_controle WHERE shop_id = ? AND produit_id = ? AND cree_le = ?', [$shop, $pid, $le])['id'] ?? 0);
    foreach ($images as $e => [$bin, $mime, $ext]) {
        $f = 'aq-' . $id . '-' . $e . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (@file_put_contents(aqDossier() . '/' . $f, $bin) === false) { continue; }
        Db::exec('INSERT INTO con_aq_photo (controle_id, etape, fichier, mime) VALUES (?,?,?,?)', [$id, $e, $f, $mime]);
    }
    if (!$essai && function_exists('journalAdd')) {
        $mag = function_exists('accesNomMagasin') ? accesNomMagasin($shop) : $shop;
        journalAdd($auteur, 'autocontrole', $mag, sprintf('Autocontrôle %s · %s · %s · %d écart(s)%s', $p['nom'],
            $fournee !== '' ? 'fournée de ' . $fournee : 'sans fournée', ['vente' => 'mis en vente', 'declasser' => 'déclassé', 'jeter' => 'jeté'][$decision],
            count($bilan['ecarts']), !empty($b['prevenir']) ? ' · responsable prévenu' : ''));
    }
    http_response_code(201);
    return ['ok' => true, 'id' => $id, 'bilan' => $bilan, 'decision' => $decision, 'essai' => (bool) $essai];
}

/** GET /autocontrole/magasin/photos/{id} — la photo, au magasin du contrôle et au siège ; sort le fichier puis s'arrête. */
function aqPhotoSortir(int $id): never
{
    aqTables();
    $p = Db::row('SELECT p.fichier, p.mime, c.shop_id FROM con_aq_photo p JOIN con_aq_controle c ON c.id = p.controle_id WHERE p.id = ?', [$id]);
    $f = $p !== null ? aqDossier() . '/' . basename((string) $p['fichier']) : '';
    if ($p === null || !aqShopOk((string) $p['shop_id']) || !is_file($f)) {
        http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'photo introuvable']); exit;
    }
    header('Content-Type: ' . (in_array($p['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $p['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; sandbox");
    readfile($f);
    exit;
}

/* ── Les routes du siège ─────────────────────────────────────────────────────────────────────────────────── */

/** GET /autocontrole/grilles — le brouillon, la version publiée, et ce qui empêche de publier. */
function ep_aq_grilles(): array
{
    $br = aqGrilles(false);
    $pub = setting(AQ_REGLAGE_PUBLIE, null);
    return ['brouillon' => $br, 'publiee' => is_array($pub) ? ['le' => $pub['publieLe'] ?? null, 'par' => $pub['publiePar'] ?? null] : null,
        'aValider' => !is_array($pub), 'erreurs' => aqErreurs($br), 'etapes' => AQ_ETAPES];
}

/** Les erreurs de chaque grille du document : [cle => [message, …]] (seulement celles qui en ont). */
function aqErreurs(array $doc): array
{
    $out = [];
    foreach ((array) ($doc['categories'] ?? []) as $c) {
        $e = aqValiderGrille($c);
        if ($e) { $out[(string) ($c['cle'] ?? '?')] = $e; }
    }
    $e = aqValiderGrille((array) ($doc['communs'] ?? []), true);
    if ($e) { $out['communs'] = $e; }
    return $out;
}

/** PUT /autocontrole/grilles {categories, communs, produits} — enregistre le brouillon (publiable ou non). */
function wr_aq_grilles(): array
{
    $b = body();
    if (!is_array($b['categories'] ?? null) || !is_array($b['communs'] ?? null)) { http_response_code(422); return ['error' => 'categories et communs sont requis']; }
    $doc = aqGraine();
    $doc['categories'] = array_values($b['categories']);
    $doc['communs'] = $b['communs'];
    $doc['produits'] = is_array($b['produits'] ?? null) ? $b['produits'] : ($doc['produits'] ?? []);
    unset($doc['aValider']);
    $doc['majLe'] = date('Y-m-d H:i:s');
    settingEcrire(AQ_REGLAGE, $doc);
    return ['ok' => true, 'erreurs' => aqErreurs($doc)];
}

/** POST /autocontrole/grilles/publication — publie le brouillon aux tablettes, s'il n'a aucune erreur. */
function wr_aq_publier(): array
{
    $doc = aqGrilles(false);
    $err = aqErreurs($doc);
    if ($err) { http_response_code(422); return ['error' => 'la grille a des erreurs', 'erreurs' => $err]; }
    unset($doc['aValider']);
    $doc['publieLe'] = date('Y-m-d H:i:s');
    $doc['publiePar'] = function_exists('psAuteur') ? psAuteur() : 'Siège';
    settingEcrire(AQ_REGLAGE_PUBLIE, $doc);
    if (function_exists('journalAdd')) { journalAdd($doc['publiePar'], 'autocontrole', null, 'Grilles d\'autocontrôle publiées aux magasins'); }
    return ['ok' => true, 'publieLe' => $doc['publieLe']];
}

/**
 * GET /autocontrole/controles?shop=&jours=30&essais=0 — les contrôles du réseau (ou d'un magasin), avec leurs photos,
 * et les défauts qui reviennent par magasin et produit.
 */
function ep_aq_controles(): array
{
    aqTables();
    $jours = max(1, min(92, (int) ($_GET['jours'] ?? 30)));
    $shop = (string) ($_GET['shop'] ?? '');
    $sql = 'SELECT * FROM con_aq_controle WHERE cree_le >= ?';
    $a = [date('Y-m-d 00:00:00', strtotime('-' . ($jours - 1) . ' days'))];
    if ($shop !== '') { $sql .= ' AND shop_id = ?'; $a[] = $shop; }
    if (empty($_GET['essais'])) { $sql .= ' AND essai = 0'; }
    $rows = Db::rows($sql . ' ORDER BY cree_le DESC, id DESC LIMIT 500', $a);
    $photos = [];
    if ($rows) {
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        foreach (Db::rows('SELECT id, controle_id, etape FROM con_aq_photo WHERE controle_id IN (' . implode(',', $ids) . ') ORDER BY id') as $ph) {
            $photos[(int) $ph['controle_id']][] = ['etape' => (string) $ph['etape'], 'url' => '/autocontrole/magasin/photos/' . (int) $ph['id']];
        }
    }
    $noms = function_exists('accesMagasins') ? accesMagasins() : [];
    $out = [];
    $groupes = [];
    foreach ($rows as $r) {
        $b = json_decode((string) $r['bilan'], true) ?: [];
        $k = ['id' => (int) $r['id'], 'shop' => (string) $r['shop_id'], 'magasin' => $noms[(string) $r['shop_id']] ?? ('Magasin ' . $r['shop_id']),
            'produitId' => (int) $r['produit_id'], 'produit' => (string) $r['produit_nom'], 'grille' => (string) $r['grille'],
            'fournee' => (string) $r['fournee'], 'pieces' => $r['pieces'] !== null ? (int) $r['pieces'] : null, 'auteur' => (string) $r['auteur'],
            'niveau' => (string) $r['niveau'], 'decision' => (string) $r['decision'], 'suggestion' => (string) $r['suggestion'],
            'ecarts' => (array) ($b['ecarts'] ?? []), 'actions' => (array) ($b['actions'] ?? []), 'n' => (array) ($b['n'] ?? []),
            'prevenir' => (bool) $r['prevenir'], 'reclamer' => (bool) $r['reclamer'], 'essai' => (bool) $r['essai'],
            'cree_le' => (string) $r['cree_le'], 'photos' => $photos[(int) $r['id']] ?? []];
        $out[] = $k;
        if (!$k['essai']) { $groupes[$k['shop'] . '|' . $k['produitId']][] = $k; }
    }
    $recurrents = [];
    foreach ($groupes as $liste) {
        foreach (aqRecurrents($liste, 3) as $d) {
            $recurrents[] = $d + ['shop' => $liste[0]['shop'], 'magasin' => $liste[0]['magasin'], 'produit' => $liste[0]['produit']];
        }
    }
    return ['jours' => $jours, 'controles' => $out, 'recurrents' => $recurrents];
}

/** Les routes /autocontrole : /autocontrole/magasin/… pour la tablette (son magasin), le reste au siège (src/acces.php). */
function aqRoute(string $method, string $path): ?array
{
    if ($path !== '/autocontrole' && !str_starts_with($path, '/autocontrole/')) { return null; }
    if ($method === 'GET' && $path === '/autocontrole/magasin/produits') { return ep_aq_produits(); }
    if ($method === 'GET' && $path === '/autocontrole/magasin/produit') { return ep_aq_produit(); }
    if ($method === 'POST' && $path === '/autocontrole/magasin/controles') { return wr_aq_controle(); }
    if ($method === 'GET' && preg_match('#^/autocontrole/magasin/photos/(\d+)$#', $path, $m)) { aqPhotoSortir((int) $m[1]); }
    if ($method === 'GET' && $path === '/autocontrole/grilles') { return ep_aq_grilles(); }
    if ($method === 'PUT' && $path === '/autocontrole/grilles') { return wr_aq_grilles(); }
    if ($method === 'POST' && $path === '/autocontrole/grilles/publication') { return wr_aq_publier(); }
    if ($method === 'GET' && $path === '/autocontrole/controles') { return ep_aq_controles(); }
    return null;
}
