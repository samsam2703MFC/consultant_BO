<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/src/doc.php';

/**
 * Cockpit CEO — les outils de lecture des ventes par référence, et le retrait du catalogue.
 *
 * L'écran « Usage du catalogue » est parti dans le Catalogue (18/10/2026) ; ses routes (/produits/utilisation…) sont
 * retirées le 19/11/2026 (docs/audit-rail.md § 5). Restent les outils que d'autres lisent : les colonnes des lignes
 * de ticket (utilColonnes : Manque à gagner, conformité de visite), les mois et les gammes (utilMois,
 * utilEcarterHorsGamme : Manque à gagner), et le retrait ou la remise au catalogue (prodActifEcrire : Structure de
 * gamme ; POST /produits/actif, qui défait un retrait).
 *
 * La lecture se fait sur les LIGNES DE TICKET (`transaction_product`), la seule
 * source qui relie une référence à un magasin et à une date. Les noms de
 * tables et de colonnes sont RÉSOLUS, jamais supposés : la caisse a changé de
 * schéma une fois déjà.
 */

/** Les colonnes candidates, comme le module marketing les connaît. */
const UTIL_COLS_TICKET = [
    'id'   => ['id', 'id_transaction', 'transaction_id'],
    'shop' => ['id_shop', 'shop_id', 'id_franchisee_shop'],
    'date' => ['insert_timestamp', 'created_at', 'sale_date', 'sold_at', 'timestamp'],
];
const UTIL_COLS_LIGNE = [
    'ticket'   => ['id_transaction', 'transaction_id'],
    'produit'  => ['id_product', 'product_id'],
    'quantite' => ['quantity', 'qty'],
];

/**
 * Les colonnes réellement présentes, par notion.
 *
 * La clé du résultat est lue SANS la nommer : MySQL rend `COLUMN_NAME` ou
 * `column_name` selon la configuration, et nommer la mauvaise rend une liste
 * vide — donc « aucune colonne », donc un écran muet sans erreur.
 *
 * @return array<string,string>|null
 */
function utilColonnes(string $table, array $candidats): ?array
{
    $presentes = [];
    try {
        foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]) as $r) {
            $v = is_array($r) ? (string) reset($r) : (string) $r;
            if ($v !== '') { $presentes[strtolower($v)] = $v; }
        }
    } catch (Throwable $e) { return null; }
    if ($presentes === []) { return null; }

    $out = [];
    foreach ($candidats as $notion => $noms) {
        foreach ($noms as $nom) {
            if (isset($presentes[strtolower($nom)])) { $out[$notion] = $presentes[strtolower($nom)]; break; }
        }
        if (!isset($out[$notion])) { return null; }
    }
    return $out;
}

/**
 * Les gammes saisonnières, par référence de caisse.
 *
 * Le catalogue n'est pas une liste plate : 441 références portent une gamme —
 * Noël, Pascale, Estivale, Saint-Nicolas. Une bûche non vendue entre mars et
 * août n'est pas une bûche délaissée : sa gamme n'était pas ouverte. Les
 * compter avec les autres fait deux dégâts d'un coup — le taux d'usage du
 * magasin baisse sans qu'il y soit pour rien, et la liste des « jamais
 * vendues » se remplit de références qu'il était normal de ne pas vendre.
 *
 * Les gammes sont RÉCURRENTES (mesuré : les 14 le sont) : l'année portée par
 * `start_date` ne veut rien dire, seuls comptent le jour et le mois. On
 * travaille donc en MOIS, ce qui suffit à décider et se raconte en une phrase.
 *
 * @return array{parProduit:array<int,list<string>>,mois:array<string,list<int>>}
 */
