<?php
declare(strict_types=1);

/**
 * Le formulaire Meta des bureaux (portail Commercial, 19/11/2026, demande : « crée le formulaire Meta lunch au bureau »).
 *
 * La Page « L'Atelier by » n'avait que des formulaires de recrutement (franchisés, commercial en flexi-job) : aucun lead
 * de bureau ne pouvait arriver. Ce module crée le formulaire de leads « Lunch au bureau » sur la Page, en français ou en
 * néerlandais, par l'API Graph (`POST /{page}/leadgen_forms`), avec le jeton de la Page que MétaDonnée tient déjà
 * (mdMetaCfg, clés du CRM dans erp_cles). Ses questions sont celles que MétaDonnée range sans réglage : entreprise,
 * nombre de personnes, besoin (les codes de MD_BESOINS), nom, fonction, e-mail, téléphone, code postal du bureau — le
 * code postal donne le magasin. La politique de confidentialité et la langue sont reprises des formulaires existants.
 * Créé, le formulaire est relu par MétaDonnée ; l'écran le fait suivre (magasin par code postal) par la route de
 * MétaDonnée. Aucune lecture ni écriture dans les tables de MétaDonnée : ses fonctions seulement.
 */

/** La définition du formulaire, sans appel : nom, questions, carte d'accueil, page de remerciement. */
function smFormulaireLunch(string $langue, string $site): array
{
    $nl = $langue === 'nl';
    $q = static fn (string $cle, string $label, array $options) => ['type' => 'CUSTOM', 'key' => $cle, 'label' => $label,
        'options' => array_map(static fn ($v, $k) => ['value' => $v, 'key' => $cle . '_' . $k], $options, array_keys($options))];
    return [
        'name' => $nl ? 'LUNCH OP KANTOOR - NL' : 'LUNCH AU BUREAU - FR',
        'questions' => [
            ['type' => 'COMPANY_NAME'],
            $q('nombre_de_personnes', $nl ? 'Met hoeveel personen zijn jullie op kantoor?' : 'Combien de personnes dans votre équipe ?', ['1 à 9', '10 à 24', '25 à 49', '50 et plus']),
            $q('votre_besoin', $nl ? 'Wat is jullie behoefte?' : 'Quel est votre besoin ?',
                $nl ? ['Lunch elke dag', 'Schotels voor vergaderingen', 'Ontbijt voor het team', 'Evenement of receptie']
                    : ['Lunch tous les jours', 'Plateaux réunion', 'Petit-déjeuner d’équipe', 'Événement']),
            ['type' => 'FULL_NAME'],
            ['type' => 'JOB_TITLE'],
            ['type' => 'EMAIL'],
            ['type' => 'PHONE'],
            ['type' => 'POST_CODE'],
        ],
        'context_card' => ['title' => $nl ? 'De lunch, geleverd op kantoor' : 'Le lunch livré au bureau', 'style' => 'PARAGRAPH_STYLE',
            'content' => [$nl ? 'Broodjesschotels, koffiekoeken en taarten, geleverd door de L’Atelier By-winkel van jullie regio. Bestellen tot 16 u de dag voordien.'
                : 'Plateaux de sandwiches, viennoiseries et tartes, livrés par le magasin L’Atelier By de votre zone. Commande jusqu’à la veille 16 h.']],
        'thank_you_page' => ['title' => $nl ? 'Bedankt! De winkel van jullie regio belt jullie terug.' : 'Merci ! Le magasin de votre zone vous rappelle.',
            'body' => $nl ? 'We nemen binnen 24 uur contact op om een proeverij en jullie levering op kantoor te regelen.'
                : 'Nous vous contactons sous 24 h pour organiser une dégustation et votre livraison au bureau.',
            'button_type' => 'VIEW_WEBSITE', 'button_text' => $nl ? 'Onze website' : 'Notre site', 'website_url' => $site],
    ];
}

