<?php
declare(strict_types=1);

/**
 * Prospection — ce que le franchisé fait de chaque lieu de sa liste de
 * démarchage : dans ma liste, date de visite, retour, note (la note part au
 * CRM marketing). Une ligne par magasin et par lieu OpenStreetMap.
 *
 * Gardé AU SERVEUR, pas dans le navigateur : la liste se prépare au bureau
 * dans le cockpit et se coche sur le téléphone en tournée — deux appareils,
 * une seule réserve. Les lieux eux-mêmes viennent de /scouting/demarchage.
 */

const PR_RETOURS = ['', 'rappeler', 'rdv', 'interesse', 'refus', 'client'];
// L'action à suivre, décidée sur place : ce qu'on doit faire pour ce lieu, et quand.
const PR_ACTIONS = ['', 'mail', 'test', 'devis', 'rappel', 'passer', 'commande'];

/**
 * Le TYPE D'OFFRE : ce qu'on propose à un client selon ce qu'il est — un
 * hôpital ne prend pas la même chose qu'une maison communale. Chaque offre
 * porte son argument de démarchage, son contenu, et les hypothèses du calcul
 * de CA (dépense par personne et par commande, commandes par semaine, part
 * atteignable). La marque ajuste ces valeurs (PUT /prospection/offres) ; un
 * lieu reçoit l'offre de son genre, et le franchisé peut en choisir une autre.
 */
function prOffresDefaut(): array
{
    return [
        ['id' => 'bureau', 'nom' => 'Plateau bureau', 'pitch' => 'Le plateau de sandwiches et les viennoiseries livrés pour vos réunions, commande jusqu’à la veille 16 h.',
            'contenu' => 'plateau sandwiches 8–12 pers., viennoiseries, tartes', 'genres' => 'entreprise|bureau|association|banque|informatique|comptab|assur|ONG|coworking|agence|recherche', 'familles' => ['bureaux'],
            'depense' => 6, 'commandes' => 1, 'part' => 20],
        ['id' => 'equipe', 'nom' => 'Petit-déjeuner d’équipe', 'pitch' => 'Viennoiseries et pistolets garnis pour l’équipe du matin, livrés avant la prise de poste.',
            'contenu' => 'viennoiseries, pistolets garnis, cramique', 'genres' => 'industri|usine|atelier|artisan|entrepôt|garage|concession|carrosserie|car repair|logisti', 'familles' => ['industrie', 'artisans'],
            'depense' => 3.5, 'commandes' => 2, 'part' => 15],
        ['id' => 'ecole', 'nom' => 'Collations et fêtes d’école', 'pitch' => 'La collation de dix heures, les couques du vendredi et les tartes de la fête d’école.',
            'contenu' => 'couques, cramiques, tartes de fête, pains pour la cantine', 'genres' => 'école|ecole|crèche|creche|enseignement|universit|haute école|collège|lycée|athénée|internat', 'familles' => ['ecoles'],
            'depense' => 2.5, 'commandes' => 1, 'part' => 25],
        ['id' => 'soins', 'nom' => 'Pause soignants et résidents', 'pitch' => 'Les viennoiseries de la pause du personnel et la tarte du dimanche pour les résidents.',
            'contenu' => 'viennoiseries, tartes, pains spéciaux, sans sucre sur demande', 'genres' => 'hôpital|hopital|clinique|maison de repos|service social|médecin|dentiste|pharmacie|soins', 'familles' => ['sante'],
            'depense' => 3, 'commandes' => 3, 'part' => 15],
        ['id' => 'commune', 'nom' => 'Réceptions et réunions', 'pitch' => 'Sandwiches de réunion, réceptions et mignardises pour le conseil, le CPAS et les services.',
            'contenu' => 'sandwiches de réunion, mignardises, gâteaux de réception', 'genres' => 'maison communale|administration|police|poste|bibliothèque|centre communautaire|tribunal|pompiers|cpas|commune', 'familles' => ['administration'],
            'depense' => 7, 'commandes' => 0.5, 'part' => 20],
        ['id' => 'formation', 'nom' => 'Pause formation', 'pitch' => 'Viennoiseries à l’accueil et sandwiches à midi pour chaque session.',
            'contenu' => 'viennoiseries, plateau sandwiches, tartes', 'genres' => 'formation|conférence|congrès|séminaire', 'familles' => ['formation'],
            'depense' => 8, 'commandes' => 1, 'part' => 20],
        ['id' => 'funeraire', 'nom' => 'Café après cérémonie', 'pitch' => 'Plateaux de sandwiches et tartes pour le café qui suit la cérémonie, livrés à l’heure.',
            'contenu' => 'plateaux sandwiches, tartes, cakes', 'genres' => 'funér|funer|crémat|cremat|pompes funèbres', 'familles' => ['funeraire'],
            'depense' => 9, 'commandes' => 1, 'part' => 25],
        ['id' => 'sport', 'nom' => 'Après-match et cantine', 'pitch' => 'Pains, gaufres et snacks pour la buvette et l’après-match.',
            'contenu' => 'pains, gaufres, couques, sandwiches', 'genres' => 'sport|fitness|stade|piscine|swimming|pool|club', 'familles' => ['sport'],
            'depense' => 3, 'commandes' => 1, 'part' => 15],
        ['id' => 'hotel', 'nom' => 'Petit-déjeuner d’hôtel', 'pitch' => 'Pains et viennoiseries du matin livrés chaque jour avant 7 h.',
            'contenu' => 'pains, viennoiseries, brioches', 'genres' => 'hôtel|hotel|auberge|gîte|salle|événement|evenement', 'familles' => ['evenements'],
            'depense' => 2.5, 'commandes' => 7, 'part' => 15],
        ['id' => 'personnel', 'nom' => 'Pause du personnel', 'pitch' => 'Viennoiseries et sandwiches pour la pause de l’équipe, sur commande groupée.',
            'contenu' => 'viennoiseries, sandwiches', 'genres' => 'commerce|supermarché|bricolage|magasin|grande surface', 'familles' => ['commerces'],
            'depense' => 3, 'commandes' => 2, 'part' => 10],
    ];
}