function utilGammes(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = ['parProduit' => [], 'mois' => []];

    $periodes = [];
    try {
        foreach (Db::rows('SELECT id, name, start_date, end_date
                             FROM product_availability_period WHERE is_active = 1') as $p) {
            $du = (string) ($p['start_date'] ?? '');
            $au = (string) ($p['end_date'] ?? '');
            $nom = (string) $p['name'];
            $mois = [];
            $t1 = $du !== '' ? strtotime($du) : false;
            $t2 = $au !== '' ? strtotime($au) : false;
            if ($t1 === false || $t2 === false || $t2 - $t1 >= 364 * 86400) {
                // Une gamme qui couvre l'année (la « Gamme Standard » va du
                // 1er janvier au 31 décembre) n'est pas une saison : elle est
                // ouverte tout le temps.
                $mois = range(1, 12);
            } else {
                $m1 = (int) date('n', $t1); $m2 = (int) date('n', $t2);
                $m = $m1;
                for ($i = 0; $i < 12; $i++) {
                    $mois[] = $m;
                    if ($m === $m2) { break; }
                    $m = $m === 12 ? 1 : $m + 1;   // les gammes passent l'hiver
                }
            }
            $periodes[(int) $p['id']] = ['nom' => $nom, 'mois' => $mois];
            $cache['mois'][$nom] = $mois;
        }
        foreach (Db::rows('SELECT id_product, id_period FROM product_availability_period_connection') as $k) {
            $pid = (int) $k['id_product'];
            $per = $periodes[(int) $k['id_period']] ?? null;
            if ($per === null) { continue; }
            $cache['parProduit'][$pid][] = $per['nom'];
        }
    } catch (PDOException $e) { /* pas de gammes lisibles : tout est permanent */ }
    return $cache;
}

/** Les mois (1..12) que couvre la fenêtre d'analyse. */
function utilMoisCouverts(array $mois): array
{
    $out = [];
    foreach ($mois as $m) { $out[(int) substr($m['cle'], 5, 2)] = true; }
    return array_keys($out);
}

/**
 * Cette référence pouvait-elle se vendre sur la fenêtre lue ?
 *
 * Sans gamme, elle est permanente : oui. Avec des gammes, il suffit qu'une
 * seule ouvre sur un des mois lus — une référence peut appartenir à la gamme
 * standard ET à une gamme de fête.
 */
function utilOuverteSur(array $gammesDuProduit, array $moisGamme, array $moisFenetre): bool
{
    if ($gammesDuProduit === []) { return true; }
    foreach ($gammesDuProduit as $nom) {
        $m = $moisGamme[$nom] ?? null;
        if ($m === null) { return true; }   // gamme illisible : on n'écarte pas
        if (array_intersect($m, $moisFenetre) !== []) { return true; }
    }
    return false;
}

/**
 * Retire de $refs ce qu'une gamme fermée rendait invendable sur la fenêtre.
 *
 * Passe par référence : les trois lectures — le tableau, le détail d'un
 * magasin, la liste des jamais vendues — doivent écarter EXACTEMENT le même
 * ensemble, sinon trois écrans annoncent trois catalogues.
 *
 * @param array<int,array<string,mixed>> $refs
 * @return array{n:int,gammes:array<string,int>}
 */
function utilEcarterHorsGamme(array &$refs, array $mois, bool $tout): array
{
    if ($tout) { return ['n' => 0, 'gammes' => []]; }
    $g = utilGammes();
    if ($g['parProduit'] === []) { return ['n' => 0, 'gammes' => []]; }
    $fenetre = utilMoisCouverts($mois);
    $n = 0; $par = [];
    foreach ($refs as $pid => $r) {
        $sien = $g['parProduit'][$pid] ?? [];
        if (utilOuverteSur($sien, $g['mois'], $fenetre)) { continue; }
        foreach ($sien as $nom) { $par[$nom] = ($par[$nom] ?? 0) + 1; }
        unset($refs[$pid]);
        $n++;
    }
    arsort($par);
    return ['n' => $n, 'gammes' => $par];
}

/** Les N derniers mois, du plus ancien au plus récent. */
function utilMois(int $n): array
{
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $t = strtotime("-$i month", strtotime(date('Y-m-01')));
        $out[] = ['cle' => date('Y-m', $t), 'lib' => strftime_fr($t, 'M Y'),
            'du' => date('Y-m-01', $t), 'au' => date('Y-m-t', $t),
            'encours' => date('Y-m', $t) === date('Y-m')];
    }
    return $out;
}