/** Les formulaires existants de la Page : la politique de confidentialité, le site et la langue (fr, nl) qu'ils portent. */
function smFormulaireReprise(array $c): array
{
    $out = ['politique' => '', 'site' => '', 'locale' => ['fr' => '', 'nl' => '']];
    foreach (array_slice((array) (ep_md()['formulaires'] ?? []), 0, 20) as $f) {
        $l = strtolower((string) ($f['locale'] ?? ''));
        if (str_starts_with($l, 'fr') && $out['locale']['fr'] === '') { $out['locale']['fr'] = (string) $f['locale']; }
        if (str_starts_with($l, 'nl') && $out['locale']['nl'] === '') { $out['locale']['nl'] = (string) $f['locale']; }
        if ($out['politique'] === '') {
            $r = MdMeta::graph('GET', (string) $f['id'], ['fields' => 'privacy_policy_url,follow_up_action_url'], $c['jeton']);
            if ($r['ok']) {
                $out['politique'] = (string) ($r['data']['privacy_policy_url'] ?? '');
                $out['site'] = (string) ($r['data']['follow_up_action_url'] ?? '');
            }
        }
    }
    return $out;
}

/** GET /commercial/formulaires/modele?langue=fr — ce qui serait créé, sans rien créer. */
function ep_sm_formulaire_modele(): array
{
    $c = mdMetaCfg();
    if (!mdMetaConfigure($c)) { http_response_code(503); return ['error' => 'connexion Meta à régler dans MétaDonnée']; }
    $langue = ($_GET['langue'] ?? '') === 'nl' ? 'nl' : 'fr';
    $rep = smFormulaireReprise($c);
    $site = $rep['site'] !== '' ? $rep['site'] : 'https://www.atelierby.be';
    return ['page' => $c['pageId'], 'langue' => $langue, 'formulaire' => smFormulaireLunch($langue, $site), 'politique' => $rep['politique'],
        'locale' => $rep['locale'][$langue] !== '' ? $rep['locale'][$langue] : ($langue === 'nl' ? 'nl_NL' : 'fr_FR'), 'site' => $site];
}

/**
 * POST /commercial/formulaires/creer — { langue, politique?, site? } : le formulaire créé sur la Page et relu par
 * MétaDonnée. Un formulaire du même nom déjà sur la Page n'est pas recréé.
 */
function wr_sm_formulaire_creer(): array
{
    $c = mdMetaCfg();
    if (!mdMetaConfigure($c)) { http_response_code(503); return ['error' => 'connexion Meta à régler dans MétaDonnée']; }
    $b = body();
    $langue = ($b['langue'] ?? '') === 'nl' ? 'nl' : 'fr';
    $rep = smFormulaireReprise($c);
    $politique = trim((string) ($b['politique'] ?? '')) !== '' ? trim((string) $b['politique']) : $rep['politique'];
    $site = trim((string) ($b['site'] ?? '')) !== '' ? trim((string) $b['site']) : ($rep['site'] !== '' ? $rep['site'] : 'https://www.atelierby.be');
    if (!preg_match('#^https://#', $politique)) { http_response_code(422); return ['error' => 'adresse de la politique de confidentialité introuvable sur les formulaires existants : donnez-la (politique)']; }
    $f = smFormulaireLunch($langue, $site);
    foreach ((array) (ep_md()['formulaires'] ?? []) as $x) {
        if ((string) ($x['nom'] ?? '') === $f['name']) { return ['ok' => true, 'id' => (string) $x['id'], 'nom' => $f['name'], 'deja' => true]; }
    }
    $params = ['name' => $f['name'], 'locale' => $rep['locale'][$langue] !== '' ? $rep['locale'][$langue] : ($langue === 'nl' ? 'nl_NL' : 'fr_FR'),
        'questions' => json_encode($f['questions'], JSON_UNESCAPED_UNICODE),
        'privacy_policy' => json_encode(['url' => $politique, 'link_text' => $langue === 'nl' ? 'Privacybeleid' : 'Politique de confidentialité'], JSON_UNESCAPED_UNICODE),
        'follow_up_action_url' => $site,
        'context_card' => json_encode($f['context_card'], JSON_UNESCAPED_UNICODE),
        'thank_you_page' => json_encode($f['thank_you_page'], JSON_UNESCAPED_UNICODE),
        'block_display_for_non_targeted_viewer' => 'true'];
    $r = MdMeta::graph('POST', $c['pageId'] . '/leadgen_forms', $params, $c['jeton']);
    if (!$r['ok']) { http_response_code(502); return ['error' => 'Meta refuse la création : ' . (string) $r['error']]; }
    $id = (string) ($r['data']['id'] ?? '');
    // MétaDonnée relit les formulaires de la Page ; l'écran le fait ensuite suivre (PUT /commercial/formulaires/{id}).
    try { wr_md_formulaires_relire(); } catch (Throwable $e) { /* relu au prochain cycle */ }
    http_response_code(200);
    journalAdd('Commercial', 'Commercial', null, 'Formulaire Meta créé : ' . $f['name']);
    return ['ok' => true, 'id' => $id, 'nom' => $f['name']];
}