/** Le catalogue en vigueur : les défauts, corrigés par ce que la marque a réglé (valeurs, textes). */
function prOffres(): array
{
    $regle = setting('prospectionOffres', []);
    $regle = is_array($regle) ? $regle : [];
    $parId = [];
    foreach ($regle as $o) { if (is_array($o) && isset($o['id'])) { $parId[(string) $o['id']] = $o; } }
    $out = [];
    foreach (prOffresDefaut() as $o) {
        $r = $parId[$o['id']] ?? [];
        foreach (['nom', 'pitch', 'contenu'] as $k) { if (isset($r[$k]) && is_string($r[$k]) && trim($r[$k]) !== '') { $o[$k] = mb_substr(trim($r[$k]), 0, 300); } }
        foreach (['depense', 'commandes', 'part'] as $k) { if (isset($r[$k]) && is_numeric($r[$k])) { $o[$k] = (float) $r[$k]; } }
        $out[] = $o;
    }
    return $out;
}

/** L'offre d'un lieu par son genre (mots du libellé), sinon par sa famille, sinon la pause du personnel. */
function prOffreDe(string $genre, string $famille, array $offres): string
{
    $g = mb_strtolower($genre);
    foreach ($offres as $o) { if ($o['genres'] !== '' && preg_match('/' . $o['genres'] . '/iu', $g)) { return $o['id']; } }
    foreach ($offres as $o) { if (in_array($famille, $o['familles'], true)) { return $o['id']; } }
    return 'personnel';
}

/** GET /prospection/offres — le catalogue des types d'offre. */
function ep_prospection_offres(): array
{
    return ['offres' => prOffres()];
}