/**
 * Les références vendues entre deux dates, d'après le PANEL : la ligne
 * `sold_qty` de /shops/{id}/products/waste, magasin par magasin. Sert de
 * garde-fou depuis le gel de la caisse locale (14/07/2026) — sans lui, une
 * référence vendue depuis juillet passait pour dormante.
 *
 * `sold_qty` a été mesuré RÉSEAU (la même valeur dans chaque magasin) : peu
 * importe ici, on ne retient que « vendue quelque part ». Tous les magasins
 * actifs doivent répondre : une vérification partielle n'en est pas une.
 *
 * @return array{ok: bool, vendus: array<int, float>, motif: ?string}
 */
function prodVendusPanel(string $du, string $au): array
{
    try { $shops = array_map(static fn ($r) => (int) $r['id'], Db::rows('SELECT id FROM shops WHERE active = 1')); }
    catch (PDOException $e) { return ['ok' => false, 'vendus' => [], 'motif' => 'liste des magasins illisible']; }
    if ($shops === []) { return ['ok' => false, 'vendus' => [], 'motif' => 'aucun magasin actif']; }
    $q = http_build_query(['from' => $du, 'date_from' => $du, 'to' => $au, 'date_to' => $au]);
    $chemins = [];
    foreach ($shops as $sid) { $chemins[$sid] = '/shops/' . $sid . '/products/waste?' . $q; }
    $vendus = []; $muets = [];
    // Trois de front : douze appels concurrents sur cette route en laissaient neuf sans réponse.
    foreach (PanelApi::getParallele($chemins, 3) as $sid => $r) {
        if (!is_array($r)) { $muets[] = (int) $sid; continue; }
        $lignes = isset($r['products']) && is_array($r['products']) ? $r['products'] : PanelApi::liste($r);
        foreach ($lignes as $l) {
            $pid = (int) ($l['id_product'] ?? $l['product_id'] ?? 0);
            $qv = (float) ($l['sold_qty'] ?? 0);
            if ($pid > 0 && $qv > 0) { $vendus[$pid] = max($vendus[$pid] ?? 0.0, $qv); }
        }
    }
    $muets = array_values(array_unique($muets));
    if ($muets !== []) {
        return ['ok' => false, 'vendus' => $vendus,
            'motif' => 'ventes illisibles au panel pour ' . count($muets) . ' magasin(s) (#' . implode(', #', $muets) . ')'];
    }
    return ['ok' => true, 'vendus' => $vendus, 'motif' => null];
}

/**
 * Retirer ou remettre UNE référence au catalogue TFBuddy :
 * PATCH /products/{id} { is_active }. Le compte admin de l'ERP d'abord (le
 * catalogue est l'affaire du franchiseur), le compte consultant ensuite.
 *
 * Le contrat de la route n'est pas documenté au swagger (09/11/2026). D'où
 * une écriture encadrée : la fiche est LUE avant (état et nom), le corps ne
 * porte QUE `is_active`, et la fiche est RELUE après. Le geste ne compte que
 * si la relecture montre le nouvel état ; un nom qui aurait changé entre les
 * deux lectures est signalé en clair — c'est le signe d'une route qui
 * remplace la fiche au lieu de la compléter.
 *
 * @return array{ok: bool, deja?: bool, canal?: string, motif?: string, alerte?: string}
 */
