<?php
declare(strict_types=1);

/**
 * Réclamations matière : la lecture des réclamations du panel (GET /fournisseurs/reclamations, ?shop= pour un
 * magasin), ce qu'il faut pour en écrire une (GET /fournisseurs/reclamation-refs), les codes-barres appris au
 * scan des cartons (POST /fournisseurs/matiere-code, table panel_matiere_code), les photos prises depuis le
 * dashboard (table panel_reclamation_photo) et le dépôt d'une réclamation (POST /fournisseurs/reclamation).
 *
 * Sorti tel quel de marketing/src/mesure.php le 21/11/2026 : ses deux tables sont du domaine Dashboard
 * (« une table, un domaine »). Le cockpit s'en sert aussi : consultant/src/reclamations.php (fiche, tableau
 * annuel, rcPhotosDe), consultant/src/franchises.php, consultant/src/invendus.php (rcRefsCache).
 */

/* ─────────────────── Réclamations matière — lecture ───────────────────
 *
 * `/consultant/shops/material-complaints` tient tout : la référence, la
 * commande liée, la quantité, le motif codé, ce que la boutique a écrit, le
 * statut, la réponse de production et les pièces jointes.
 *
 * Le cockpit ne fait que LIRE. Les routes qui répondent, relancent ou closent
 * sont les webhooks `material-suppliers/complaints/*`, destinés au système du
 * fournisseur : mesuré, elles répondent 401 avec le compte consultant. L'écran
 * le dit plutôt que d'afficher des boutons qui ne feraient rien.
 */