/** PUT /prospection/offres — { offres: [{ id, nom?, pitch?, contenu?, depense?, commandes?, part? }] } : la marque règle le catalogue. */
function wr_prospection_offres_put(): array
{
    $b = body();
    $liste = $b['offres'] ?? [];
    if (!is_array($liste)) { http_response_code(400); return ['error' => 'offres attendu']; }
    $ids = array_map(fn ($o) => $o['id'], prOffresDefaut());
    $garde = [];
    foreach ($liste as $o) {
        if (!is_array($o) || !in_array($o['id'] ?? '', $ids, true)) { continue; }
        $g = ['id' => $o['id']];
        foreach (['nom', 'pitch', 'contenu'] as $k) { if (isset($o[$k]) && is_string($o[$k])) { $g[$k] = mb_substr(trim($o[$k]), 0, 300); } }
        if (isset($o['depense']) && is_numeric($o['depense'])) { $g['depense'] = max(0.1, min(500, (float) $o['depense'])); }
        if (isset($o['commandes']) && is_numeric($o['commandes'])) { $g['commandes'] = max(0.05, min(14, (float) $o['commandes'])); }
        if (isset($o['part']) && is_numeric($o['part'])) { $g['part'] = max(1, min(100, (float) $o['part'])); }
        $garde[] = $g;
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['prospectionOffres', json_encode($garde, JSON_UNESCAPED_UNICODE)]);
    journalAdd('Marque', 'Prospection', null, 'Types d’offre réglés (' . count($garde) . ')');
    return ['ok' => true, 'offres' => prOffres()];
}