function prodActifTfb(int $pid, bool $actif): array
{
    $canaux = [];
    if (ErpApi::disponible()) { $canaux['ERP admin'] = 'erp'; }
    if (PanelApi::configured()) { $canaux['panel consultant'] = 'panel'; }
    $motifs = [];
    foreach ($canaux as $nom => $c) {
        $lire = static fn () => $c === 'erp' ? ErpApi::get('/products/' . $pid) : PanelApi::get('/products/' . $pid);
        $avant = $lire();
        if (!is_array($avant)) { $motifs[] = $nom . ' : fiche illisible (' . (($c === 'erp' ? ErpApi::$lastError : PanelApi::$lastError) ?? 'sans réponse') . ')'; continue; }
        $avant = $avant['product'] ?? $avant;
        if (array_key_exists('is_active', $avant) && (bool) (int) $avant['is_active'] === $actif) {
            return ['ok' => true, 'deja' => true, 'canal' => $nom];
        }
        $corps = ['is_active' => $actif ? 1 : 0];
        if ($c === 'erp') {
            $w = ErpApi::ecrire('PATCH', '/products/' . $pid, $corps);
            $ok = $w['erreur'] === null; $err = $w['erreur'];
        } else {
            [$ok] = PanelApi::envoi('PATCH', '/products/' . $pid, $corps);
            $err = PanelApi::$lastError;
        }
        if (!$ok) { $motifs[] = $nom . ' : ' . ($err ?? 'écriture refusée'); continue; }
        $apres = $lire();
        $apres = is_array($apres) ? ($apres['product'] ?? $apres) : null;
        if (!is_array($apres) || !array_key_exists('is_active', $apres)) {
            return ['ok' => false, 'canal' => $nom, 'motif' => 'écriture acceptée, mais la relecture ne dit pas l’état — à vérifier dans TFBuddy'];
        }
        if ((bool) (int) $apres['is_active'] !== $actif) {
            return ['ok' => false, 'canal' => $nom, 'motif' => 'écriture acceptée, mais la fiche relue garde l’ancien état'];
        }
        $alerte = null;
        if (isset($avant['name']) && (string) ($apres['name'] ?? '') !== (string) $avant['name']) {
            $alerte = 'le nom de la fiche a changé pendant l’écriture (« ' . $avant['name'] . ' » → « ' . ($apres['name'] ?? '') . ' ») : vérifier la fiche dans TFBuddy';
        }
        return ['ok' => true, 'canal' => $nom] + ($alerte !== null ? ['alerte' => $alerte] : []);
    }
    return ['ok' => false, 'motif' => $motifs !== [] ? implode(' ; ', $motifs) : 'aucun compte API configuré'];
}

/**
 * Activer ou retirer UNE référence : le seul geste du cockpit sur la fiche produit, un booléen, jamais
 * une suppression. Avec un compte API, la source est TFBuddy (prodActifTfb : écrit, relit, vérifie) et la
 * copie locale suit ; sans compte, la copie seule. Partagé par « Retirer du catalogue » (POST
 * /produits/actif) et la structure de gamme (POST /produits/structure/desactiver).
 *
 * @return array{ok:bool, deja?:bool, ou?:string, motif?:string, alerte?:?string, erreurBase?:string}
 */
function prodActifEcrire(int $pid, bool $actif, bool $api): array
{
    if ($api) {
        $tfb = prodActifTfb($pid, $actif);
        if (!$tfb['ok']) { return ['ok' => false, 'motif' => $tfb['motif'] ?? 'refusé']; }
        // La copie suit la source ; elle peut manquer, la source a déjà la main.
        try { Db::exec('UPDATE product SET is_active = ? WHERE id = ?', [$actif ? 1 : 0, $pid]); } catch (PDOException $e) { /* sans copie */ }
        return ['ok' => true, 'deja' => !empty($tfb['deja']), 'ou' => 'TFBuddy (' . $tfb['canal'] . ')', 'alerte' => $tfb['alerte'] ?? null];
    }
    try {
        // `rowCount()` de l'UPDATE lui-même : MySQL rend 0 quand la valeur
        // était déjà celle-là — c'est exactement « rien à faire ici ».
        $n = Db::exec('UPDATE product SET is_active = ? WHERE id = ?', [$actif ? 1 : 0, $pid]);
    } catch (PDOException $e) {
        return ['ok' => false, 'erreurBase' => $e->getMessage()];
    }
    return ['ok' => true, 'deja' => $n === 0, 'ou' => 'copie locale seulement (aucun compte API)'];
}

