<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les agences.
 *
 * Le réseau travaille avec PLUSIEURS agences : une pour la création, une pour
 * l'achat média, une pour le shooting. Le réglage unique — un nom, un logo,
 * une adresse — ne pouvait en porter qu'une, et la note signait toujours la
 * même quelle que soit la campagne.
 *
 * Le référentiel est celui du module Marketing (`mar_agency`) : c'est lui que
 * les canaux d'une campagne désignent déjà. Depuis le 21/11/2026 (lot 5b de la
 * correction des lectures et écritures SQL directes), le cockpit le lit et
 * l'écrit par l'API du module (marketingAppel, jeton de service) :
 * GET …/agencies, POST …/agencies, PATCH et DELETE …/agencies/{id}
 * (marketing#81). Il n'ajoute plus de colonnes à la table (la migration 045 du
 * module les porte) et ne reprend plus l'agence de l'ancien réglage unique : la
 * note retombe d'elle-même sur ce réglage quand le référentiel est vide.
 */

/**
 * Le référentiel au module, dans l'ordre de l'écran (l'agence par défaut d'abord, puis par nom, comme le rend le
 * module) : [lignes du module, null], ou [null, raison]. Lu une fois par requête, sauf `$frais` (après une écriture).
 */
function agencesModule(bool $frais = false): array
{
    static $lu = null;
    if ($lu !== null && !$frais) { return $lu; }
    $r = marketingAppel('GET', '/agencies');
    if ($r['erreur'] !== null || !is_array($r['corps']) || !array_is_list($r['corps'])) {
        $lu = null;   // une relecture ratée ne laisse pas servir l'ancienne liste
        return [null, $r['erreur'] ?? 'réponse du module illisible'];
    }
    $lignes = array_values(array_filter($r['corps'], 'is_array'));
    // Tri stable : l'agence par défaut d'abord, les autres dans l'ordre du module (par nom).
    $rang = array_flip(array_keys($lignes));
    $cles = array_keys($lignes);
    usort($cles, static fn ($a, $b) => [empty($lignes[$a]['is_default']) ? 1 : 0, $rang[$a]] <=> [empty($lignes[$b]['is_default']) ? 1 : 0, $rang[$b]]);
    return $lu = [array_map(static fn ($k) => $lignes[$k], $cles), null];
}

/** Une agence, telle que les écrans la lisent. */
function agenceLigne(array $a): array
{
    return [
        'id' => (int) $a['id'],
        'nom' => (string) $a['name'],
        'email' => mktBriefAdresse($a['email'] ?? '') ?: mktBriefAdresse($a['site'] ?? ''),
        'site' => (string) ($a['site'] ?? ''),
        'logo' => (string) ($a['logo_uri'] ?? ''),
        'defaut' => (bool) ($a['is_default'] ?? false),
        'campagnes' => (int) ($a['channel_campaigns'] ?? $a['campagnes'] ?? 0),
    ];
}

/** @return list<array<string,mixed>> Vide si le module ne répond pas (la note retombe alors sur le réglage). */
function agenceListe(bool $frais = false): array
{
    [$lignes] = agencesModule($frais);
    return array_map('agenceLigne', $lignes ?? []);
}

/** L'agence qui signe : celle du canal le mieux doté de la campagne, sinon celle par défaut. */
function agenceDeCampagne(int $campagne): ?array
{
    [$lignes] = agencesModule();
    if ($lignes === null) { return null; }   // module muet : le réglage fera foi
    $parId = [];
    foreach ($lignes as $a) { $parId[(int) $a['id']] = $a; }

    $f = function_exists('campagneFiche') ? campagneFiche($campagne) : ['fiche' => null];
    $choisie = null; $budget = null;
    foreach ((array) ($f['fiche']['channels'] ?? []) as $c) {
        $id = is_array($c) && isset($c['agency_id']) ? (int) $c['agency_id'] : 0;
        if ($id <= 0 || !isset($parId[$id])) { continue; }
        $b = (float) ($c['budget_amount'] ?? 0);
        if ($budget === null || $b > $budget) { $choisie = $id; $budget = $b; }
    }
    if ($choisie !== null) { return agenceLigne(['channel_campaigns' => 0] + $parId[$choisie]); }
    foreach ($lignes as $a) {
        if (!empty($a['is_default'])) { return agenceLigne(['channel_campaigns' => 0] + $a); }
    }
    return null;
}

