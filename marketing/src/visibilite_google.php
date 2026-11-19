<?php
declare(strict_types=1);

/**
 * Visibilité Google (09/11/2026) — Marque & marketing › Visibilité Google.
 *
 * Deux lectures, avec la clé Places déjà enregistrée pour la réputation (GoogleApi) :
 *  - les FICHES : ce que la fiche Google de chaque magasin affiche (nom, catégorie, horaires,
 *    lien, téléphone, photos, options), comparé à ce que le site du réseau publie dans ses données
 *    structurées (schema.org Bakery) — un écart entre les deux fait perdre des places sur Maps ;
 *  - le RANG LOCAL : la place de la fiche du magasin dans les 20 résultats d'une recherche
 *    (« cougnou Gosselies »…) lancée depuis le magasin. Proche du classement de Google Maps, sans
 *    être celui que voit un client donné.
 *
 * Ce que la clé ne permet pas, et que l'écran dit : modifier une fiche, publier un post, répondre à
 * un avis (API Business Profile, compte propriétaire) ; lire les requêtes du site (Search Console).
 *
 * Données : un relevé par magasin dans `ceo_app_setting.visibiliteGoogle` (pas de table) — le
 * dernier relevé et les rangs du précédent, pour l'évolution. Les fiches raccordées sont celles de
 * la réputation (ep_reputation) ; la ville et la langue des recherches, celles du référentiel du
 * benchmark (src/data/benchmark_cougnou.json, `nous.boutiques`), comme l'adresse du site (`site.url`).
 * Maquettes : docs/maquettes/visibilite-google.
 */

/** Les recherches suivies, par thème : modèle français, modèle néerlandais (magasins `nl`). */
const VISIBILITE_THEMES = [
    ['cle' => 'boulangerie', 'nom' => 'Boulangerie', 'requetes' => [
        ['boulangerie {ville}', 'bakkerij {ville}'],
        ['boulangerie ouverte dimanche {ville}', 'bakkerij zondag open {ville}']]],
    ['cle' => 'sandwiches', 'nom' => 'Sandwiches garnis', 'requetes' => [
        ['sandwich {ville}', 'broodjes {ville}'],
        ['sandwicherie {ville}', 'broodjeszaak {ville}']]],
    ['cle' => 'cougnous', 'nom' => 'Cougnous', 'requetes' => [
        ['cougnou {ville}', 'koekebrood {ville}'],
        ['commander cougnou {ville}', 'kerstbrood {ville}']]],
    ['cle' => 'galettes', 'nom' => 'Galettes des rois', 'requetes' => [
        ['galette des rois {ville}', 'driekoningentaart {ville}']]],
];

/** Rayon de la recherche autour du magasin, en mètres. */
const VISIBILITE_RAYON = 5000;

/** Le relevé enregistré. */
function visibiliteReleve(): array
{
    $r = setting('visibiliteGoogle', []);
    return is_array($r) ? $r + ['magasins' => [], 'site' => null] : ['magasins' => [], 'site' => null];
}

function visibiliteEnregistrer(array $r): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['visibiliteGoogle', json_encode($r, JSON_UNESCAPED_UNICODE)]);
}

/** Les boutiques du référentiel du benchmark (ville courte, langue), par identifiant de magasin. */
function visibiliteBoutiques(): array
{
    $out = [];
    try {
        foreach ((array) ((benchmarkDonnees('cougnou')['nous'] ?? [])['boutiques'] ?? []) as $b) {
            $out[(string) $b['id']] = ['ville' => (string) ($b['ville'] ?? ''), 'nl' => !empty($b['nl'])];
        }
    } catch (Throwable $e) { /* référentiel absent : la ville vient de la table des magasins */ }
    return $out;
}

/** L'adresse du site du réseau : celle du référentiel du benchmark, sinon le réglage `visibiliteSite`. */
function visibiliteSiteUrl(): string
{
    $u = '';
    try { $u = (string) ((benchmarkDonnees('cougnou')['site'] ?? [])['url'] ?? ''); } catch (Throwable $e) { /* absent */ }
    if ($u === '') { $u = (string) setting('visibiliteSite', ''); }
    return rtrim($u, '/');
}