function ensureProspection(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_prospect ('
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'place_id VARCHAR(24) NOT NULL,'        // id OSM : n123, w123, r123
        . 'nom VARCHAR(200) NOT NULL DEFAULT "",'  // rappel, pour lire la table sans la carte
        . 'coche TINYINT NOT NULL DEFAULT 0,'
        . 'visite DATE NULL,'
        . 'retour VARCHAR(12) NOT NULL DEFAULT "",'
        . 'note TEXT NULL,'
        . 'action VARCHAR(12) NOT NULL DEFAULT "",'  // à suivre : mail, test, devis, rappel, passer, commande
        . 'action_le DATE NULL,'
        . 'offre VARCHAR(16) NOT NULL DEFAULT "",'   // le type d'offre choisi ; vide = celui du genre
        . 'le DATE NULL,'                          // dernière annotation
        . 'updated_at DATETIME NOT NULL,'
        . 'PRIMARY KEY (shop_id, place_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    try { Db::exec('ALTER TABLE ceo_prospect ADD COLUMN offre VARCHAR(16) NOT NULL DEFAULT ""'); } catch (Throwable $e) { /* déjà là */ }
}

function prShop(string $shop): string
{
    $s = trim($shop);
    if (!preg_match('/^\d{1,10}$/', $s)) { http_response_code(422); throw new RuntimeException('magasin invalide'); }
    return $s;
}

function prRow(array $r): array
{
    return ['coche' => (int) $r['coche'] === 1, 'visite' => $r['visite'] ?? '', 'retour' => $r['retour'], 'note' => (string) ($r['note'] ?? ''),
        'action' => (string) ($r['action'] ?? ''), 'actionLe' => $r['action_le'] ?? '', 'offre' => (string) ($r['offre'] ?? ''), 'le' => $r['le'] ?? '', 'nom' => $r['nom']];
}

/** GET /prospection/{shop} — l'état de chaque lieu de la liste du magasin. */
function ep_prospection(string $shop): array
{
    ensureProspection();
    $shop = prShop($shop);
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_prospect WHERE shop_id = ? ORDER BY updated_at DESC', [$shop]) as $r) { $out[$r['place_id']] = prRow($r); }
    return ['shop' => $shop, 'lieux' => (object) $out, 'n' => count($out)];
}

/**
 * PUT /prospection/{shop} — { lieux: { placeId: {coche?, visite?, retour?, note?, action?, actionLe?, offre?, nom?} } }
 * Chaque entrée est fusionnée avec ce qui existe ; une ligne vidée (ni dans
 * la liste, ni visite, ni retour, ni note) disparaît.
 */
function wr_prospection_put(string $shop): array
{
    ensureProspection();
    $shop = prShop($shop);
    $b = body();
    $lieux = $b['lieux'] ?? [];
    if (!is_array($lieux) || $lieux === []) { http_response_code(400); return ['error' => 'lieux attendu']; }
    $faits = 0; $out = [];
    foreach (array_slice($lieux, 0, 400, true) as $id => $p) {
        $id = (string) $id;
        if (!preg_match('/^[nwr]\d{1,15}$/', $id) || !is_array($p)) { continue; }
        $cur = Db::row('SELECT * FROM ceo_prospect WHERE shop_id = ? AND place_id = ?', [$shop, $id]);
        $coche = array_key_exists('coche', $p) ? (!empty($p['coche']) ? 1 : 0) : (int) ($cur['coche'] ?? 0);
        $visite = array_key_exists('visite', $p) ? (string) $p['visite'] : (string) ($cur['visite'] ?? '');
        if ($visite !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visite)) { $visite = ''; }
        $retour = array_key_exists('retour', $p) ? (string) $p['retour'] : (string) ($cur['retour'] ?? '');
        if (!in_array($retour, PR_RETOURS, true)) { $retour = ''; }
        $note = array_key_exists('note', $p) ? mb_substr(trim((string) $p['note']), 0, 2000) : (string) ($cur['note'] ?? '');
        $action = array_key_exists('action', $p) ? (string) $p['action'] : (string) ($cur['action'] ?? '');
        if (!in_array($action, PR_ACTIONS, true)) { $action = ''; }
        $actionLe = array_key_exists('actionLe', $p) ? (string) $p['actionLe'] : (string) ($cur['action_le'] ?? '');
        if ($actionLe !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $actionLe)) { $actionLe = ''; }
        if ($action === '') { $actionLe = ''; }
        $offre = array_key_exists('offre', $p) ? (string) $p['offre'] : (string) ($cur['offre'] ?? '');
        if ($offre !== '' && !in_array($offre, array_map(fn ($o) => $o['id'], prOffresDefaut()), true)) { $offre = ''; }
        $nom = array_key_exists('nom', $p) ? mb_substr(trim((string) $p['nom']), 0, 200) : (string) ($cur['nom'] ?? '');
        $le = (array_key_exists('note', $p) || array_key_exists('retour', $p) || array_key_exists('visite', $p) || array_key_exists('action', $p)) ? date('Y-m-d') : ($cur['le'] ?? null);
        if (!$coche && $visite === '' && $retour === '' && $note === '' && $action === '' && $offre === '') {
            if ($cur) { Db::exec('DELETE FROM ceo_prospect WHERE shop_id = ? AND place_id = ?', [$shop, $id]); $faits++; }
            continue;
        }
        Db::exec('INSERT INTO ceo_prospect (shop_id, place_id, nom, coche, visite, retour, note, action, action_le, offre, le, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())'
            . ' ON DUPLICATE KEY UPDATE nom = VALUES(nom), coche = VALUES(coche), visite = VALUES(visite), retour = VALUES(retour), note = VALUES(note), action = VALUES(action), action_le = VALUES(action_le), offre = VALUES(offre), le = VALUES(le), updated_at = NOW()',
            [$shop, $id, $nom, $coche, $visite !== '' ? $visite : null, $retour, $note !== '' ? $note : null, $action, $actionLe !== '' ? $actionLe : null, $offre, $le]);
        $faits++;
        $r = Db::row('SELECT * FROM ceo_prospect WHERE shop_id = ? AND place_id = ?', [$shop, $id]);
        if ($r) { $out[$id] = prRow($r); }
    }
    return ['ok' => true, 'faits' => $faits, 'lieux' => (object) $out];
}
