<?php
declare(strict_types=1);

/*
 * ADAPTER MON PRIX DE VENTE — le bouton de l'onglet « Recette & marge » de la fiche produit du dashboard magasin
 * (demande du 21/11/2026 : « rajouter un bouton (adapter mon prix de vente) », « post dans erp »).
 *
 *   POST /analyse/produits/prix {shop, pid, prixHt[, apercu]}
 *
 * L'onglet teste un prix HORS TVA (le prix encaissé de la pièce, celui du coefficient). Le panel, lui, porte le prix
 * de vente TTC du magasin (`portion_price` de /shops/{id}/products/available). La route fait le passage :
 *   prix testé HT × (1 + TVA du produit) → prix TTC, arrondi aux 5 centimes les plus proches ;
 *   un produit divisible vendu à la portion : le prix écrit est celui de la portion (prix de la pièce × portion_size) ;
 *   au-dessus du prix maximum du réseau (`max_portion_price`), rien n'est écrit (`bloque: max`) : l'écran propose
 *   « Demander au réseau de relever le maximum », une demande au bureau par le helpdesk (POST /helpdesk/magasin,
 *   domaine Ventes : le dossier au panel, le SMS au bureau, la liste du cockpit des consultants).
 * `apercu: true` rend ce plan sans rien écrire : l'écran le montre avant de confirmer.
 *
 * L'écriture : PATCH /shops/{s}/products/{p}/price {portion_price} — le contrat du panel
 * (consultant/docs/contrat-panel-produits.md), dans le format réglé du cockpit s'il y en a un (`ppmFormat('prix')`).
 * Le prix est relu ensuite (/shops/{s}/products/{p}) : accepté mais inchangé à la relecture = « sans_effet ».
 * Chaque envoi est gardé dans `panel_prix_modif` (qui, quand, avant, après, la réponse du panel).
 */

const PXP_PRIX_MAX = 10000.0;