function ep_fournisseurs_reclamations(): array
{
    if (!PanelApi::configured()) {
        return ['indispo' => true, 'motif' => 'compte consultant non configuré'];
    }
    $r = PanelApi::get('/consultant/shops/material-complaints');
    if (!is_array($r)) {
        return ['indispo' => true, 'motif' => 'le panel n’a pas rendu les réclamations'];
    }
    $noms = [];
    try {
        foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $noms[(int) $s['id']] = (string) $s['name']; }
    } catch (PDOException $e) { /* noms indisponibles : l'identifiant fera foi */ }

    // COMBIEN : le prix d'achat de la matière, pour chiffrer ce qui est réclamé.
    // `/shops/{id}/materials` porte `base_unit_price_net` — la même matière a le
    // même prix d'achat d'un magasin à l'autre, une lecture suffit donc.
    $prix = [];
    $mats = PanelApi::get('/shops/' . (int) (array_key_first($noms) ?: 3) . '/materials');
    foreach ((array) (is_array($mats) ? (array_is_list($mats) ? $mats : ($mats['materials'] ?? [])) : []) as $m) {
        $id = (int) ($m['id'] ?? 0);
        $p = $m['base_unit_price_net'] ?? null;
        if ($id > 0 && is_numeric($p)) { $prix[$id] = (float) $p; }
    }

    $auj = new DateTimeImmutable('today');
    // La fenêtre : trois mois par défaut. Une réclamation d'il y a un an ne dit
    // plus rien de la livraison de la semaine — mais on ne la jette pas : on
    // compte ce qui est écarté, et l'écran permet d'élargir.
    $mois = (int) ($_GET['mois'] ?? 3);
    $mois = $mois > 0 ? min(60, $mois) : 0;
    $depuis = $mois > 0 ? $auj->modify('-' . $mois . ' months')->format('Y-m-d') : null;
    $ecartees = 0;
    $lignes = [];
    // ?shop= : le dashboard d'un magasin ne lit que les siennes.
    $seul = (int) ($_GET['shop'] ?? 0);
    // Les photos prises depuis le dashboard (gardées ici) : pour un magasin, ou pour tout le réseau (21/11/2026).
    $photos = $seul > 0 ? rcPhotosDe($seul) : rcPhotosDe(0);
    foreach ((array) ($r['shops'] ?? []) as $s) {
        $sid = (int) ($s['shop_id'] ?? 0);
        if ($seul > 0 && $sid !== $seul) { continue; }
        foreach ((array) ($s['complaints'] ?? []) as $c) {
            if (!is_array($c)) { continue; }
            $rep = trim((string) ($c['production_response'] ?? ''));
            $statut = strtoupper((string) ($c['status'] ?? ''));
            $le = substr((string) ($c['reported_at'] ?? $c['created_at'] ?? ''), 0, 10);
            $age = null;
            if ($le !== '') {
                try { $age = (int) (new DateTimeImmutable($le))->diff($auj)->days; } catch (Exception $e) { $age = null; }
            }
            // « Ouverte » n'est pas un statut du panel : c'est une réclamation
            // acceptée que personne n'a suivie d'effet. C'est celle-là qui coûte.
            $ouverte = $statut !== 'REJECTED' && $rep === '';
            if ($depuis !== null && $le !== '' && $le < $depuis) { $ecartees++; continue; }
            $qte = isset($c['qty']) ? (float) $c['qty'] : null;
            $pu = $prix[(int) ($c['id_material'] ?? 0)] ?? null;
            $lignes[] = [
                'id' => (int) ($c['id'] ?? 0),
                'prixUnitaire' => $pu,
                'montant' => ($pu !== null && $qte !== null) ? round($pu * $qte, 2) : null,
                'cle' => (string) ($c['complaint_key'] ?? ''),
                'shopId' => (string) $sid, 'magasin' => $noms[$sid] ?? ('Magasin ' . $sid),
                'le' => $le, 'age' => $age,
                'fournisseur' => (string) ($c['supplier_name'] ?? ''),
                'reference' => (string) ($c['material_name'] ?? ''),
                'sku' => (string) ($c['supplier_sku'] ?? ''),
                'commande' => (string) ($c['order_key'] ?? ''),
                'qte' => isset($c['qty']) ? (float) $c['qty'] : null,
                'unite' => (string) ($c['unit_name'] ?? ''),
                'motif' => (string) ($c['reason_name'] ?? $c['reason_code'] ?? ''),
                'motifCode' => (string) ($c['reason_code'] ?? ''),
                'texte' => trim((string) ($c['description'] ?? '')),
                'action' => (string) ($c['requested_action'] ?? ''),
                'statut' => $statut,
                'reponse' => $rep,
                'reponseLe' => $rep !== '' ? substr((string) ($c['updated_at'] ?? ''), 0, 10) : null,
                'qteAcceptee' => isset($c['accepted_qty']) && $c['accepted_qty'] !== null ? (float) $c['accepted_qty'] : null,
                'compensation' => (string) ($c['compensation_method'] ?? ''),
                'pj' => count((array) ($c['attachments'] ?? [])),
                // Les photos prises depuis le dashboard, gardées par le cockpit.
                'photos' => $photos[(int) ($c['id'] ?? 0)] ?? [],
                // Toutes les photos : pièces jointes du panel + photos du dashboard (la fiche les ouvre, 21/11/2026).
                'nbPhotos' => count((array) ($c['attachments'] ?? [])) + count($photos[(int) ($c['id'] ?? 0)] ?? []),
                'idMatiere' => (int) ($c['id_material'] ?? 0),
                'ouverte' => $ouverte,
            ];
        }
    }
    usort($lignes, fn ($a, $b) => strcmp((string) $b['le'], (string) $a['le']));

    // Par fournisseur : ce qui traîne, ce qui revient.
    $parF = [];
    foreach ($lignes as $l) {
        $f = $l['fournisseur'] !== '' ? $l['fournisseur'] : 'Fournisseur inconnu';
        if (!isset($parF[$f])) {
            $parF[$f] = ['nom' => $f, 'total' => 0, 'ouvertes' => 0, 'reglees' => 0, 'refusees' => 0,
                'plusAncienne' => null, 'refs' => [], 'motifs' => [], 'delais' => []];
        }
        $parF[$f]['total']++;
        if ($l['statut'] === 'REJECTED') { $parF[$f]['refusees']++; }
        elseif ($l['ouverte']) { $parF[$f]['ouvertes']++; }
        else { $parF[$f]['reglees']++; }
        if ($l['ouverte'] && $l['age'] !== null) {
            $parF[$f]['plusAncienne'] = max((int) $parF[$f]['plusAncienne'], $l['age']);
        }
        if ($l['reference'] !== '') { $parF[$f]['refs'][$l['reference']] = ($parF[$f]['refs'][$l['reference']] ?? 0) + 1; }
        if ($l['motif'] !== '') { $parF[$f]['motifs'][$l['motif']] = ($parF[$f]['motifs'][$l['motif']] ?? 0) + 1; }
        if ($l['reponse'] !== '' && $l['le'] !== '' && $l['reponseLe']) {
            try {
                $d1 = new DateTimeImmutable($l['le']); $d2 = new DateTimeImmutable($l['reponseLe']);
                if ($d2 >= $d1) { $parF[$f]['delais'][] = (int) $d1->diff($d2)->days; }
            } catch (Exception $e) { /* dates illisibles */ }
        }
    }
    foreach ($parF as $f => $v) {
        arsort($parF[$f]['refs']); arsort($parF[$f]['motifs']);
        $parF[$f]['refs'] = array_slice($parF[$f]['refs'], 0, 5, true);
        $parF[$f]['motifs'] = array_slice($parF[$f]['motifs'], 0, 6, true);
        $parF[$f]['delaiMoyen'] = $v['delais'] !== [] ? round(array_sum($v['delais']) / count($v['delais']), 1) : null;
        unset($parF[$f]['delais']);
    }
    usort($parF, fn ($a, $b) => [$b['ouvertes'], $b['total']] <=> [$a['ouvertes'], $a['total']]);

    // QUI — le magasin qui réclame. QUOI — la référence. COMBIEN — la quantité
    // et ce qu'elle vaut au prix d'achat. Trois lectures d'un même jeu.
    $cumul = static function (array $lignes, string $champ): array {
        $t = [];
        foreach ($lignes as $l) {
            $cle = ($l[$champ] ?? '') !== '' ? (string) $l[$champ] : '—';
            if (!isset($t[$cle])) {
                $t[$cle] = ['nom' => $cle, 'n' => 0, 'ouvertes' => 0, 'qte' => 0.0, 'montant' => 0.0, 'chiffre' => true];
            }
            $t[$cle]['n']++;
            if ($l['ouverte']) { $t[$cle]['ouvertes']++; }
            $t[$cle]['qte'] += (float) ($l['qte'] ?? 0);
            if ($l['montant'] === null) { $t[$cle]['chiffre'] = false; }
            else { $t[$cle]['montant'] += (float) $l['montant']; }
        }
        uasort($t, fn ($a, $b) => [$b['ouvertes'], $b['n']] <=> [$a['ouvertes'], $a['n']]);
        foreach ($t as $k => $v) { $t[$k]['montant'] = round($v['montant'], 2); $t[$k]['qte'] = round($v['qte'], 2); }
        return array_values($t);
    };
    $parMag = $cumul($lignes, 'magasin');
    $parRef = $cumul($lignes, 'reference');

    $mtOuvert = 0.0; $mtOk = true;
    foreach ($lignes as $l) {
        if (!$l['ouverte']) { continue; }
        if ($l['montant'] === null) { $mtOk = false; continue; }
        $mtOuvert += $l['montant'];
    }

    return ['lignes' => $lignes, 'fournisseurs' => array_values($parF),
        'mois' => $mois, 'depuis' => $depuis, 'ecartees' => $ecartees,
        'fenetre' => $mois > 0 ? ('les ' . $mois . ' derniers mois') : 'tout l’historique',
        'parMagasin' => $parMag, 'parReference' => array_slice($parRef, 0, 8),
        'montantOuvert' => $mtOk || $mtOuvert > 0 ? round($mtOuvert, 2) : null,
        'montantComplet' => $mtOk,
        'prixConnus' => count($prix),
        'total' => count($lignes),
        'ouvertes' => count(array_filter($lignes, fn ($l) => $l['ouverte'])),
        'reglees' => count(array_filter($lignes, fn ($l) => !$l['ouverte'] && $l['statut'] !== 'REJECTED')),
        'refusees' => count(array_filter($lignes, fn ($l) => $l['statut'] === 'REJECTED')),
        'source' => 'API panel — /consultant/shops/material-complaints',
        'lecture' => 'Le cockpit lit ; répondre, relancer et clore passent par les webhooks fournisseurs '
            . '(material-suppliers/complaints/*), qui refusent le compte consultant.'];
}

