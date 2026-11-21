<?php
declare(strict_types=1);

/**
 * Les campagnes du réseau, lues au module Marketing (21/11/2026, lot 2 de la correction des lectures SQL directes).
 *
 * Le calendrier, les campagnes et les types de campagne (GET /marketing) et le budget des campagnes
 * (GET /marketing/budget-campagnes, shared/src/endpoints.php) lisaient mar_brand, mar_campaign, mar_campaign_type,
 * mar_campaign_status, mar_campaign_shop et mar_lever en SQL ; ils les lisent par l'API du module (marketingAppel,
 * shared/src/endpoints.php : jeton de service, identité « marque »). Les réponses gardent leurs clés.
 */

/** Toutes les campagnes du module (GET /campaigns, les plus récentes d'abord) : [liste, null], ou [null, raison]. */
function campagnesModule(): array
{
    $r = marketingAppel('GET', '/campaigns');
    if ($r['erreur'] !== null) { return [null, $r['erreur']]; }
    return [is_array($r['corps']) && array_is_list($r['corps']) ? $r['corps'] : [], null];
}

/**
 * Les campagnes qui touchent un exercice (commencées, et pas finies avant le 1er janvier), de la plus ancienne à la
 * plus récente, dans la forme que lisait le budget des campagnes : [lignes, null], ou [null, raison].
 */
function campagnesDeLExercice(int $exercice): array
{
    [$toutes, $err] = campagnesModule();
    if ($toutes === null) { return [null, $err]; }
    $du = $exercice . '-01-01'; $au = $exercice . '-12-31';
    $out = [];
    foreach ($toutes as $c) {
        $debut = $c['starts_on'] ?? null; $fin = $c['ends_on'] ?? null;
        if ($debut === null || $debut > $au || ($fin !== null && $fin < $du)) { continue; }
        $out[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'starts_on' => $debut, 'ends_on' => $fin,
            'status_code' => $c['status_code'] ?? null, 'statut' => $c['status_label'] ?? null,
            'type_label' => $c['type_label'] ?? null, 'type_color' => $c['type_color_hex'] ?? null];
    }
    usort($out, static fn ($a, $b) => [$a['starts_on'], $a['id']] <=> [$b['starts_on'], $b['id']]);
    return [$out, null];
}

/** Les boutiques d'une campagne (GET /campaigns/{id} → shops) : [identifiants, null], ou [null, raison]. */
function campagneBoutiques(int $id): array
{
    $r = marketingAppel('GET', '/campaigns/' . $id);
    if ($r['erreur'] !== null) { return [null, $r['erreur']]; }
    $ids = [];
    foreach ((array) ($r['corps']['shops'] ?? []) as $s) {
        if (is_array($s) && isset($s['shop_id'])) { $ids[] = (string) $s['shop_id']; }
    }
    return [$ids, null];
}

/**
 * GET /marketing — campagnes, calendrier et types, lus au module Marketing.
 *
 * Pilotage → Calendrier / Campagnes / Types de campagne : le module tient les campagnes et leurs référentiels, le
 * cockpit les affiche. Les types arrivent TOUS (actifs ou non), avec tout ce que le module en connaît — description,
 * couleur, icône, levier lié et le nombre de campagnes qui les portent (c'est lui qui décide si « Supprimer » a un
 * sens). `levier` : le badge du type s'il en porte un, sinon le nom du levier lié (le module fait ce COALESCE), et en
 * dernier recours `default_lever_code`, que portent les types d'avant la liaison.
 */