/** Les magasins suivis : ceux de la réputation, avec leur fiche raccordée, leur ville et leur langue. */
function visibiliteMagasins(): array
{
    $rep = ep_reputation();
    $bq = visibiliteBoutiques();
    $out = [];
    foreach ((array) ($rep['magasins'] ?? []) as $m) {
        $id = (string) $m['id'];
        $b = $bq[$id] ?? null;
        $out[] = ['id' => $id, 'nom' => (string) $m['nom'], 'ville' => $b['ville'] ?? (string) ($m['ville'] ?? ''),
            'langue' => ($b['nl'] ?? false) ? 'nl' : 'fr', 'placeId' => (string) ($m['placeId'] ?? '')];
    }
    return $out;
}

/** Sept chaînes (lundi → dimanche) depuis des openingHoursSpecification schema.org. */
function visibiliteHorairesSchema(array $specs): ?array
{
    if ($specs === []) { return null; }
    $noms = ['monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3, 'friday' => 4, 'saturday' => 5, 'sunday' => 6];
    $jours = ['', '', '', '', '', '', ''];
    $hm = static fn ($v) => preg_match('/^(\d{1,2}):(\d{2})/', (string) $v, $m) ? sprintf('%02d:%s', (int) $m[1], $m[2]) : null;
    foreach ($specs as $s) {
        if (!is_array($s)) { continue; }
        $o = $hm($s['opens'] ?? ''); $f = $hm($s['closes'] ?? '');
        if ($o === null || $f === null) { continue; }
        foreach ((array) ($s['dayOfWeek'] ?? []) as $d) {
            $k = strtolower((string) preg_replace('#^.*/#', '', (string) $d));
            if (!isset($noms[$k])) { continue; }
            $j = $noms[$k];
            $jours[$j] = $jours[$j] === '' ? $o . '–' . $f : $jours[$j] . ', ' . $o . '–' . $f;
        }
    }
    return implode('', $jours) === '' ? null : $jours;
}

/**
 * Les boutiques que le site publie dans ses données structurées (schema.org Bakery, Store,
 * LocalBusiness) : nom, ville, téléphone, lien, horaires. Fonction pure sur le HTML.
 */
function visibiliteSiteBoutiques(string $html): array
{
    $out = [];
    if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) { return $out; }
    foreach ($m[1] as $bloc) {
        $d = json_decode(html_entity_decode(trim($bloc), ENT_QUOTES, 'UTF-8'), true);
        if (!is_array($d)) { continue; }
        $noeuds = isset($d['@graph']) ? (array) $d['@graph'] : (array_is_list($d) ? $d : [$d]);
        foreach ($noeuds as $n) {
            if (!is_array($n)) { continue; }
            $types = (array) ($n['@type'] ?? []);
            if (!array_intersect($types, ['Bakery', 'Store', 'LocalBusiness', 'FoodEstablishment', 'CafeOrCoffeeShop'])) { continue; }
            $a = (array) ($n['address'] ?? []);
            $out[] = ['nom' => (string) ($n['name'] ?? ''), 'ville' => (string) ($a['addressLocality'] ?? ''),
                'codePostal' => (string) ($a['postalCode'] ?? ''), 'rue' => (string) ($a['streetAddress'] ?? ''),
                'telephone' => (string) ($n['telephone'] ?? ''), 'lien' => (string) ($n['url'] ?? ''),
                'horaires' => visibiliteHorairesSchema((array) ($n['openingHoursSpecification'] ?? []))];
        }
    }
    return $out;
}

