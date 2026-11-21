<?php
declare(strict_types=1);

/**
 * Réclamations fournisseur : les photos de chaque réclamation, la part du produit dans ce qui a été livré, et le
 * tableau annuel réclamé / livré (21/11/2026 — maquettes A et B de consultant/docs/maquettes/reclamations-photos-volumes,
 * retenues par Sam : « code A et B, photo obligatoire »).
 *
 * D'où viennent les chiffres :
 *  - les réclamations : le panel, GET /consultant/shops/material-complaints (la même lecture que
 *    ep_fournisseurs_reclamations, marketing/src/mesure.php) ;
 *  - les photos : les pièces jointes de la réclamation au panel, ouvertes par un lien signé à durée limitée
 *    (/attachments/{id}/presigned-url, demandé à l'ouverture de la fiche, jamais pour toute la liste), et celles prises
 *    depuis le dashboard, gardées ici (panel_reclamation_photo) ;
 *  - le livré : le grand livre matière du panel (material_inventory_log), les ENTRÉES liées à une commande fournisseur
 *    (id_order renseigné, quantité positive), en unité de base. Lecture SQL directe d'une table du panel : écart connu
 *    (REORG_PLAN.md §8), faute de route du panel qui rende les livraisons d'un magasin. Valorisé au prix d'achat de la
 *    matière (base_unit_price_net), le même prix que le montant des réclamations.
 */

/** Les identifiants des pièces jointes d'une réclamation : entiers nus, ou objets {id | attachment_id}. */
function rcPjIds(mixed $att): array
{
    $ids = [];
    foreach ((array) $att as $a) {
        $id = is_array($a) ? ($a['attachment_id'] ?? $a['id'] ?? $a['attachmentId'] ?? null) : $a;
        if (is_numeric($id) && (int) $id > 0) { $ids[] = (int) $id; }
    }
    return array_values(array_unique($ids));
}

/** Toutes les réclamations du panel, à plat : [['shop' => id, 'c' => réclamation], …] ; null si le panel ne répond pas. */
function rcToutesPanel(): ?array
{
    if (!PanelApi::configured()) { return null; }
    $r = PanelApi::get('/consultant/shops/material-complaints');
    if (!is_array($r)) { return null; }
    $out = [];
    foreach ((array) ($r['shops'] ?? []) as $s) {
        foreach ((array) ($s['complaints'] ?? []) as $c) {
            if (is_array($c)) { $out[] = ['shop' => (int) ($s['shop_id'] ?? 0), 'c' => $c]; }
        }
    }
    return $out;
}

/** Le jour d'une réclamation (AAAA-MM-JJ), ou ''. */
function rcJour(array $c): string
{
    $j = substr((string) ($c['reported_at'] ?? $c['created_at'] ?? ''), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $j) ? $j : '';
}

/** Le prix d'achat de chaque matière (base_unit_price_net) : la même matière a le même prix d'un magasin à l'autre. */
function rcPrixMatieres(): array
{
    $prix = [];
    $shop = 3;
    try { $r = Db::row('SELECT MIN(id) id FROM shops WHERE active = 1'); if ($r && (int) $r['id'] > 0) { $shop = (int) $r['id']; } }
    catch (PDOException $e) { /* le magasin 3 fera l'affaire */ }
    $mats = PanelApi::get('/shops/' . $shop . '/materials');
    foreach ((array) (is_array($mats) ? (array_is_list($mats) ? $mats : ($mats['materials'] ?? [])) : []) as $m) {
        $id = (int) ($m['id'] ?? 0);
        $p = $m['base_unit_price_net'] ?? null;
        if ($id > 0 && is_numeric($p)) { $prix[$id] = (float) $p; }
    }
    return $prix;
}

/** Les magasins actifs : id → nom. */
function rcNomsMagasins(): array
{
    $noms = [];
    try { foreach (Db::rows('SELECT id, name FROM shops WHERE active = 1') as $s) { $noms[(int) $s['id']] = (string) $s['name']; } }
    catch (PDOException $e) { /* l'identifiant fera foi */ }
    return $noms;
}