/** Les noms des formulaires que ce module crée : les seuls que le portail met en inactif ou réactive. */
function smFormulairesLunchNoms(): array
{
    return [smFormulaireLunch('fr', '')['name'], smFormulaireLunch('nl', '')['name']];
}

/**
 * POST /commercial/formulaires/{id}/actif — { actif: bool } : un formulaire « Lunch au bureau » archivé chez Meta
 * (inactif : plus aucune publicité ne peut s'en servir) ou réactivé. Les formulaires de recrutement et les autres ne se
 * touchent pas d'ici. Le suivi (MétaDonnée) se coupe à part, par PUT /commercial/formulaires/{id}.
 */
function wr_sm_formulaire_actif(string $id): array
{
    $c = mdMetaCfg();
    if (!mdMetaConfigure($c)) { http_response_code(503); return ['error' => 'connexion Meta à régler dans MétaDonnée']; }
    $f = null;
    foreach ((array) (ep_md()['formulaires'] ?? []) as $x) { if ((string) ($x['id'] ?? '') === $id) { $f = $x; } }
    if ($f === null) { http_response_code(404); return ['error' => 'formulaire inconnu']; }
    if (!in_array((string) ($f['nom'] ?? ''), smFormulairesLunchNoms(), true)) {
        http_response_code(403); return ['error' => 'seuls les formulaires « Lunch au bureau » se mettent en inactif depuis le portail Commercial'];
    }
    $actif = !empty(body()['actif']);
    $r = MdMeta::graph('POST', $id, ['status' => $actif ? 'ACTIVE' : 'ARCHIVED'], $c['jeton']);
    if (!$r['ok']) { http_response_code(502); return ['error' => 'Meta refuse le changement : ' . (string) $r['error']]; }
    try { wr_md_formulaires_relire(); } catch (Throwable $e) { /* relu au prochain cycle */ }
    http_response_code(200);
    journalAdd('Commercial', 'Commercial', null, 'Formulaire Meta ' . ($actif ? 'réactivé' : 'mis en inactif (archivé chez Meta)') . ' : ' . (string) $f['nom']);
    return ['ok' => true, 'id' => $id, 'nom' => (string) $f['nom'], 'statut' => $actif ? 'ACTIVE' : 'ARCHIVED'];
}

/** Les routes du formulaire Meta ; null pour une autre adresse. */
function smRouteMeta(string $method, string $p): mixed
{
    if ($method === 'GET' && $p === '/formulaires/modele') { return ep_sm_formulaire_modele(); }
    if ($method === 'POST' && $p === '/formulaires/creer') { return wr_sm_formulaire_creer(); }
    if ($method === 'POST' && preg_match('#^/formulaires/(\d+)/actif$#', $p, $m)) { return wr_sm_formulaire_actif($m[1]); }
    return null;
}