/** GET /marketing/agences. */
function ep_mkt_agences(): array
{
    [$lignes, $err] = agencesModule();
    if ($lignes === null) { http_response_code(502); return ['error' => 'le module Marketing ne rend pas les agences : ' . $err]; }
    return ['agences' => array_map('agenceLigne', $lignes)];
}

/** Ce que le module a répondu à une écriture, en réponse de l'écran (404, 409 et 422 gardent leur code). */
function agenceRefus(array $r): array
{
    $code = in_array($r['code'], [404, 409, 422], true) ? $r['code'] : 502;
    http_response_code($code);
    if ($code === 404) { return ['error' => 'agence inconnue']; }
    return ['error' => $code === 502 ? 'le référentiel des agences est indisponible : ' . $r['erreur'] : (string) $r['erreur']];
}

/** POST /marketing/agence — ou PATCH /marketing/agence/{id}. */
function wr_mkt_agence(?int $id): array
{
    $b = body();

    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 160);
    if ($id === null && $nom === '') {
        http_response_code(422); return ['error' => 'Le nom de l’agence est obligatoire.'];
    }

    $logo = (string) ($b['logo'] ?? '');
    // Un logo est une IMAGE embarquée : une URL ferait un cadre vide chez le
    // destinataire et rien du tout à l'impression.
    if ($logo !== '' && !preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,#', $logo)) { $logo = ''; }
    if (strlen($logo) > 700000) { $logo = ''; }

    // Ce que l'écran envoie, et cela seul : une modification ne touche que les champs reçus (un nom vide garde
    // l'ancien), une adresse qui n'en est pas une est vidée, comme avant.
    $champs = [];
    if ($nom !== '') { $champs['name'] = $nom; }
    if ($id === null || array_key_exists('email', $b)) { $champs['email'] = mktBriefAdresse($b['email'] ?? ''); }
    if ($id === null || array_key_exists('site', $b)) { $champs['site'] = mb_substr(trim((string) ($b['site'] ?? '')), 0, 190); }
    if ($id === null || array_key_exists('logo', $b)) { $champs['logoUri'] = $logo; }
    if ($id === null || array_key_exists('defaut', $b)) { $champs['isDefault'] = !empty($b['defaut']); }

    $r = $id === null ? marketingAppel('POST', '/agencies', $champs) : marketingAppel('PATCH', '/agencies/' . $id, $champs);
    if ($r['erreur'] !== null) { return agenceRefus($r); }
    if ($id === null) { journalAdd('CEO', 'Paramètre', $nom, 'Agence ajoutée'); }

    return ['ok' => true, 'agences' => agenceListe(true)];
}

/** DELETE /marketing/agence/{id}. */
function wr_mkt_agence_suppr(int $id): array
{
    $nom = null;
    foreach (agenceListe() as $a) { if ($a['id'] === $id) { $nom = $a['nom']; } }
    // Une agence qui porte des canaux (ou des interventions) ne s'efface pas : le module répond 409 et dit pourquoi
    // — les campagnes passées perdraient qui les a faites.
    $r = marketingAppel('DELETE', '/agencies/' . $id);
    if ($r['erreur'] !== null) { return agenceRefus($r); }
    journalAdd('CEO', 'Paramètre', (string) ($nom ?? ('agence ' . $id)), 'Agence retirée');
    return ['ok' => true, 'agences' => agenceListe(true)];
}