/** Le site relu (au plus une fois toutes les 10 minutes pendant une relecture). */
function visibiliteLireSite(array $avant): array
{
    if ($avant && ($avant['lu_le'] ?? '') !== '' && strtotime((string) $avant['lu_le']) > time() - 600 && empty($avant['erreur'])) { return $avant; }
    $url = visibiliteSiteUrl();
    if ($url === '') { return ['url' => '', 'lu_le' => date('Y-m-d H:i'), 'boutiques' => [], 'erreur' => 'Adresse du site inconnue.']; }
    [$code, $html] = benchmarkLire($url . '/');
    if ($code !== 200) { return ['url' => $url, 'lu_le' => date('Y-m-d H:i'), 'boutiques' => [], 'erreur' => 'Le site répond HTTP ' . $code . '.']; }
    return ['url' => $url, 'lu_le' => date('Y-m-d H:i'), 'boutiques' => visibiliteSiteBoutiques($html), 'erreur' => null];
}

/** Rapproche une boutique du site d'un magasin : même ville, sinon même téléphone. */
function visibiliteBoutiqueSite(array $boutiques, string $ville, string $tel): ?array
{
    $norm = static fn (string $s) => strtolower(trim((string) preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s)));
    foreach ($boutiques as $b) { if ($ville !== '' && $norm($b['ville']) === $norm($ville)) { return $b; } }
    foreach ($boutiques as $b) { if ($tel !== '' && visibiliteChiffres($b['telephone']) === visibiliteChiffres($tel)) { return $b; } }
    return null;
}

/** Un numéro belge ramené à ses chiffres nationaux (+32 2… → 02…). */
function visibiliteChiffres(string $t): string
{
    $c = (string) preg_replace('/\D/', '', $t);
    if (str_starts_with($c, '0032')) { $c = '0' . substr($c, 4); } elseif (str_starts_with($c, '32') && strlen($c) >= 10) { $c = '0' . substr($c, 2); }
    return $c;
}

/** Relit un magasin : sa fiche, puis ses recherches. Rend le relevé du magasin. */
function visibiliteRelireMagasin(array $m, array $avant): array
{
    $releve = ['lu_le' => date('Y-m-d H:i'), 'fiche' => null, 'erreurFiche' => null, 'rangs' => [],
        'precedent' => null, 'appels' => 0];
    // L'évolution : les rangs du relevé d'avant, une seule génération.
    if (!empty($avant['rangs'])) {
        $releve['precedent'] = ['lu_le' => $avant['lu_le'] ?? null,
            'rangs' => array_column(array_map(fn ($r) => ['k' => $r['modele'], 'v' => $r['rang']], $avant['rangs']), 'v', 'k')];
    }
    $pos = null;
    if ($m['placeId'] !== '') {
        GoogleApi::$lastError = null;
        $f = GoogleApi::ficheVisibilite($m['placeId'], $m['nom'] . ' ' . $m['ville']);
        $releve['appels']++;
        if ($f === null) { $releve['erreurFiche'] = GoogleApi::$lastError ?? 'réponse vide'; }
        else { $releve['fiche'] = $f; $pos = $f['position']; }
    }
    foreach (VISIBILITE_THEMES as $t) {
        foreach ($t['requetes'] as [$fr, $nl]) {
            $modele = $m['langue'] === 'nl' ? $nl : $fr;
            $q = str_replace('{ville}', $m['ville'], $modele);
            GoogleApi::$lastError = null;
            $res = GoogleApi::rechercheLocale($q, $pos, $m['langue'], VISIBILITE_RAYON);
            $releve['appels']++;
            if ($res === null) { $releve['rangs'][] = ['theme' => $t['cle'], 'modele' => $fr, 'requete' => $q, 'rang' => null, 'total' => 0, 'top' => [], 'erreur' => GoogleApi::$lastError]; continue; }
            $rang = 0;
            foreach ($res as $k => $p) {
                $nous = $m['placeId'] !== '' ? $p['placeId'] === $m['placeId'] : stripos((string) $p['nom'], 'atelier') !== false;
                if ($nous) { $rang = $k + 1; break; }
            }
            // Les dix premiers, et la fiche du magasin si elle est plus loin.
            $top = [];
            foreach ($res as $k => $p) {
                if ($k < 10 || $k + 1 === $rang) { $top[] = $p + ['rang' => $k + 1, 'nous' => $k + 1 === $rang]; }
            }
            $releve['rangs'][] = ['theme' => $t['cle'], 'modele' => $fr, 'requete' => $q, 'rang' => $rang, 'total' => count($res), 'top' => $top, 'erreur' => null];
        }
    }
    return $releve;
}