/**
 * POST /produits/actif — retirer (ou remettre) des références du catalogue.
 *
 * LE CATALOGUE VIT CHEZ TFBUDDY. L'écriture part donc à la source
 * (`prodActifTfb()`, PATCH /products/{id}) ; la copie locale (`product`) ne
 * suit qu'une fois la source relue. Auparavant le cockpit n'écrivait que dans
 * sa copie : le catalogue TFBuddy ne bougeait pas. Sans aucun compte API
 * (installation autonome), la copie reste la seule table, et c'est elle qu'on
 * écrit — en le disant.
 *
 * Un seul champ, `is_active`. Jamais de DELETE — les lignes de ticket
 * pointent sur `product.id`, et supprimer la référence rendrait l'historique
 * illisible.
 *
 * Trois garde-fous :
 *  - une référence VENDUE sur les douze derniers mois est refusée, pas
 *    désactivée. Les ventes se lisent dans la copie de la caisse jusqu'à son
 *    gel, puis au PANEL depuis le gel (`prodVendusPanel()`) : sans ce second
 *    temps, une référence vendue depuis juillet passait pour dormante. Si le
 *    panel ne répond pas pour tous les magasins, rien n'est modifié ;
 *  - chaque référence passe au journal, nommée, avec son motif et l'endroit
 *    où elle a été écrite ;
 *  - le geste s'annule par le même appel (`actif: true`).
 *
 * Avec un compte API, 40 références au plus par appel : chacune coûte trois
 * appels (lecture, écriture, relecture).
 *
 * Body : {refs: ["6700048", …], actif: false, motif: "…"}
 */