/**
 * Ce qu'il faut pour ÉCRIRE une réclamation : motifs, matières du fournisseur
 * (avec leur SKU), unités, et les livraisons du magasin.
 *
 * `id_order` a demandé une enquête : les routes `orders` refusent le compte
 * consultant (404 ORDER_NOT_FOUND), mais `/deliveries/{id}` répond — et la
 * livraison 33 porte `ORD-3-B6BD0C67901C0D4D`, exactement la commande d'une
 * réclamation dont `id_order` vaut 33. L'identifiant attendu est donc celui
 * d'une LIVRAISON. Faute de route qui les liste toutes, on assemble ce qu'on
 * peut voir : les livraisons en cours du fournisseur, et celles déjà citées par
 * les réclamations du magasin.
 */
function ep_reclamation_refs(): array
{
    return rcRefsMagasin((int) ($_GET['shop'] ?? 0));
}

/** Les références réclamables d'un magasin : motifs, matières par fournisseur avec leur SKU, livraisons. */
function rcRefsMagasin(int $shop): array
{
    if (!PanelApi::configured()) { return ['indispo' => true, 'motif' => 'compte consultant non configuré']; }
    $out = ['shopId' => (string) $shop, 'motifs' => [], 'matieres' => [], 'livraisons' => [], 'fournisseurs' => []];

    foreach ((array) PanelApi::get('/material-complaint-reasons') as $m) {
        if (is_array($m) && isset($m['code'])) { $out['motifs'][] = ['code' => (string) $m['code'], 'nom' => (string) ($m['name'] ?? $m['code'])]; }
    }

    // Les fournisseurs matière, et pour chacun ses références avec leur SKU.
    $unites = [];
    foreach ((array) PanelApi::get('/shops/' . ($shop ?: 3) . '/materials') as $m) {
        if (is_array($m) && isset($m['id'])) {
            $unites[(int) $m['id']] = ['idUnite' => (int) ($m['id_unit'] ?? 0), 'unite' => (string) ($m['unit_name'] ?? ''),
                'prix' => isset($m['base_unit_price_net']) && is_numeric($m['base_unit_price_net']) ? (float) $m['base_unit_price_net'] : null];
        }
    }
    foreach ((array) PanelApi::get('/material-suppliers') as $f) {
        if (!is_array($f) || !isset($f['id'])) { continue; }
        $fid = (int) $f['id'];
        $out['fournisseurs'][] = ['id' => (string) $fid, 'nom' => (string) ($f['name'] ?? ('Fournisseur ' . $fid))];
        foreach ((array) PanelApi::get('/material-suppliers/' . $fid . '/connected-materials') as $m) {
            if (!is_array($m) || !isset($m['id'])) { continue; }
            $mid = (int) $m['id'];
            $u = $unites[$mid] ?? ['idUnite' => 0, 'unite' => '', 'prix' => null];
            $out['matieres'][] = ['id' => (string) $mid, 'nom' => (string) ($m['name'] ?? ''),
                'sku' => (string) ($m['supplier_sku'] ?? ''), 'fournisseur' => (string) $fid,
                'idUnite' => $u['idUnite'], 'unite' => $u['unite'], 'prix' => $u['prix']];
        }
        // Les livraisons en cours de ce fournisseur, pour ce magasin.
        foreach ((array) PanelApi::get('/material-suppliers/' . $fid . '/upcoming-deliveries') as $d) {
            if (!is_array($d) || ($shop > 0 && (int) ($d['id_shop'] ?? 0) !== $shop)) { continue; }
            $out['livraisons'][(string) ($d['id'] ?? '')] = [
                'id' => (string) ($d['id'] ?? ''), 'cle' => (string) ($d['order_key'] ?? ''),
                'fournisseur' => (string) $fid, 'le' => substr((string) ($d['order_date'] ?? ''), 0, 10),
                'attendue' => substr((string) ($d['expected_date'] ?? ''), 0, 10),
                'statut' => (string) ($d['status'] ?? ''), 'source' => 'en cours'];
        }
    }
    // Celles que les réclamations du magasin citent déjà : ce sont des
    // livraisons reçues, donc les plus probables pour une réclamation.
    $r = PanelApi::get('/consultant/shops/material-complaints');
    foreach ((array) ($r['shops'] ?? []) as $s) {
        if ($shop > 0 && (int) ($s['shop_id'] ?? 0) !== $shop) { continue; }
        foreach ((array) ($s['complaints'] ?? []) as $c) {
            $id = (string) ($c['id_order'] ?? '');
            if ($id === '' || isset($out['livraisons'][$id])) { continue; }
            $out['livraisons'][$id] = ['id' => $id, 'cle' => (string) ($c['order_key'] ?? ''),
                'fournisseur' => (string) ($c['id_supplier'] ?? ''), 'le' => substr((string) ($c['reported_at'] ?? ''), 0, 10),
                'attendue' => '', 'statut' => 'livrée', 'source' => 'déjà réclamée'];
        }
    }
    $out['livraisons'] = array_values($out['livraisons']);
    usort($out['livraisons'], fn ($a, $b) => strcmp((string) $b['le'], (string) $a['le']));
    // Les codes-barres déjà reconnus : le panel n'en porte aucun (ni EAN ni
    // GTIN sur les matières), le cockpit apprend la correspondance au premier
    // scan et la sert à tous les magasins.
    $out['codes'] = rcCodes();
    $out['note'] = 'Les livraisons listées sont celles en cours chez le fournisseur et celles déjà citées par une '
        . 'réclamation : aucune route ne rend l’historique complet des commandes d’un magasin.';
    return $out;
}