function ensurePrixModif(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_prix_modif ('
        . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'produit_id INT UNSIGNED NOT NULL,'
        . 'produit VARCHAR(190) NOT NULL,'
        . 'avant_ttc DECIMAL(10,2) NULL,'          // le prix du panel avant l'envoi (celui de la portion écrite)
        . 'apres_ttc DECIMAL(10,2) NOT NULL,'      // le prix envoyé
        . 'prix_ht DECIMAL(10,4) NOT NULL,'        // le prix testé à l'écran, hors TVA, la pièce entière
        . 'tva DECIMAL(5,2) NOT NULL,'
        . 'http SMALLINT NOT NULL,'
        . 'resultat VARCHAR(16) NOT NULL,'         // ok · sans_effet · refuse
        . 'message VARCHAR(400) NULL,'
        . 'cree_le DATETIME NOT NULL,'
        . 'cree_par VARCHAR(120) NULL,'
        . 'PRIMARY KEY (id), KEY k_shop (shop_id, produit_id, cree_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Première valeur numérique parmi des clés. */
function pxpNum(array $x, array $cles): ?float
{
    foreach ($cles as $k) { if (isset($x[$k]) && is_numeric($x[$k])) { return (float) $x[$k]; } }
    return null;
}

/** Un prix TTC arrondi aux 5 centimes les plus proches. */
function pxpArrondi5(float $p): float
{
    return round(round($p * 20) / 20, 2);
}

/**
 * Le plan d'un changement de prix, depuis la ligne du produit (products/available) et le prix testé hors TVA de la
 * pièce entière. Fonction pure. Rend {tva, part, avant, avantPiece, ecrit, pieceTtc, pieceHt, max, change, erreur,
 * bloque: max | inchange | tva | bas | null}.
 */
function pxpPlan(array $l, float $prixHt): array
{
    $tva = pxpNum($l, ['tax_value_percent', 'tax_val_perc']);
    $divisible = (int) ($l['is_divisible'] ?? 0) === 1;
    $taille = pxpNum($l, ['portion_size']);
    $part = $divisible && $taille !== null && $taille > 0 && $taille <= 1 ? $taille : 1.0;
    $avant = pxpNum($l, ['portion_price_gross', 'portion_price']);
    if ($avant !== null && $avant <= 0) { $avant = null; }
    $max = pxpNum($l, ['max_portion_price']);
    if ($max !== null && $max <= 0) { $max = null; }
    $p = ['tva' => $tva, 'part' => $part, 'avant' => $avant !== null ? round($avant, 2) : null,
        'avantPiece' => $avant !== null ? round($avant / $part, 2) : null,
        'ecrit' => null, 'pieceTtc' => null, 'pieceHt' => null, 'max' => $max !== null ? round($max, 2) : null, 'change' => false, 'erreur' => null, 'bloque' => null];
    if ($tva === null || $tva < 0 || $tva > 30) { $p['erreur'] = 'Le panel ne donne pas la TVA de ce produit : le prix TTC ne se calcule pas.'; $p['bloque'] = 'tva'; return $p; }
    $ecrit = pxpArrondi5($prixHt * (1 + $tva / 100) * $part);
    if ($ecrit <= 0) { $p['erreur'] = 'Ce prix est trop bas pour être écrit.'; $p['bloque'] = 'bas'; return $p; }
    $p['ecrit'] = $ecrit;
    $p['pieceTtc'] = round($ecrit / $part, 2);
    $p['pieceHt'] = round($ecrit / $part / (1 + $tva / 100), 2);
    $p['change'] = $avant === null || abs($ecrit - $avant) >= 0.005;
    $eur = static fn (float $v): string => number_format($v, 2, ',', ' ') . ' €';
    if ($max !== null && $ecrit > $max + 0.005) {
        $p['erreur'] = 'Au-dessus du prix maximum du réseau : ' . $eur($ecrit) . ' TTC demandé, ' . $eur($max) . ' TTC au plus.';
        $p['bloque'] = 'max';   // l'écran propose alors d'en faire la demande au réseau (helpdesk)
    } elseif (!$p['change']) {
        $p['bloque'] = 'inchange';
        $p['erreur'] = 'Le panel porte déjà ce prix : ' . $eur($ecrit) . ' TTC.';
    }
    return $p;
}

/** La ligne d'un produit dans une liste products/available, null s'il n'y est pas. Fonction pure. */
function pxpLigne(array $lignes, int $pid): ?array
{
    foreach ($lignes as $l) {
        if (is_array($l) && (int) ($l['id'] ?? $l['id_product'] ?? $l['product_id'] ?? 0) === $pid) { return $l; }
    }
    return null;
}

/** L'envoi au panel : le format réglé du cockpit (ppmFormat) s'il est chargé, sinon celui du contrat. Fonction pure hors réglage. */
function pxpEnvoi(int $shop, int $pid, float $prix): array
{
    $f = function_exists('ppmFormat') ? ppmFormat('prix') : null;
    if (!is_array($f) || !function_exists('ppmCorps')) {
        return ['methode' => 'PATCH', 'route' => '/shops/' . $shop . '/products/' . $pid . '/price', 'corps' => ['portion_price' => $prix]];
    }
    return ['methode' => $f['methode'], 'route' => str_replace(['{sid}', '{pid}'], [(string) $shop, (string) $pid], $f['route']),
        'corps' => ppmCorps($f, ['{prix}' => $prix, '{pid}' => $pid, '{sid}' => $shop])];
}

/** Le prix d'un produit relu au panel après l'écriture (/shops/{s}/products/{p}), null s'il ne se lit pas. */
function pxpRelire(int $shop, int $pid): ?float
{
    $d = PanelApi::get('/shops/' . $shop . '/products/' . $pid);
    if (!is_array($d)) { return null; }
    foreach (['data', 'product'] as $k) { if (isset($d[$k]) && is_array($d[$k]) && !array_is_list($d[$k])) { $d = $d[$k]; } }
    $v = pxpNum($d, ['portion_price_gross', 'portion_price', 'price', 'sale_price']);
    return $v !== null && $v > 0 ? $v : null;
}

/** POST /analyse/produits/prix — le prix testé devient le prix de vente du magasin au panel (voir plus haut). */
function wr_analyse_produit_prix(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $pid = isset($b['pid']) && is_scalar($b['pid']) && preg_match('/^\d{1,10}$/', (string) $b['pid']) ? (int) $b['pid'] : 0;
    $prixHt = $b['prixHt'] ?? null;
    $apercu = !empty($b['apercu']);
    if ($shop <= 0 || $pid <= 0) { http_response_code(400); return ['error' => 'magasin et produit attendus']; }
    if (!is_numeric($prixHt) || (float) $prixHt <= 0 || (float) $prixHt > PXP_PRIX_MAX) { http_response_code(422); return ['error' => 'prixHt : un prix positif, hors TVA']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'compte du panel non configuré']; }
    $prixHt = round((float) $prixHt, 4);
    $l = pxpLigne(PanelApi::produitsDisponibles($shop), $pid);
    if ($l === null) { http_response_code(404); return ['error' => 'Ce produit n’est pas dans la liste du magasin au panel : son prix ne se règle pas d’ici.']; }
    $nom = '';
    foreach (['base_name', 'name', 'product_name'] as $k) { if (!empty($l[$k]) && is_string($l[$k])) { $nom = trim($l[$k]); break; } }
    $p = pxpPlan($l, $prixHt);
    $base = ['shop' => $shop, 'pid' => $pid, 'nom' => $nom, 'prixHt' => $prixHt] + $p;
    if ($p['erreur'] !== null) { if (!$apercu) { http_response_code(422); return ['error' => $p['erreur']] + $base; } return ['ok' => false, 'apercu' => true] + $base; }
    if ($apercu) { return ['ok' => true, 'apercu' => true] + $base; }

    $e = pxpEnvoi($shop, $pid, (float) $p['ecrit']);
    $r = PanelApi::requete($e['methode'], $e['route'], $e['corps']);
    $accepte = $r['code'] >= 200 && $r['code'] < 300;
    $relu = $accepte ? pxpRelire($shop, $pid) : null;
    $resultat = !$accepte ? 'refuse' : ($relu === null || abs($relu - (float) $p['ecrit']) < 0.005 ? 'ok' : 'sans_effet');
    $message = match ($resultat) {
        'refuse' => 'Le panel a refusé ce prix (' . ($r['erreur'] ?? 'pas de réponse') . ') : rien n’a changé.',
        'sans_effet' => 'Le panel a accepté, mais le prix relu vaut ' . number_format((float) $relu, 2, ',', ' ') . ' € : rien n’a changé.',
        default => $relu === null ? 'Accepté par le panel ; le prix n’a pas pu être relu.' : '',
    };
    try {
        ensurePrixModif();
        $qui = function_exists('accesIdentite') ? accesIdentite() : null;
        Db::exec('INSERT INTO panel_prix_modif (shop_id, produit_id, produit, avant_ttc, apres_ttc, prix_ht, tva, http, resultat, message, cree_le, cree_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) $shop, $pid, mb_substr($nom !== '' ? $nom : 'produit ' . $pid, 0, 190), $p['avant'], $p['ecrit'], $prixHt, $p['tva'], (int) $r['code'], $resultat,
                $message !== '' ? mb_substr($message, 0, 400) : null, date('Y-m-d H:i:s'), is_array($qui) && isset($qui['nom']) ? mb_substr((string) $qui['nom'], 0, 120) : null]);
    } catch (Throwable $ex) { /* le journal ne bloque pas l'écriture */ }
    if ($resultat === 'ok' && method_exists(PanelApi::class, 'oublierDispo')) { PanelApi::oublierDispo(); }
    if ($resultat === 'refuse') { http_response_code(502); return ['error' => $message, 'resultat' => $resultat] + $base; }
    return ['ok' => $resultat === 'ok', 'resultat' => $resultat, 'message' => $message, 'relu' => $relu !== null ? round($relu, 2) : null] + $base;
}