function ep_mkt(): array
{
    $indispo = static fn (string $quoi, ?string $err): array => ['indispo' => true,
        'raison' => 'Le module Marketing ne rend pas ' . $quoi . ' : ' . ($err ?? 'réponse illisible') . '.'];
    $marques = marketingAppel('GET', '/brands');
    if ($marques['erreur'] !== null) { return $indispo('les marques', $marques['erreur']); }
    $ref = marketingAppel('GET', '/references');
    if ($ref['erreur'] !== null || !is_array($ref['corps'])) { return $indispo('ses référentiels', $ref['erreur']); }
    $typ = marketingAppel('GET', '/campaign-types');
    if ($typ['erreur'] !== null || !is_array($typ['corps']['types'] ?? null)) { return $indispo('les types de campagne', $typ['erreur']); }
    [$camps, $errC] = campagnesModule();
    if ($camps === null) { return $indispo('les campagnes', $errC); }

    $statuts = array_map(fn ($s) => ['code' => $s['code'], 'nom' => $s['label'],
        'texte' => $s['text_hex'] ?? null, 'fond' => $s['bg_rgba'] ?? null, 'ordre' => (int) ($s['sort_order'] ?? 0)],
        array_values(array_filter((array) ($ref['corps']['campaignStatuses'] ?? []), 'is_array')));

    $types = array_map(fn ($t) => [
        'id' => (int) $t['id'], 'code' => $t['code'] ?? null, 'nom' => $t['label'] ?? null,
        'description' => $t['description'] ?? null,
        'levier' => $t['lever_label'] ?? $t['default_lever_code'] ?? null,
        'levierId' => isset($t['lever_id']) && $t['lever_id'] !== null ? (int) $t['lever_id'] : null,
        'levierBadge' => $t['lever_badge_label'] ?? null,
        'levierCouleur' => $t['lever_color_hex'] ?? null,
        'kpi' => $t['default_kpi_label'] ?? null,
        'couleur' => $t['color_hex'] ?? null,
        'icone' => $t['icon_key'] ?? null, 'iconePath' => $t['icon_path'] ?? null,
        'ordre' => (int) ($t['sort_order'] ?? 0),
        'actif' => (bool) ($t['is_active'] ?? false),
        'nCampagnes' => (int) ($t['campaigns'] ?? 0)],
        array_values(array_filter((array) $typ['corps']['types'], 'is_array')));

    $campagnes = [];
    foreach ($camps as $c) {
        if (!is_array($c)) { continue; }
        $campagnes[] = [
            'id' => (int) $c['id'], 'nom' => (string) $c['name'],
            'typeId' => isset($c['type_id']) && $c['type_id'] !== null ? (int) $c['type_id'] : null,
            'type' => $c['type_label'] ?? null, 'typeCouleur' => $c['type_color_hex'] ?? null,
            'scope' => (string) ($c['scope'] ?? ''),
            'statut' => (string) ($c['status_code'] ?? ''), 'statutNom' => $c['status_label'] ?? $c['status_code'] ?? null,
            'statutTexte' => $c['status_text_hex'] ?? '#666666', 'statutFond' => $c['status_bg_rgba'] ?? 'rgba(120,116,110,.12)',
            'debut' => $c['starts_on'] ?? null, 'fin' => $c['ends_on'] ?? null,
            'budget' => (float) ($c['budget_amount'] ?? 0), 'depense' => (float) ($c['spent_amount'] ?? 0),
            'nBoutiques' => (int) ($c['shops_count'] ?? 0),
            'image' => ($c['image_url'] ?? '') ?: null,
        ];
    }

    // Les leviers et la bibliothèque d'icônes accompagnent les types : l'écran ne redéclare ni les uns ni les autres,
    // il affiche ce que le serveur sert.
    $leviers = array_map(fn ($l) => ['id' => (int) $l['id'], 'code' => $l['code'] ?? null, 'nom' => $l['label'] ?? null,
        'couleur' => $l['color_hex'] ?? null], array_values(array_filter((array) ($ref['corps']['levers'] ?? []), 'is_array')));

    // La marque : la première (le plus petit identifiant), comme avant.
    $liste = array_values(array_filter(is_array($marques['corps']) ? $marques['corps'] : [], 'is_array'));
    usort($liste, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

    return ['campagnes' => $campagnes, 'types' => $types, 'statuts' => $statuts,
        'leviers' => $leviers, 'icones' => mktIcones(),
        'marqueId' => $liste ? (int) $liste[0]['id'] : null,
        'marque' => $liste ? (string) $liste[0]['name'] : ''];
}