/**
 * Le livré, en unité de base : les entrées du grand livre matière liées à une commande fournisseur, entre $du et $au
 * (inclus), regroupées par magasin, matière et $grain (« jour » ou « mois »). Rend null si la table n'est pas lisible.
 *
 * @param int[]|null $matieres  restreindre à ces matières (null : toutes)
 * @return list<array{shop:int, matiere:int, cle:string, q:float}>|null
 */
function rcLivre(string $du, string $au, ?array $matieres = null, ?int $shop = null, string $grain = 'jour'): ?array
{
    if ($matieres !== null && $matieres === []) { return []; }
    $cle = $grain === 'mois' ? "DATE_FORMAT(created_at, '%Y-%m')" : 'DATE(created_at)';
    $sql = "SELECT id_shop, id_material, $cle cle, SUM(change_quantity_in_base_unit) q FROM material_inventory_log
            WHERE id_order IS NOT NULL AND change_quantity_in_base_unit > 0 AND created_at >= ? AND created_at < ?";
    $args = [$du . ' 00:00:00', date('Y-m-d', strtotime($au . ' +1 day')) . ' 00:00:00'];
    if ($matieres !== null) { $sql .= ' AND id_material IN (' . implode(',', array_map('intval', $matieres)) . ')'; }
    if ($shop !== null) { $sql .= ' AND id_shop = ?'; $args[] = $shop; }
    $sql .= ' GROUP BY id_shop, id_material, cle';
    try { $rows = Db::rows($sql, $args); } catch (PDOException $e) { return null; }
    return array_map(fn ($r) => ['shop' => (int) $r['id_shop'], 'matiere' => (int) $r['id_material'],
        'cle' => (string) $r['cle'], 'q' => (float) $r['q']], $rows);
}

/** Les bornes d'une période autour d'un jour : la semaine (lundi → dimanche), le mois, l'année. */
function rcPeriodes(string $jour): array
{
    $d = new DateTimeImmutable($jour);
    $lundi = $d->modify('monday this week');
    return [
        'semaine' => ['du' => $lundi->format('Y-m-d'), 'au' => $lundi->modify('+6 days')->format('Y-m-d'), 'nom' => 'Semaine ' . (int) $d->format('W')],
        'mois' => ['du' => $d->format('Y-m-01'), 'au' => $d->format('Y-m-t'), 'nom' => $d->format('m/Y')],
        'annee' => ['du' => $d->format('Y-01-01'), 'au' => $d->format('Y-12-31'), 'nom' => $d->format('Y')],
    ];
}

/**
 * La part du produit réclamé dans ce qui a été livré : pour le magasin sur la semaine, le mois et l'année de la
 * réclamation, et pour le réseau sur l'année. « Réclamé » additionne TOUTES les réclamations de ce magasin sur cette
 * matière dans la période (pas seulement celle de la fiche).
 */
function rcPartLivre(array $toutes, int $shop, int $matiere, string $jour): array
{
    $P = rcPeriodes($jour);
    $livres = rcLivre($P['annee']['du'], $P['annee']['au'], [$matiere]);
    $out = ['lu' => $livres !== null, 'periodes' => []];
    $reclame = static function (string $du, string $au, ?int $seul) use ($toutes, $matiere): float {
        $q = 0.0;
        foreach ($toutes as $x) {
            $c = $x['c']; $j = rcJour($c);
            if ((int) ($c['id_material'] ?? 0) !== $matiere || $j === '' || $j < $du || $j > $au) { continue; }
            if ($seul !== null && $x['shop'] !== $seul) { continue; }
            $q += (float) ($c['qty'] ?? 0);
        }
        return $q;
    };
    $livre = static function (string $du, string $au, ?int $seul) use ($livres): ?float {
        if ($livres === null) { return null; }
        $q = 0.0;
        foreach ($livres as $l) {
            if ($l['cle'] < $du || $l['cle'] > $au || ($seul !== null && $l['shop'] !== $seul)) { continue; }
            $q += $l['q'];
        }
        return $q;
    };
    $ligne = static function (string $nom, float $r, ?float $l): array {
        return ['nom' => $nom, 'reclame' => round($r, 2), 'livre' => $l === null ? null : round($l, 2),
            'part' => $l !== null && $l > 0 ? round(100 * $r / $l, 2) : null];
    };
    foreach ($P as $k => $p) {
        $out['periodes'][$k] = $ligne($p['nom'], $reclame($p['du'], $p['au'], $shop), $livre($p['du'], $p['au'], $shop));
    }
    $out['reseau'] = $ligne($P['annee']['nom'], $reclame($P['annee']['du'], $P['annee']['au'], null), $livre($P['annee']['du'], $P['annee']['au'], null));
    return $out;
}

