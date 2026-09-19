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
        . 'le DATE NULL,'                          // dernière annotation
        . 'updated_at DATETIME NOT NULL,'
        . 'PRIMARY KEY (shop_id, place_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
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
        'action' => (string) ($r['action'] ?? ''), 'actionLe' => $r['action_le'] ?? '', 'le' => $r['le'] ?? '', 'nom' => $r['nom']];
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
 * PUT /prospection/{shop} — { lieux: { placeId: {coche?, visite?, retour?, note?, action?, actionLe?, nom?} } }
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
        $nom = array_key_exists('nom', $p) ? mb_substr(trim((string) $p['nom']), 0, 200) : (string) ($cur['nom'] ?? '');
        $le = (array_key_exists('note', $p) || array_key_exists('retour', $p) || array_key_exists('visite', $p) || array_key_exists('action', $p)) ? date('Y-m-d') : ($cur['le'] ?? null);
        if (!$coche && $visite === '' && $retour === '' && $note === '' && $action === '') {
            if ($cur) { Db::exec('DELETE FROM ceo_prospect WHERE shop_id = ? AND place_id = ?', [$shop, $id]); $faits++; }
            continue;
        }
        Db::exec('INSERT INTO ceo_prospect (shop_id, place_id, nom, coche, visite, retour, note, action, action_le, le, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
            . ' ON DUPLICATE KEY UPDATE nom = VALUES(nom), coche = VALUES(coche), visite = VALUES(visite), retour = VALUES(retour), note = VALUES(note), action = VALUES(action), action_le = VALUES(action_le), le = VALUES(le), updated_at = NOW()',
            [$shop, $id, $nom, $coche, $visite !== '' ? $visite : null, $retour, $note !== '' ? $note : null, $action, $actionLe !== '' ? $actionLe : null, $le]);
        $faits++;
        $r = Db::row('SELECT * FROM ceo_prospect WHERE shop_id = ? AND place_id = ?', [$shop, $id]);
        if ($r) { $out[$id] = prRow($r); }
    }
    return ['ok' => true, 'faits' => $faits, 'lieux' => (object) $out];
}