function wr_prod_actif(): array
{
    $b = body();
    $refs = $b['refs'] ?? [];
    if (!is_array($refs) || $refs === []) {
        http_response_code(422); return ['error' => 'aucune référence fournie'];
    }
    $api = ErpApi::disponible() || PanelApi::configured();
    $plafond = $api ? 40 : 400;
    if (count($refs) > $plafond) {
        http_response_code(422); return ['error' => 'trop de références en une fois (' . $plafond . ' au plus)'];
    }
    $actif = !empty($b['actif']);
    $motif = mb_substr(trim((string) ($b['motif'] ?? '')), 0, 200);

    // ref (code cockpit) → id de caisse, et le nom pour le journal.
    $parRef = [];
    foreach (ep_prod_catalogue() as $p) {
        $parRef[(string) $p['ref']] = ['pid' => $p['pwaId'] ?? null, 'nom' => (string) $p['nom']];
    }

    // Ce qui s'est vendu sur douze mois : le garde-fou.
    $vendus = [];
    if (!$actif) {
        $douze = date('Y-m-d', strtotime('-12 months'));
        // 1) la copie de la caisse, jusqu'à son gel
        $copieLue = false; $gel = null;
        $ct = utilColonnes('transaction', UTIL_COLS_TICKET);
        $cl = utilColonnes('transaction_product', UTIL_COLS_LIGNE);
        if ($ct !== null && $cl !== null) {
            try {
                $sql = sprintf(
                    'SELECT l.`%s` AS produit, SUM(l.`%s`) AS q
                       FROM `transaction_product` l JOIN `transaction` t ON t.`%s` = l.`%s`
                      WHERE t.`%s` >= ? GROUP BY produit',
                    $cl['produit'], $cl['quantite'], $ct['id'], $cl['ticket'], $ct['date']);
                foreach (Db::rows($sql, [$douze . ' 00:00:00']) as $r) {
                    if ((float) $r['q'] > 0) { $vendus[(int) $r['produit']] = (float) $r['q']; }
                }
                $gel = Db::rows(sprintf('SELECT MAX(`%s`) AS d FROM `transaction`', $ct['date']))[0]['d'] ?? null;
                $copieLue = true;
            } catch (PDOException $e) { /* copie illisible : le panel seul, s'il est là */ }
        }
        // 2) le panel, depuis le gel (ou sur douze mois sans copie)
        if (PanelApi::configured()) {
            $du = $gel !== null ? max($douze, substr((string) $gel, 0, 10)) : $douze;
            $pv = prodVendusPanel($du, date('Y-m-d'));
            if (!$pv['ok']) {
                http_response_code(503);
                return ['error' => 'impossible de vérifier les ventes depuis le ' . date('d/m/Y', strtotime($du))
                    . ' : ' . $pv['motif'] . '. Rien n’a été modifié.'];
            }
            foreach ($pv['vendus'] as $pid => $q) { $vendus[$pid] = max($vendus[$pid] ?? 0.0, $q); }
        } elseif ($api) {
            // Le catalogue sera écrit chez TFBuddy, mais les ventes depuis le
            // gel de la caisse locale ne se lisent qu'au panel : la copie seule
            // laisserait passer une référence vendue depuis juillet.
            http_response_code(503);
            return ['error' => 'les ventes récentes se lisent au panel, dont le compte consultant n’est pas configuré. Rien n’a été modifié.'];
        } elseif (!$copieLue) {
            http_response_code(503);
            return ['error' => 'impossible de vérifier les ventes récentes : ni la caisse locale ni le panel ne répondent. Rien n’a été modifié.'];
        }
    }

    $faits = []; $refuses = []; $inconnues = []; $inchangees = []; $echecs = []; $alertes = [];
    foreach ($refs as $r) {
        $ref = trim((string) $r);
        if ($ref === '') { continue; }
        $c = $parRef[$ref] ?? null;
        if ($c === null || $c['pid'] === null) { $inconnues[] = $ref; continue; }
        $pid = (int) $c['pid'];
        if (!$actif && isset($vendus[$pid])) {
            $refuses[] = ['ref' => $ref, 'nom' => $c['nom'], 'unites' => round($vendus[$pid], 1)];
            continue;
        }
        // Le même chemin pour tout retrait : TFBuddy (ERP puis panel) relu après écriture, puis la copie locale ;
        // sans compte API, la copie seule (prodActifEcrire).
        $e = prodActifEcrire($pid, $actif, $api);
        if (isset($e['erreurBase'])) {
            http_response_code(503);
            return ['error' => 'écriture refusée par la base : ' . $e['erreurBase'],
                'faits' => count($faits), 'refuses' => $refuses];
        }
        if (!$e['ok']) { $echecs[] = ['ref' => $ref, 'nom' => $c['nom'], 'motif' => $e['motif'] ?? 'refusé']; continue; }
        if (!empty($e['alerte'])) { $alertes[] = ['ref' => $ref, 'nom' => $c['nom'], 'alerte' => $e['alerte']]; }
        if (!empty($e['deja'])) { $inchangees[] = $ref; continue; }
        $ou = $e['ou'];
        journalAdd('CEO', 'Produit', $c['nom'],
            ($actif ? 'Remise au catalogue' : 'Retirée du catalogue (is_active = 0)')
            . ' — réf. ' . $ref . ' — ' . $ou . ($motif !== '' ? ' — ' . $motif : ''));
        $faits[] = ['ref' => $ref, 'nom' => $c['nom'], 'ou' => $ou];
    }

    return ['ok' => true, 'actif' => $actif, 'motif' => $motif,
        'faits' => $faits, 'refuses' => $refuses,
        'inconnues' => $inconnues, 'inchangees' => $inchangees,
        'echecs' => $echecs, 'alertes' => $alertes,
        'resume' => count($faits) . ($actif ? ' remise(s) au catalogue' : ' retirée(s) du catalogue')
            . ($api ? ' TFBuddy' : ' (copie locale : aucun compte API)')
            . (count($refuses) ? ', ' . count($refuses) . ' refusée(s) car vendue(s) sur 12 mois' : '')
            . (count($echecs) ? ', ' . count($echecs) . ' refusée(s) par TFBuddy' : '')
            . (count($inconnues) ? ', ' . count($inconnues) . ' référence(s) inconnue(s)' : '')
            . (count($inchangees) ? ', ' . count($inchangees) . ' déjà dans cet état' : '')
            . (count($alertes) ? ' — ' . count($alertes) . ' alerte(s) à vérifier' : '')];
}