/**
 * GET /fournisseurs/reclamation-fiche?shop=&id= — ce que la fiche d'une réclamation montre en plus de la liste :
 * ses photos (liens signés des pièces jointes du panel + photos du dashboard) et la part du produit dans le livré.
 */
function ep_reclamation_fiche(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $id = (int) ($_GET['id'] ?? 0);
    if ($shop <= 0 || $id <= 0) { http_response_code(400); return ['error' => 'shop et id sont requis']; }
    $toutes = rcToutesPanel();
    if ($toutes === null) { http_response_code(503); return ['error' => 'le panel n’a pas rendu les réclamations']; }
    $c = null;
    foreach ($toutes as $x) { if ($x['shop'] === $shop && (int) ($x['c']['id'] ?? 0) === $id) { $c = $x['c']; break; } }
    if ($c === null) { http_response_code(404); return ['error' => 'réclamation introuvable pour ce magasin']; }

    $photos = [];
    $req = [];
    foreach (rcPjIds($c['attachments'] ?? []) as $a) { $req[$a] = '/attachments/' . $a . '/presigned-url'; }
    foreach ($req ? PanelApi::getParallele($req) : [] as $a => $r) {
        $url = is_string($r) ? $r : null;
        foreach (['url', 'presigned_url', 'presignedUrl', 'link'] as $k) {
            if ($url === null && is_array($r) && isset($r[$k]) && is_string($r[$k])) { $url = $r[$k]; }
        }
        $photos[] = $url !== null && preg_match('#^https://#', $url)
            ? ['url' => $url, 'source' => 'panel'] : ['url' => null, 'source' => 'panel', 'erreur' => 'lien non rendu par le panel'];
    }
    $base = function_exists('rapBaseUrl') ? rtrim(rapBaseUrl(), '/') : '';
    foreach ((rcPhotosDe($shop)[$id] ?? []) as $chemin) {
        $photos[] = ['url' => ($base !== '' ? $base . '/' : '') . $chemin, 'source' => 'dashboard'];
    }

    $jour = rcJour($c);
    $mat = (int) ($c['id_material'] ?? 0);
    return ['id' => $id, 'shopId' => (string) $shop, 'photos' => $photos,
        'part' => $mat > 0 && $jour !== '' ? rcPartLivre($toutes, $shop, $mat, $jour) : null,
        'validite' => 'Les liens des photos du panel sont signés et expirent : la fiche les redemande à chaque ouverture.'];
}

/**
 * GET /fournisseurs/reclamations/annuel?annee=&fournisseur= — le tableau annuel : magasins × mois, le montant réclamé
 * (au prix d'achat), sa part dans ce qui a été livré au magasin le même mois, l'accepté, et la ligne Réseau.
 * Sans `fournisseur`, celui qui a le plus de réclamations dans l'année ; `fournisseur=*` : tous.
 */