/** POST /visibilite/sync {magasinId?} — relit Google pour un magasin, ou tous. */
function wr_visibilite_sync(): array
{
    if (!GoogleApi::configured()) {
        http_response_code(422);
        return ['error' => 'Clé Google absente — renseignez-la dans Paramètres.'];
    }
    @set_time_limit(180);
    $un = trim((string) (body()['magasinId'] ?? ''));
    $r = visibiliteReleve();
    $r['site'] = visibiliteLireSite((array) ($r['site'] ?? []));
    $faits = 0; $appels = 0; $erreurs = [];
    foreach (visibiliteMagasins() as $m) {
        if ($un !== '' && $m['id'] !== $un) { continue; }
        $rel = visibiliteRelireMagasin($m, (array) ($r['magasins'][$m['id']] ?? []));
        $r['magasins'][$m['id']] = $rel;
        $appels += $rel['appels'];
        if ($rel['erreurFiche']) { $erreurs[] = $m['nom'] . ' : ' . $rel['erreurFiche']; }
        foreach ($rel['rangs'] as $x) { if (!empty($x['erreur'])) { $erreurs[] = $m['nom'] . ' · ' . $x['requete'] . ' : ' . $x['erreur']; break; } }
        $faits++;
    }
    visibiliteEnregistrer($r);
    journalAdd('CEO', 'Visibilité Google', $un !== '' ? $un : '—', 'Relecture Google : ' . $faits . ' magasin(s), ' . $appels . ' appel(s)'
        . ($erreurs !== [] ? ' — ' . count($erreurs) . ' erreur(s)' : ''));
    return ['ok' => true, 'magasins' => $faits, 'appels' => $appels, 'erreurs' => $erreurs];
}

/**
 * Les écarts d'un magasin entre sa fiche Google et le site, du plus grave au moins grave.
 * Fonction pure (testée) : [ ['gravite' => 1..4, 'quoi' => …, 'detail' => …, 'ou' => 'google'|'site'] ].
 */
function visibiliteEcarts(array $m, ?array $fiche, ?string $erreurFiche, ?array $site, bool $siteLu): array
{
    $e = [];
    $add = static function (int $g, string $quoi, string $detail, string $ou) use (&$e) { $e[] = ['gravite' => $g, 'quoi' => $quoi, 'detail' => $detail, 'ou' => $ou]; };
    if ($m['placeId'] === '') { $add(1, 'Fiche non raccordée', 'Raccordez la fiche Google du magasin dans Note Google et avis.', 'cockpit'); return $e; }
    if ($fiche === null) { $add(1, 'Fiche illisible', (string) ($erreurFiche ?: 'Google n’a pas rendu la fiche.'), 'google'); return $e; }
    if ($fiche['statut'] !== '' && $fiche['statut'] !== 'OPERATIONAL') { $add(1, 'Fiche fermée', 'Google affiche le magasin comme fermé (' . $fiche['statut'] . ').', 'google'); }
    $gH = $fiche['horaires']; $sH = $site['horaires'] ?? null;
    if ($gH === null) { $add(1, 'Horaires absents de Google', 'La fiche n’a pas d’horaires habituels.', 'google'); }
    if ($siteLu && $site !== null && $sH === null) { $add(3, 'Horaires absents du site', 'Le site ne publie aucun horaire pour cette boutique.', 'site'); }
    if ($gH !== null && $sH !== null && $gH !== $sH) {
        $jours = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        $diff = [];
        foreach ($gH as $i => $v) { if ($v !== $sH[$i]) { $diff[] = $jours[$i] . ' : Google ' . ($v ?: 'fermé') . ', site ' . ($sH[$i] ?: 'fermé'); } }
        $add(1, 'Le site contredit Google sur les horaires', implode(' · ', array_slice($diff, 0, 3)) . (count($diff) > 3 ? ' · …' : ''), 'site');
    }
    if ($fiche['type'] !== '' && $fiche['type'] !== 'bakery') {
        $add(2, 'Catégorie principale', '« ' . ($fiche['typeLibelle'] ?: $fiche['type']) . ' » : « Boulangerie » attendue.', 'google');
    }
    if ($fiche['site'] === '') { $add(2, 'Aucun lien vers le site', 'La fiche ne renvoie ni au site ni à la commande.', 'google'); }
    if ($site !== null && $site['telephone'] !== '' && $fiche['telephone'] !== '' && visibiliteChiffres($site['telephone']) !== visibiliteChiffres($fiche['telephone'])) {
        $add(2, 'Téléphone différent', 'Google ' . $fiche['telephone'] . ', site ' . $site['telephone'] . '.', 'google');
    }
    if ($fiche['nom'] !== null && stripos((string) $fiche['nom'], 'atelier') === false) {
        $add(3, 'Nom sans l’enseigne', 'Google affiche « ' . $fiche['nom'] . ' ».', 'google');
    }
    if ($fiche['photos'] < 10) { $add(4, 'Peu de photos', $fiche['photos'] . ' photo(s) sur la fiche.', 'google'); }
    usort($e, fn ($a, $b) => $a['gravite'] <=> $b['gravite']);
    return $e;
}