/**
 * Les mêmes références, gardées dix minutes (`rc:refs:{shop}`) : pour agir depuis la modale des invendus sans
 * relire tout le panel à chaque clic. Le formulaire du téléphone, lui, relit toujours.
 */
function rcRefsCache(int $shop): array
{
    $cle = 'rc:refs:' . $shop;
    $c = setting($cle);
    if (is_array($c) && isset($c['v']) && is_array($c['v']) && (int) ($c['ts'] ?? 0) > time() - 600) { return $c['v']; }
    $v = rcRefsMagasin($shop);
    if (empty($v['indispo']) && function_exists('svGrave')) { svGrave($cle, ['ts' => time(), 'v' => $v]); }
    return $v;
}

/**
 * Code-barres → matière. Le panel ne porte aucun code-barres sur ses matières
 * (ni EAN, ni GTIN : seulement le SKU du fournisseur). Le premier scan d'un
 * carton inconnu demande le produit une fois ; le lien est gardé ici, et le
 * carton suivant — dans n'importe quel magasin — est reconnu tout seul.
 */
function ensureMatiereCode(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS panel_matiere_code (
        code VARCHAR(64) NOT NULL PRIMARY KEY,
        matiere_id INT NOT NULL,
        sku VARCHAR(64) NULL,
        nom VARCHAR(255) NULL,
        shop_id INT NULL,
        cree_le DATETIME NOT NULL,
        maj_le DATETIME NOT NULL,
        KEY idx_matiere (matiere_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** code → identifiant de matière (chaîne), pour l'écran. */
function rcCodes(): array
{
    $out = [];
    try {
        ensureMatiereCode();
        foreach (Db::rows('SELECT code, matiere_id FROM panel_matiere_code') as $r) { $out[(string) $r['code']] = (string) $r['matiere_id']; }
    } catch (PDOException $e) { /* table absente : rien d'appris */ }
    return $out;
}

/**
 * POST /fournisseurs/matiere-code — retenir qu'un code-barres désigne une
 * matière. { code, idMatiere, shopId?, sku?, nom? }. Le code est le GTIN
 * quand l'étiquette est GS1 (le lot et la date changent d'un carton à
 * l'autre, le GTIN non), sinon le texte lu.
 */
function wr_matiere_code(): array
{
    $b = body();
    $code = trim((string) ($b['code'] ?? ''));
    $mid = (int) ($b['idMatiere'] ?? 0);
    if (!preg_match('/^[\x21-\x7E]{3,64}$/', $code)) { http_response_code(422); return ['error' => 'code illisible (3 à 64 caractères imprimables)']; }
    if ($mid <= 0) { http_response_code(422); return ['error' => 'il manque la matière']; }
    $maintenant = date('Y-m-d H:i:s');
    ensureMatiereCode();
    Db::exec('INSERT INTO panel_matiere_code (code, matiere_id, sku, nom, shop_id, cree_le, maj_le) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE matiere_id = VALUES(matiere_id), sku = VALUES(sku), nom = VALUES(nom), shop_id = VALUES(shop_id), maj_le = VALUES(maj_le)',
        [$code, $mid, mb_substr((string) ($b['sku'] ?? ''), 0, 64) ?: null, mb_substr((string) ($b['nom'] ?? ''), 0, 255) ?: null,
         (int) ($b['shopId'] ?? 0) ?: null, $maintenant, $maintenant]);
    return ['ok' => true, 'code' => $code, 'idMatiere' => (string) $mid];
}

/** La table des photos de réclamation prises depuis le dashboard. */
function ensureReclamationPhotos(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS panel_reclamation_photo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reclamation_id INT NOT NULL,
        shop_id INT NOT NULL,
        chemin VARCHAR(255) NOT NULL,
        cree_le DATETIME NOT NULL,
        KEY idx_recl (reclamation_id), KEY idx_shop (shop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** reclamation_id → [url relative, …] pour un magasin ($shop = 0 : tout le réseau). */
function rcPhotosDe(int $shop): array
{
    $out = [];
    try {
        ensureReclamationPhotos();
        $rows = $shop > 0
            ? Db::rows('SELECT reclamation_id, chemin FROM panel_reclamation_photo WHERE shop_id = ? ORDER BY id', [$shop])
            : Db::rows('SELECT reclamation_id, chemin FROM panel_reclamation_photo WHERE reclamation_id > 0 ORDER BY id');
        foreach ($rows as $r) {
            $out[(int) $r['reclamation_id']][] = (string) $r['chemin'];
        }
    } catch (PDOException $e) { /* table absente : pas de photo locale */ }
    return $out;
}

/**
 * Une photo de réclamation (data-URL JPEG, PNG ou WebP, 6 Mo au plus) posée
 * sous public/uploads/reclamations, sous un nom ALÉATOIRE : le lien part dans
 * la description envoyée au fournisseur, il doit s'ouvrir sans compte et ne
 * pas se deviner.
 */
function rcPhotoEnregistrer(string $data, int $shop): array
{
    if (!preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $data, $m)) { return ['code' => 422, 'error' => 'photo illisible (data-URL attendue)']; }
    $bin = base64_decode($m[2], true);
    if ($bin === false) { return ['code' => 422, 'error' => 'photo illisible']; }
    return rcPhotoPoser($bin, $shop);
}

/** Les octets d'une photo (JPEG, PNG ou WebP, 6 Mo au plus) posés sous public/uploads/reclamations, nom aléatoire. */
function rcPhotoPoser(string $bin, int $shop): array
{
    if (strlen($bin) < 64) { return ['code' => 422, 'error' => 'photo illisible']; }
    if (strlen($bin) > 6 * 1024 * 1024) { return ['code' => 413, 'error' => 'photo trop lourde — 6 Mo au maximum']; }
    $info = @getimagesizefromstring($bin);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if ($ext === null) { return ['code' => 415, 'error' => 'format non accepté — JPEG, PNG ou WebP']; }
    $chemin = 'uploads/reclamations/' . $shop . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $abs = __DIR__ . '/../../public/' . $chemin;
    $dos = dirname($abs);
    if (!is_dir($dos) && !@mkdir($dos, 0775, true) && !is_dir($dos)) { return ['code' => 500, 'error' => 'dossier des photos impossible à créer']; }
    if (@file_put_contents($abs, $bin) === false) { return ['code' => 500, 'error' => 'écriture de la photo impossible']; }
    return ['chemin' => $chemin];
}

/**
 * POST /fournisseurs/reclamation — déposer une réclamation dans le panel.
 *
 * Les six champs exigés viennent de l'API elle-même, qui les nomme quand ils
 * manquent : id_material, supplier_sku, product_quantity, id_order, id_unit,
 * complaint_reason_code. Rien n'est deviné.
 */
function wr_reclamation_creer(): array
{
    $b = body();
    if (!PanelApi::configured()) { http_response_code(503); return ['error' => 'compte consultant non configuré']; }
    $manque = [];
    foreach (['idMatiere' => 'la référence', 'sku' => 'le SKU fournisseur', 'quantite' => 'la quantité',
              'idLivraison' => 'la livraison', 'idUnite' => 'l’unité', 'motif' => 'le motif'] as $k => $lib) {
        if (($b[$k] ?? '') === '' || $b[$k] === null) { $manque[] = $lib; }
    }
    if ($manque !== []) { http_response_code(422); return ['error' => 'il manque : ' . implode(', ', $manque)]; }

    // LES PHOTOS (dashboard, au téléphone). La route de création du panel ne
    // prend que du texte : les photos restent ici, et leurs liens partent dans
    // la description — le fournisseur les ouvre sans compte. Enregistrées
    // AVANT l'envoi pour que les liens y soient ; effacées si le panel refuse.
    $shopId = (int) ($b['shopId'] ?? 0);
    $poses = [];
    $dataPhotos = array_slice(array_values(array_filter((array) ($b['photos'] ?? []), 'is_string')), 0, 4);
    foreach ($dataPhotos as $data) {
        $f = rcPhotoEnregistrer($data, $shopId);
        if (isset($f['error'])) {
            foreach ($poses as $c) { @unlink(__DIR__ . '/../../public/' . $c); }
            http_response_code($f['code']); return ['error' => $f['error']];
        }
        $poses[] = $f['chemin'];
    }
    // Depuis le contrôle du cockpit : la photo de la tâche contrôlée devient celle de la réclamation. Le lien est
    // relu au panel (src/reclamations.php), jamais pris dans le corps de la requête.
    $pt = is_array($b['photoTache'] ?? null) ? $b['photoTache'] : null;
    if ($poses === [] && $pt !== null && function_exists('rcPhotoTache')) {
        $bin = rcPhotoTache($shopId, (int) ($pt['taskId'] ?? 0), (string) ($pt['date'] ?? ''));
        $f = $bin !== null ? rcPhotoPoser($bin, $shopId) : null;
        if ($f !== null && isset($f['chemin'])) { $poses[] = $f['chemin']; }
    }
    // LA PHOTO EST OBLIGATOIRE (21/11/2026, Sam) : sans photo, le fournisseur refuse — on ne l'envoie plus.
    if ($poses === []) {
        http_response_code(422);
        return ['error' => $pt !== null
            ? 'la photo du contrôle n’a pas pu être reprise du panel : sans photo, la réclamation ne part pas'
            : 'ajoutez au moins une photo : sans photo, le fournisseur ne peut pas traiter la réclamation'];
    }
    $texte = trim((string) ($b['texte'] ?? ''));
    $auteur = mb_substr(trim((string) ($b['auteur'] ?? '')), 0, 60);
    if ($auteur !== '') { $texte .= ($texte !== '' ? "\n\n" : '') . '— signalé par ' . $auteur; }
    if ($poses !== []) {
        $base = function_exists('rapBaseUrl') ? rapBaseUrl() : '';
        $texte .= ($texte !== '' ? "\n\n" : '') . 'Photos (' . count($poses) . ') :';
        foreach ($poses as $c) { $texte .= "\n" . ($base !== '' ? $base . '/' : '') . $c; }
    }

    $corps = [
        'id_shop' => (int) ($b['shopId'] ?? 0),
        'id_material' => (int) $b['idMatiere'],
        'supplier_sku' => (string) $b['sku'],
        'product_quantity' => (float) $b['quantite'],
        'id_order' => (int) $b['idLivraison'],
        'id_unit' => (int) $b['idUnite'],
        'complaint_reason_code' => (string) $b['motif'],
        'description' => $texte,
        'requested_action' => in_array($b['action'] ?? '', ['REPLACEMENT', 'REFUND', 'CREDIT_NOTE'], true) ? $b['action'] : 'REPLACEMENT',
        'complaint_type' => 'PRODUCT',
    ];
    if ((int) ($b['idFournisseur'] ?? 0) > 0) { $corps['id_supplier'] = (int) $b['idFournisseur']; }

    [$ok, $res] = PanelApi::post('/material-complaints', $corps);
    if (!$ok) {
        foreach ($poses as $c) { @unlink(__DIR__ . '/../../public/' . $c); }
        http_response_code(502);
        return ['error' => 'le panel a refusé la réclamation : ' . (PanelApi::$lastError ?? 'motif inconnu'),
            'detail' => is_array($res) ? ($res['errors'] ?? null) : null];
    }
    $id = null;
    foreach ([$res['inserted_id'] ?? null, $res['id'] ?? null] as $v) { if (is_numeric($v)) { $id = (int) $v; break; } }
    if ($poses !== []) {
        try {
            ensureReclamationPhotos();
            foreach ($poses as $c) {
                Db::exec('INSERT INTO panel_reclamation_photo (reclamation_id, shop_id, chemin, cree_le) VALUES (?,?,?,?)',
                    [(int) ($id ?? 0), $shopId, $c, date('Y-m-d H:i:s')]);
            }
        } catch (PDOException $e) { /* les liens sont déjà dans la description */ }
    }
    journalAdd('CEO', 'Réclamation', magasinNom((string) ($b['shopId'] ?? '')) ?? null,
        'Réclamation déposée' . ($id ? ' (#' . $id . ')' : '') . ' — ' . (string) ($b['nomMatiere'] ?? $b['sku'])
        . ' · ' . $corps['product_quantity'] . ' · ' . $corps['complaint_reason_code']);
    return ['ok' => true, 'id' => $id, 'photos' => $poses];
}