function ep_reclamations_annuel(): array
{
    $annee = (int) ($_GET['annee'] ?? date('Y'));
    if ($annee < 2020 || $annee > (int) date('Y') + 1) { $annee = (int) date('Y'); }
    $toutes = rcToutesPanel();
    if ($toutes === null) { return ['indispo' => true, 'motif' => PanelApi::configured() ? 'le panel n’a pas rendu les réclamations' : 'compte consultant non configuré']; }

    $an = array_values(array_filter($toutes, fn ($x) => str_starts_with(rcJour($x['c']), (string) $annee)));
    $parF = [];
    foreach ($an as $x) {
        $f = trim((string) ($x['c']['supplier_name'] ?? '')) ?: 'Fournisseur inconnu';
        $parF[$f] ??= ['nom' => $f, 'n' => 0, 'ids' => []];
        $parF[$f]['n']++;
        if ((int) ($x['c']['id_supplier'] ?? 0) > 0) { $parF[$f]['ids'][(int) $x['c']['id_supplier']] = true; }
    }
    uasort($parF, fn ($a, $b) => $b['n'] <=> $a['n']);
    $choix = (string) ($_GET['fournisseur'] ?? '');
    if ($choix !== '*' && !isset($parF[$choix])) { $choix = (string) (array_key_first($parF) ?? '*'); }
    $retenues = $choix === '*' ? $an : array_values(array_filter($an, fn ($x) => (trim((string) ($x['c']['supplier_name'] ?? '')) ?: 'Fournisseur inconnu') === $choix));

    // Les matières du fournisseur : le livré ne compte que celles-là (le taux de SLFood se juge sur ce que SLFood livre).
    $fids = [];
    foreach ($choix === '*' ? $parF : [$parF[$choix] ?? ['ids' => []]] as $f) { foreach (array_keys($f['ids']) as $fid) { $fids[$fid] = true; } }
    $matieres = [];
    foreach (array_keys($fids) as $fid) {
        foreach ((array) PanelApi::get('/material-suppliers/' . $fid . '/connected-materials') as $m) {
            if (is_array($m) && (int) ($m['id'] ?? 0) > 0) { $matieres[(int) $m['id']] = true; }
        }
    }
    foreach ($retenues as $x) { if ((int) ($x['c']['id_material'] ?? 0) > 0) { $matieres[(int) $x['c']['id_material']] = true; } }

    $prix = rcPrixMatieres();
    $noms = rcNomsMagasins();
    $moisMax = $annee < (int) date('Y') ? 12 : ($annee > (int) date('Y') ? 0 : (int) date('n'));
    $vide = static fn (): array => ['reclame' => 0.0, 'n' => 0, 'nonChiffrees' => 0, 'accepte' => 0.0, 'livre' => 0.0, 'livreSansPrix' => 0];
    $grille = [];   // shop → mois (1..12) → cellule
    $cell = static function (int $shop, int $m) use (&$grille, $vide): void { $grille[$shop][$m] ??= $vide(); };

    foreach ($retenues as $x) {
        $c = $x['c']; $m = (int) substr(rcJour($c), 5, 2); $s = $x['shop'];
        $cell($s, $m);
        $g = &$grille[$s][$m];
        $g['n']++;
        $pu = $prix[(int) ($c['id_material'] ?? 0)] ?? null;
        $q = isset($c['qty']) ? (float) $c['qty'] : null;
        if ($pu !== null && $q !== null) { $g['reclame'] += $pu * $q; } else { $g['nonChiffrees']++; }
        $qa = isset($c['accepted_qty']) && $c['accepted_qty'] !== null ? (float) $c['accepted_qty'] : null;
        if ($pu !== null && $qa !== null && strtoupper((string) ($c['status'] ?? '')) !== 'REJECTED') { $g['accepte'] += $pu * $qa; }
        unset($g);
    }
    $livres = rcLivre($annee . '-01-01', $annee . '-12-31', array_keys($matieres), null, 'mois');
    foreach ($livres ?? [] as $l) {
        $m = (int) substr($l['cle'], 5, 2);
        $cell($l['shop'], $m);
        $pu = $prix[$l['matiere']] ?? null;
        if ($pu === null) { $grille[$l['shop']][$m]['livreSansPrix']++; continue; }
        $grille[$l['shop']][$m]['livre'] += $pu * $l['q'];
    }

    $somme = static function (array $cells) use ($vide): array {
        $t = $vide();
        foreach ($cells as $c) { if ($c) { foreach ($t as $k => $v) { $t[$k] += $c[$k]; } } }
        return $t;
    };
    $fin = static function (?array $c): ?array {
        if ($c === null) { return null; }
        foreach (['reclame', 'accepte', 'livre'] as $k) { $c[$k] = round($c[$k], 2); }
        $c['part'] = $c['livre'] > 0 ? round(100 * $c['reclame'] / $c['livre'], 2) : null;
        return $c;
    };
    $magasins = [];
    foreach ($grille as $s => $parMois) {
        $mois = [];
        for ($m = 1; $m <= 12; $m++) { $mois[] = $m > $moisMax ? null : $fin($parMois[$m] ?? $vide()); }
        $magasins[] = ['id' => (string) $s, 'nom' => $noms[$s] ?? ('Magasin ' . $s), 'mois' => $mois, 'total' => $fin($somme($parMois))];
    }
    // Seuls comptent les magasins qui ont réclamé ou reçu quelque chose ; le plus réclamant d'abord.
    $magasins = array_values(array_filter($magasins, fn ($x) => $x['total']['n'] > 0 || $x['total']['livre'] > 0));
    usort($magasins, fn ($a, $b) => $b['total']['reclame'] <=> $a['total']['reclame']);
    $reseauMois = [];
    for ($i = 0; $i < 12; $i++) {
        $reseauMois[] = $i + 1 > $moisMax ? null : $fin($somme(array_map(fn ($x) => $x['mois'][$i], $magasins)));
    }
    $reseau = $fin($somme(array_map(fn ($x) => $x['total'], $magasins)));

    return ['annee' => $annee, 'fournisseur' => $choix,
        'fournisseurs' => array_values(array_map(fn ($f) => ['nom' => $f['nom'], 'n' => $f['n']], $parF)),
        'magasins' => $magasins, 'reseau' => ['mois' => $reseauMois, 'total' => $reseau],
        'livreLu' => $livres !== null, 'matieres' => count($matieres), 'prixConnus' => count($prix),
        'source' => 'Réclamations : API panel (/consultant/shops/material-complaints). Livré : grand livre matière du panel '
            . '(material_inventory_log, entrées liées à une commande fournisseur), au prix d’achat.'];
}