/** La nature du lien de la fiche : aucun, accueil, webshop, page. */
function visibiliteLien(string $lien): string
{
    if ($lien === '') { return 'aucun'; }
    if (stripos($lien, 'webshop.') !== false) { return 'webshop'; }
    $p = (string) (parse_url($lien, PHP_URL_PATH) ?? '');
    return in_array(rtrim($p, '/'), ['', '/nl', '/fr'], true) ? 'accueil' : 'page';
}

/** GET /visibilite — le relevé, les écarts calculés, l'état du connecteur. */
function ep_visibilite(): array
{
    $r = visibiliteReleve();
    $site = (array) ($r['site'] ?? []);
    $siteLu = ($site['lu_le'] ?? '') !== '' && empty($site['erreur']);
    $magasins = []; $ecarts = 0; $dernier = null;
    try { $liste = visibiliteMagasins(); }
    catch (Throwable $e) { return ['indispo' => true, 'raison' => 'Les magasins et leurs fiches sont illisibles : ' . $e->getMessage()]; }
    foreach ($liste as $m) {
        $rel = $r['magasins'][$m['id']] ?? null;
        $fiche = $rel['fiche'] ?? null;
        $b = $siteLu ? visibiliteBoutiqueSite((array) ($site['boutiques'] ?? []), $m['ville'], (string) ($fiche['telephone'] ?? '')) : null;
        $ec = $rel !== null || $m['placeId'] === '' ? visibiliteEcarts($m, $fiche, $rel['erreurFiche'] ?? null, $b, $siteLu) : [];
        $ecarts += count($ec);
        if ($rel && ($dernier === null || $rel['lu_le'] > $dernier)) { $dernier = $rel['lu_le']; }
        $magasins[] = $m + ['lu_le' => $rel['lu_le'] ?? null, 'fiche' => $fiche, 'erreurFiche' => $rel['erreurFiche'] ?? null,
            'lien' => $fiche ? visibiliteLien((string) $fiche['site']) : null,
            'site' => $b, 'ecarts' => $ec, 'rangs' => $rel['rangs'] ?? [], 'precedent' => $rel['precedent'] ?? null];
    }
    return [
        'connecteur' => GoogleApi::statut(),
        'lu_le' => $dernier,
        'site' => ['url' => $site['url'] ?? visibiliteSiteUrl(), 'lu_le' => $site['lu_le'] ?? null, 'erreur' => $site['erreur'] ?? null,
            'boutiques' => count((array) ($site['boutiques'] ?? []))],
        'themes' => array_map(fn ($t) => ['cle' => $t['cle'], 'nom' => $t['nom'], 'requetes' => array_map(fn ($q) => ['fr' => $q[0], 'nl' => $q[1]], $t['requetes'])], VISIBILITE_THEMES),
        'rayon' => VISIBILITE_RAYON,
        'ecarts' => $ecarts,
        'magasins' => $magasins,
    ];
}