/**
 * La photo d'une tâche contrôlée, en octets — pour la réclamation créée depuis le contrôle du cockpit : la photo du
 * contrôle devient la photo de la réclamation. Le lien vient du panel lui-même (pièce jointe de l'avancement de la
 * checklist), jamais d'une adresse fournie par le navigateur. Rend null si la tâche n'a pas de photo lisible.
 */
function rcPhotoTache(int $shop, int $task, string $date, ?callable $telecharger = null): ?string
{
    if ($shop <= 0 || $task <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
    $att = 0;
    foreach (PanelApi::shopChecklists($shop, $date) as $cl) {
        $cid = (int) ($cl['id'] ?? $cl['checklist_id'] ?? 0);
        if ($cid <= 0) { continue; }
        foreach (PanelApi::checklistProgress($shop, $cid, $date) as $p) {
            if ((int) ($p['task_id'] ?? $p['id'] ?? 0) === $task && (int) ($p['attachment_id'] ?? 0) > 0) { $att = (int) $p['attachment_id']; break 2; }
        }
    }
    $url = $att > 0 ? PanelApi::attachmentUrl($att) : null;
    if ($url === null || !preg_match('#^https://#', $url)) { return null; }
    return ($telecharger ?? 'rcTelecharger')($url);
}

/** Les octets d'une image en https (6 Mo au plus, 15 s), ou null. */
function rcTelecharger(string $url): ?string
{
    if (!function_exists('curl_init')) { return null; }
    $ch = curl_init($url);
    $buf = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $d) use (&$buf): int {
            $buf .= $d;
            return strlen($buf) > 6 * 1024 * 1024 ? 0 : strlen($d);   // au-delà de 6 Mo : on coupe
        }]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $ok !== false && $code === 200 && $buf !== '' ? $buf : null;
}
