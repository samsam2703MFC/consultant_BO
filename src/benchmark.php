<?php
declare(strict_types=1);

/**
 * Benchmark concurrents — client du module Benchmark de l'API TFBuddy, vérification
 * de bout en bout, benchmark saisonnier du cougnou, rapport et routine.
 *
 * L'API (spec OpenAPI : https://atelierby.tfbuddy.com/api/documentation/api.php,
 * tag « Benchmark », 18 opérations) tient trois niveaux :
 *
 *   concurrent (par magasin)  →  observation (un relevé daté)  →  items (un produit vu, son prix)
 *
 * et deux lectures d'analyse : `analysis/my-products` (KPI + agrégat par produit à nous)
 * et `analysis/my-products/competitors` (dernier prix vu par produit et concurrent),
 * par magasin ou pour tout le réseau.
 *
 * Authentification : le jeton CONSULTANT du panel (PanelApi), le même qui lit déjà
 * `/shops/{id}/products/waste` — rien de neuf à configurer. Les identifiants ne
 * quittent jamais le serveur.
 *
 * Ce que la spec dit et que ce module respecte :
 *  - `date_from` / `date_to` partent tels quels dans le SQL de l'API : on n'envoie
 *    JAMAIS autre chose qu'une date Y-m-d validée ici ;
 *  - une lecture « introuvable » rend `[]` (pas 404) : on la lit comme null ;
 *  - un PATCH rend 204 avec un corps qu'il ne faut pas lire : on relit la ressource ;
 *  - `inserted_id` peut valoir 0 ou 1 quand le dépôt rend un booléen : on relit la
 *    liste pour retrouver l'identifiant ;
 *  - le module ne vérifie pas que `shopId` appartient au jeton, ni qu'une observation
 *    appartient au magasin : la vérification en écriture le mesure et le signale.
 */

final class BenchmarkApi
{
    /** Les magasins du réseau (identifiants du panel) — réglage `benchmark.magasins` sinon. */
    public const MAGASINS = [2 => 'Corbais', 3 => 'Gosselies', 4 => 'Halle', 5 => 'Sombreffe'];

    public static ?string $lastError = null;

    /** @return array<int,string> */
    public static function magasins(): array
    {
        $s = setting('benchmark', []);
        $m = is_array($s) && is_array($s['magasins'] ?? null) ? $s['magasins'] : [];
        $out = [];
        foreach ($m as $id => $nom) { if ((int) $id > 0) { $out[(int) $id] = (string) $nom; } }
        return $out ?: self::MAGASINS;
    }

    public static function disponible(): bool
    {
        return PanelApi::configured();
    }

    /** Un appel, tel quel : [ok, code, corps, ms, erreur]. */
    public static function appel(string $methode, string $path, ?array $body = null): array
    {
        $r = PanelApi::sonde($methode, $path, $body);
        $r['ok'] = $r['code'] >= 200 && $r['code'] < 300;
        if (!$r['ok']) { self::$lastError = $methode . ' ' . $path . ' → ' . ($r['erreur'] ?? 'échec'); }
        return $r;
    }

    /** Une date sûre pour le SQL de l'API, ou null. */
    public static function date(?string $d): ?string
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return null; }
        [$y, $m, $j] = array_map('intval', explode('-', $d));
        return checkdate($m, $j, $y) ? $d : null;
    }

    private static function q(?string $du, ?string $au): string
    {
        $q = array_filter(['date_from' => self::date($du), 'date_to' => self::date($au)]);
        return $q ? '?' . http_build_query($q) : '';
    }

    /** `[]` (rien trouvé) ou pas un objet ⇒ null. */
    private static function objet(array $r): ?array
    {
        $c = $r['corps'] ?? null;
        if (!$r['ok'] || !is_array($c) || $c === [] || array_is_list($c)) { return null; }
        return $c;
    }

    private static function liste(array $r): ?array
    {
        $c = $r['corps'] ?? null;
        if (!$r['ok'] || !is_array($c)) { return null; }
        if (isset($c['data']) && is_array($c['data'])) { $c = $c['data']; }
        return array_is_list($c) ? $c : null;
    }

    private static function cree(array $r): ?int
    {
        if (!$r['ok'] || !is_array($r['corps'])) { return null; }
        return (int) ($r['corps']['inserted_id'] ?? 0);
    }

    // --- concurrents --------------------------------------------------------------
    public static function concurrents(int $shop, bool $avecObservations = false): ?array
    {
        return self::liste(self::appel('GET', "/shops/$shop/benchmarks/competitors" . ($avecObservations ? '?include=observations' : '')));
    }
    public static function concurrent(int $shop, int $id): ?array
    {
        return self::objet(self::appel('GET', "/shops/$shop/benchmarks/competitors/$id"));
    }
    public static function creerConcurrent(int $shop, array $c): ?int
    {
        return self::cree(self::appel('POST', "/shops/$shop/benchmarks/competitors", $c));
    }
    public static function modifierConcurrent(int $shop, int $id, array $c): bool
    {
        return self::appel('PATCH', "/shops/$shop/benchmarks/competitors/$id", $c)['ok'];
    }
    public static function supprimerConcurrent(int $shop, int $id): bool
    {
        return self::appel('DELETE', "/shops/$shop/benchmarks/competitors/$id")['ok'];
    }

    // --- observations -------------------------------------------------------------
    public static function observations(int $shop, int $comp, bool $avecItems = false): ?array
    {
        return self::liste(self::appel('GET', "/shops/$shop/benchmarks/competitors/$comp/observations" . ($avecItems ? '?include=items' : '')));
    }
    public static function creerObservation(int $shop, int $comp, array $o): ?int
    {
        return self::cree(self::appel('POST', "/shops/$shop/benchmarks/competitors/$comp/observations", $o));
    }
    public static function observation(int $shop, int $id, bool $avecItems = false): ?array
    {
        $o = self::objet(self::appel('GET', "/shops/$shop/benchmarks/observations/$id" . ($avecItems ? '?include=items' : '')));
        // Avec include=items, une observation absente revient comme {"items": []}.
        return $o !== null && isset($o['id']) ? $o : null;
    }
    public static function supprimerObservation(int $shop, int $id): bool
    {
        return self::appel('DELETE', "/shops/$shop/benchmarks/observations/$id")['ok'];
    }

    // --- items --------------------------------------------------------------------
    public static function items(int $shop, int $obs): ?array
    {
        return self::liste(self::appel('GET', "/shops/$shop/benchmarks/observations/$obs/items"));
    }
    public static function creerItem(int $shop, int $comp, int $obs, array $i): ?int
    {
        return self::cree(self::appel('POST', "/shops/$shop/benchmarks/competitors/$comp/observations/$obs/items", $i));
    }

    // --- analyses -----------------------------------------------------------------
    public static function analyse(int $shop, ?string $du = null, ?string $au = null): ?array
    {
        $r = self::appel('GET', "/shops/$shop/benchmarks/analysis/my-products" . self::q($du, $au));
        $c = $r['corps'] ?? null;
        return $r['ok'] && is_array($c) && isset($c['kpi']) ? $c : null;
    }
    public static function detail(int $shop, ?string $du = null, ?string $au = null): ?array
    {
        $r = self::appel('GET', "/shops/$shop/benchmarks/analysis/my-products/competitors" . self::q($du, $au));
        $c = $r['corps'] ?? null;
        return $r['ok'] && is_array($c) && isset($c['breakdown']) ? $c['breakdown'] : null;
    }
    public static function detailReseau(?string $du = null, ?string $au = null): ?array
    {
        $r = self::appel('GET', '/benchmarks/analysis/my-products/competitors' . self::q($du, $au));
        $c = $r['corps'] ?? null;
        return $r['ok'] && is_array($c) && isset($c['breakdown']) ? $c['breakdown'] : null;
    }
}

/* =================================================================================
   1. VÉRIFICATION — les 18 routes, en lecture, et en écriture sur un concurrent témoin
   ================================================================================= */

/**
 * Passe les routes Benchmark en revue et dit, route par route, si elles répondent
 * la forme attendue. En lecture seule, rien ne change chez TFBuddy. Avec `$ecrire`,
 * un concurrent témoin « ZZ Test cockpit » est créé, relu, modifié, doté d'une
 * observation et d'un item, puis tout est supprimé — et le cloisonnement entre
 * magasins est mesuré au passage.
 *
 * @return array{ok:bool, magasin:int, ecrire:bool, etapes:list<array>, alertes:list<string>, resume:string}
 */
function benchmarkVerifier(int $shop = 4, bool $ecrire = false): array
{
    $etapes = []; $alertes = [];
    $du = date('Y-m-d', strtotime('-90 days')); $au = date('Y-m-d');
    $pas = static function (string $etape, string $m, string $route, array $r, bool $attendu, string $note = '') use (&$etapes): bool {
        $ok = $r['ok'] && $attendu;
        $etapes[] = ['etape' => $etape, 'methode' => $m, 'route' => $route, 'ok' => $ok, 'code' => $r['code'],
            'ms' => $r['ms'] ?? 0, 'note' => $ok ? $note : ($r['erreur'] ?? 'réponse inattendue') . ($note !== '' ? ' — ' . $note : '')];
        return $ok;
    };
    $estListe = static fn ($c) => is_array($c) && array_is_list($c);

    if (!BenchmarkApi::disponible()) {
        return ['ok' => false, 'magasin' => $shop, 'ecrire' => $ecrire, 'etapes' => [], 'alertes' => [],
            'resume' => 'Compte consultant du panel non configuré (Paramètres → API du panel) : aucune route ne peut être appelée.'];
    }

    // --- lectures -------------------------------------------------------------------
    $base = "/shops/$shop/benchmarks";
    $r = BenchmarkApi::appel('GET', "$base/competitors");
    $pas('Lister les concurrents', 'GET', "$base/competitors", $r, $estListe($r['corps']), is_array($r['corps']) ? count($r['corps']) . ' concurrent(s)' : '');
    $r2 = BenchmarkApi::appel('GET', "$base/competitors?include=observations");
    $pas('… avec leurs observations', 'GET', "$base/competitors?include=observations", $r2, $estListe($r2['corps']));
    $premier = $estListe($r2['corps']) && $r2['corps'] ? $r2['corps'][0] : null;
    if ($premier !== null && isset($premier['id'])) {
        $cid = (int) $premier['id'];
        $r = BenchmarkApi::appel('GET', "$base/competitors/$cid");
        $pas('Lire un concurrent', 'GET', "$base/competitors/$cid", $r, is_array($r['corps']) && (int) ($r['corps']['id'] ?? 0) === $cid);
        $r = BenchmarkApi::appel('GET', "$base/competitors/$cid/observations?include=items");
        $pas('Lister ses observations (+ items)', 'GET', "$base/competitors/$cid/observations?include=items", $r, $estListe($r['corps']));
        $obs = $estListe($r['corps']) && $r['corps'] ? $r['corps'][0] : null;
        if ($obs !== null && isset($obs['id'])) {
            $oid = (int) $obs['id'];
            $r = BenchmarkApi::appel('GET', "$base/observations/$oid?include=items");
            $pas('Lire une observation', 'GET', "$base/observations/$oid?include=items", $r, is_array($r['corps']) && (int) ($r['corps']['id'] ?? 0) === $oid);
            $r = BenchmarkApi::appel('GET', "$base/observations/$oid/items");
            $pas('Lister ses items', 'GET', "$base/observations/$oid/items", $r, $estListe($r['corps']));
            $it = $estListe($r['corps']) && $r['corps'] ? $r['corps'][0] : null;
            if ($it !== null && isset($it['id'])) {
                $iid = (int) $it['id'];
                $r = BenchmarkApi::appel('GET', "$base/observations/$oid/items/$iid");
                $pas('Lire un item', 'GET', "$base/observations/$oid/items/$iid", $r, is_array($r['corps']) && (int) ($r['corps']['id'] ?? 0) === $iid);
            }
        }
    } else {
        $alertes[] = "Aucun concurrent encore saisi pour le magasin $shop : les lectures de détail seront couvertes par la vérification en écriture.";
    }
    $qs = '?date_from=' . $du . '&date_to=' . $au;
    $r = BenchmarkApi::appel('GET', "$base/analysis/my-products$qs");
    $pas('Analyse « mes produits »', 'GET', "$base/analysis/my-products$qs", $r, is_array($r['corps']) && isset($r['corps']['kpi'], $r['corps']['products']),
        is_array($r['corps']['kpi'] ?? null) ? ($r['corps']['kpi']['comparisons_count'] ?? 0) . ' comparaison(s), fraîcheur ' . ($r['corps']['kpi']['freshness_days'] ?? '—') . ' j' : '');
    $r = BenchmarkApi::appel('GET', "$base/analysis/my-products/competitors$qs");
    $pas('Analyse par concurrent (magasin)', 'GET', "$base/analysis/my-products/competitors$qs", $r, is_array($r['corps']) && isset($r['corps']['breakdown']));
    $r = BenchmarkApi::appel('GET', "/benchmarks/analysis/my-products/competitors$qs");
    $pas('Analyse par concurrent (réseau)', 'GET', "/benchmarks/analysis/my-products/competitors$qs", $r, is_array($r['corps']) && isset($r['corps']['breakdown']));

    // --- écritures : un cycle complet sur un témoin, toujours nettoyé -------------------
    if ($ecrire) {
        $nom = 'ZZ Test cockpit ' . date('YmdHis');
        $r = BenchmarkApi::appel('POST', "$base/competitors", ['name' => $nom, 'type' => 'test', 'city' => 'Test', 'distance_km' => 0, 'notes' => 'vérification automatique — à supprimer', 'is_active' => false]);
        $cid = (int) (($r['corps']['inserted_id'] ?? 0));
        if ($r['ok'] && $cid <= 1) {
            // inserted_id booléen : retrouver le témoin par son nom.
            foreach ((array) BenchmarkApi::concurrents($shop) as $c) { if (($c['name'] ?? '') === $nom) { $cid = (int) $c['id']; } }
            $alertes[] = 'POST competitors a rendu inserted_id ' . (int) ($r['corps']['inserted_id'] ?? 0) . ' : identifiant retrouvé par relecture.';
        }
        $pas('Créer un concurrent témoin', 'POST', "$base/competitors", $r, $cid > 0, $cid > 0 ? "id $cid" : '');
        if ($cid > 0) {
            $r = BenchmarkApi::appel('GET', "$base/competitors/$cid");
            $pas('Relire le témoin', 'GET', "$base/competitors/$cid", $r, ($r['corps']['name'] ?? '') === $nom);
            $r = BenchmarkApi::appel('PATCH', "$base/competitors/$cid", ['notes' => 'modifié']);
            $relu = BenchmarkApi::concurrent($shop, $cid);
            $pas('Modifier le témoin', 'PATCH', "$base/competitors/$cid", $r, ($relu['notes'] ?? '') === 'modifié', 'vérifié par relecture');
            // Cloisonnement : le même concurrent lu depuis un autre magasin.
            $autre = $shop === 2 ? 3 : 2;
            $x = BenchmarkApi::appel('GET', "/shops/$autre/benchmarks/competitors/$cid");
            if (is_array($x['corps']) && (int) ($x['corps']['id'] ?? 0) === $cid) {
                $alertes[] = "Cloisonnement absent : le concurrent $cid du magasin $shop se lit depuis /shops/$autre (la spec le dit : shopId n'est pas contrôlé). À corriger côté TFBuddy.";
            }
            $r = BenchmarkApi::appel('POST', "$base/competitors/$cid/observations", ['observed_at' => date('Y-m-d'), 'notes' => 'témoin']);
            $oid = (int) ($r['corps']['inserted_id'] ?? 0);
            if ($r['ok'] && $oid <= 1) {
                foreach ((array) BenchmarkApi::observations($shop, $cid) as $o) { if (($o['notes'] ?? '') === 'témoin') { $oid = (int) $o['id']; } }
            }
            $pas('Créer une observation', 'POST', "$base/competitors/$cid/observations", $r, $oid > 0, $oid > 0 ? "id $oid" : '');
            if ($oid > 0) {
                $r = BenchmarkApi::appel('PATCH', "$base/observations/$oid", ['notes' => 'témoin modifié']);
                $relu = BenchmarkApi::observation($shop, $oid);
                $pas('Modifier l\'observation', 'PATCH', "$base/observations/$oid", $r, ($relu['notes'] ?? '') === 'témoin modifié', 'vérifié par relecture');
                $r = BenchmarkApi::appel('POST', "$base/competitors/$cid/observations/$oid/items",
                    ['name_raw' => 'Cougnou témoin', 'unit_type' => 'g', 'unit_size' => 250, 'price' => 3.0, 'is_promo' => false, 'notes' => 'témoin']);
                $iid = (int) ($r['corps']['inserted_id'] ?? 0);
                if ($r['ok'] && $iid <= 1) {
                    foreach ((array) BenchmarkApi::items($shop, $oid) as $i) { if (($i['name_raw'] ?? '') === 'Cougnou témoin') { $iid = (int) $i['id']; } }
                }
                $pas('Créer un item', 'POST', "$base/competitors/$cid/observations/$oid/items", $r, $iid > 0, $iid > 0 ? "id $iid" : '');
                if ($iid > 0) {
                    $r = BenchmarkApi::appel('PATCH', "$base/observations/$oid/items/$iid", ['price' => 3.2]);
                    $x = BenchmarkApi::appel('GET', "$base/observations/$oid/items/$iid");
                    $pas('Modifier l\'item', 'PATCH', "$base/observations/$oid/items/$iid", $r, abs((float) ($x['corps']['price'] ?? 0) - 3.2) < 0.001, 'vérifié par relecture');
                    $x = BenchmarkApi::appel('GET', "$base/observations/$oid/items");
                    $pas('Lister les items', 'GET', "$base/observations/$oid/items", $x, is_array($x['corps']) && in_array($iid, array_map(fn ($i) => (int) ($i['id'] ?? 0), (array) $x['corps']), true));
                    $r = BenchmarkApi::appel('DELETE', "$base/observations/$oid/items/$iid");
                    $pas('Supprimer l\'item', 'DELETE', "$base/observations/$oid/items/$iid", $r, true);
                }
                $r = BenchmarkApi::appel('DELETE', "$base/observations/$oid");
                $pas('Supprimer l\'observation', 'DELETE', "$base/observations/$oid", $r, true);
            }
            $r = BenchmarkApi::appel('DELETE', "$base/competitors/$cid");
            $reste = BenchmarkApi::concurrent($shop, $cid);
            $pas('Supprimer le témoin', 'DELETE', "$base/competitors/$cid", $r, $reste === null, $reste === null ? 'plus lisible après suppression' : 'encore lisible après suppression');
        }
    }

    $ko = array_values(array_filter($etapes, fn ($e) => !$e['ok']));
    $ok = $etapes !== [] && $ko === [];
    $resume = $ok
        ? count($etapes) . ' étape(s) passée(s)' . ($ecrire ? ', cycle d\'écriture complet et nettoyé' : ' en lecture seule')
        : count($ko) . ' étape(s) en échec sur ' . count($etapes) . ' : ' . implode(' ; ', array_map(fn ($e) => $e['etape'] . ' (' . $e['note'] . ')', array_slice($ko, 0, 3)));
    return ['ok' => $ok, 'magasin' => $shop, 'ecrire' => $ecrire, 'etapes' => $etapes, 'alertes' => $alertes, 'resume' => $resume];
}

/* =================================================================================
   2. LE BENCHMARK COUGNOU — référentiel, semis idempotent dans l'API
   ================================================================================= */

/** Le référentiel du benchmark cougnou (src/data/benchmark_cougnou.json). */
function benchmarkCougnouDonnees(): array
{
    static $d = null;
    if ($d === null) {
        $d = json_decode((string) @file_get_contents(__DIR__ . '/data/benchmark_cougnou.json'), true);
        if (!is_array($d)) { throw new RuntimeException('src/data/benchmark_cougnou.json illisible'); }
    }
    return $d;
}

/** Le poids retenu pour NOTRE cougnou individuel (g) — réglage `benchmark.poidsCougnou`, sinon le référentiel. */
function benchmarkPoidsCougnou(): int
{
    $s = setting('benchmark', []);
    $p = is_array($s) ? (int) ($s['poidsCougnou'] ?? 0) : 0;
    return $p > 0 ? $p : (int) (benchmarkCougnouDonnees()['nous']['poidsEstime_g'] ?? 220);
}

function benchmarkNomCle(string $n): string
{
    $n = mb_strtolower(trim($n));
    $n = strtr($n, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '’' => "'"]);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $n));
}

/**
 * Pose le benchmark cougnou dans l'API : pour chaque magasin, ses concurrents
 * (créés s'ils n'existent pas, retrouvés par leur nom sinon), une observation
 * datée du relevé, et ses items. Rejouable sans doublon : une observation porte
 * l'étiquette `[cockpit:cougnou:<date>]`, et une observation déjà étiquetée
 * n'est pas recréée.
 *
 * @param list<int> $magasins vide = tous
 */
function benchmarkCougnouSemer(bool $simuler = true, array $magasins = []): array
{
    $d = benchmarkCougnouDonnees();
    $releve = (string) $d['releve'];
    $tag = '[cockpit:cougnou:' . $releve . ']';
    $produits = $d['nous']['correspondance'] ?? [];
    $magasins = $magasins ?: array_keys(BenchmarkApi::magasins());
    $journal = []; $n = ['concurrents' => 0, 'observations' => 0, 'items' => 0, 'deja' => 0, 'erreurs' => 0];

    if (!$simuler && !BenchmarkApi::disponible()) {
        return ['ok' => false, 'simuler' => false, 'erreur' => 'compte consultant du panel non configuré', 'journal' => [], 'compte' => $n];
    }
    foreach ($magasins as $shop) {
        $shop = (int) $shop;
        $existants = [];
        if (!$simuler) {
            $liste = BenchmarkApi::concurrents($shop);
            if ($liste === null) { $n['erreurs']++; $journal[] = "magasin $shop : lecture des concurrents impossible — " . BenchmarkApi::$lastError; continue; }
            foreach ($liste as $c) { $existants[benchmarkNomCle((string) ($c['name'] ?? ''))] = (int) $c['id']; }
        }
        foreach ($d['concurrents'] as $c) {
            if (!in_array($shop, array_map('intval', (array) ($c['magasins'] ?? [])), true)) { continue; }
            $items = array_values(array_filter((array) ($c['items'] ?? []), fn ($i) => isset($i['price']) && (float) $i['price'] > 0));
            if ($items === []) { continue; }
            $cle = benchmarkNomCle((string) $c['nom']);
            $dist = $c['distance_km'][(string) $shop] ?? null;
            $fiche = array_filter([
                'name' => (string) $c['nom'], 'type' => (string) ($c['type'] ?? ''), 'city' => (string) ($c['ville'][(string) $shop] ?? ($c['ville']['*'] ?? '')),
                'distance_km' => $dist !== null ? (float) $dist : null,
                'notes' => trim('Benchmark cougnou — ' . ($c['note'] ?? '') . ' ' . ($c['site'] ?? '')), 'is_active' => true,
            ], fn ($v) => $v !== null && $v !== '');
            $cid = $existants[$cle] ?? 0;
            if ($simuler) {
                $journal[] = "magasin $shop : " . ($cid ? 'concurrent existant' : 'créer') . ' « ' . $c['nom'] . ' » + 1 observation ' . $releve . ' + ' . count($items) . ' item(s)';
                $n['concurrents'] += $cid ? 0 : 1; $n['observations']++; $n['items'] += count($items);
                continue;
            }
            if ($cid === 0) {
                $id = BenchmarkApi::creerConcurrent($shop, $fiche);
                if ($id !== null && $id <= 1) {
                    foreach ((array) BenchmarkApi::concurrents($shop) as $x) { if (benchmarkNomCle((string) ($x['name'] ?? '')) === $cle) { $id = (int) $x['id']; } }
                }
                if (!$id) { $n['erreurs']++; $journal[] = "magasin $shop : création de « {$c['nom']} » refusée — " . BenchmarkApi::$lastError; continue; }
                $cid = $id; $existants[$cle] = $cid; $n['concurrents']++;
            }
            $deja = false;
            foreach ((array) BenchmarkApi::observations($shop, $cid) as $o) { if (str_contains((string) ($o['notes'] ?? ''), $tag)) { $deja = true; } }
            if ($deja) { $n['deja']++; $journal[] = "magasin $shop : « {$c['nom']} » déjà relevé le $releve"; continue; }
            $oid = BenchmarkApi::creerObservation($shop, $cid, ['observed_at' => $releve, 'notes' => 'Relevé web cougnou ' . $releve . ' ' . $tag]);
            if ($oid !== null && $oid <= 1) {
                foreach ((array) BenchmarkApi::observations($shop, $cid) as $o) { if (str_contains((string) ($o['notes'] ?? ''), $tag)) { $oid = (int) $o['id']; } }
            }
            if (!$oid) { $n['erreurs']++; $journal[] = "magasin $shop : observation de « {$c['nom']} » refusée — " . BenchmarkApi::$lastError; continue; }
            $n['observations']++;
            foreach ($items as $i) {
                $corps = array_filter([
                    'name_raw' => (string) $i['name_raw'], 'unit_type' => (string) ($i['unit_type'] ?? 'g'),
                    'unit_size' => isset($i['unit_size']) ? (float) $i['unit_size'] : null, 'price' => (float) $i['price'],
                    'promo_price' => isset($i['promo_price']) ? (float) $i['promo_price'] : null, 'is_promo' => !empty($i['is_promo']),
                    'my_product_id' => isset($i['comparer_a'], $produits[$i['comparer_a']]) ? (int) $produits[$i['comparer_a']] : null,
                    'notes' => trim(($i['vu_le'] ?? '') . ' ' . ($i['source'] ?? '')),
                ], fn ($v) => $v !== null && $v !== '');
                if (BenchmarkApi::creerItem($shop, $cid, $oid, $corps) !== null) { $n['items']++; }
                else { $n['erreurs']++; $journal[] = "magasin $shop : item « {$i['name_raw']} » refusé — " . BenchmarkApi::$lastError; }
            }
            $journal[] = "magasin $shop : « {$c['nom']} » relevé ($releve, " . count($items) . ' item(s))';
        }
    }
    if (!$simuler && function_exists('journalAdd')) {
        journalAdd('Cockpit', 'benchmark', 'Benchmark cougnou', "Relevé cougnou $releve posé : {$n['concurrents']} concurrent(s), {$n['observations']} observation(s), {$n['items']} item(s), {$n['erreurs']} erreur(s).");
    }
    return ['ok' => $n['erreurs'] === 0, 'simuler' => $simuler, 'releve' => $releve, 'compte' => $n, 'journal' => $journal];
}

/* =================================================================================
   3. LE RAPPORT — prix au kilo, position, visibilité web
   ================================================================================= */

/** €/kg d'un item, ou null si son poids n'est pas connu. */
function benchmarkPrixKg(array $i): ?float
{
    $p = !empty($i['is_promo']) && (float) ($i['promo_price'] ?? 0) > 0 ? (float) $i['promo_price'] : (float) ($i['price'] ?? 0);
    $u = strtolower((string) ($i['unit_type'] ?? 'g'));
    $t = (float) ($i['unit_size'] ?? 0);
    if ($p <= 0 || $t <= 0) { return null; }
    $g = match ($u) { 'kg' => $t * 1000, 'g', 'gr' => $t, default => null };
    return $g !== null ? round($p / $g * 1000, 2) : null;
}

function benchmarkMediane(array $v): ?float
{
    $v = array_values(array_filter($v, 'is_numeric')); sort($v);
    $n = count($v);
    if ($n === 0) { return null; }
    return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 2);
}

/**
 * Les items observés : depuis l'API quand elle répond (c'est la vérité saisie,
 * terrain compris), sinon depuis le référentiel — et le rapport le dit.
 *
 * @return array{source:string, items:list<array>}
 */
function benchmarkCougnouItems(): array
{
    $d = benchmarkCougnouDonnees();
    $items = [];
    if (BenchmarkApi::disponible()) {
        $vu = [];
        foreach (BenchmarkApi::magasins() as $shop => $ville) {
            foreach ((array) BenchmarkApi::concurrents($shop, true) as $c) {
                foreach ((array) ($c['observations'] ?? []) as $o) {
                    foreach ((array) BenchmarkApi::items($shop, (int) $o['id']) as $i) {
                        if (!preg_match('/cougn|koekebr|cougnol/i', (string) ($i['name_raw'] ?? '') . ' ' . (string) ($i['my_product_name'] ?? ''))) { continue; }
                        // Un même concurrent national relevé pour 4 magasins ne compte qu'une fois.
                        $cle = benchmarkNomCle((string) $c['name']) . '|' . benchmarkNomCle((string) $i['name_raw']) . '|' . $i['price'];
                        if (isset($vu[$cle])) { $vu[$cle]['magasins'][] = $shop; continue; }
                        $vu[$cle] = $i + ['concurrent' => $c['name'], 'type' => $c['type'] ?? '', 'magasins' => [$shop], 'vu_le' => $o['observed_at'] ?? null];
                    }
                }
            }
        }
        $items = array_values($vu);
        if ($items !== []) { return ['source' => 'api', 'items' => $items]; }
    }
    foreach ($d['concurrents'] as $c) {
        foreach ((array) ($c['items'] ?? []) as $i) {
            if ((float) ($i['price'] ?? 0) <= 0) { continue; }
            $items[] = $i + ['concurrent' => $c['nom'], 'type' => $c['type'] ?? '', 'magasins' => $c['magasins'] ?? []];
        }
    }
    return ['source' => 'referentiel', 'items' => $items];
}

/**
 * Ce que voit un moteur de recherche sur atelierby.be pour « cougnou » —
 * contrôles automatiques, sans clé : titre et description de la page de gamme,
 * cougnou dans le HTML servi, prix et poids affichés, balisage Product,
 * sitemap, robots.txt.
 */
function benchmarkVisibiliteSite(): array
{
    $d = benchmarkCougnouDonnees();
    $site = rtrim((string) ($d['site']['url'] ?? 'https://atelierby.be'), '/');
    $page = $site . (string) ($d['site']['pageGamme'] ?? '/gammes/gamme-noel-nouvel-an/');
    $lire = static function (string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CockpitBenchmark/1.0)']);
        $t = curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$c, is_string($t) ? $t : ''];
    };
    $visible = static fn (string $h) => mb_strtolower(html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $h)), ENT_QUOTES, 'UTF-8'));
    [$cA, $hA] = $lire($site . '/');
    [$cG, $hG] = $lire($page);
    [$cS, $hS] = $lire($site . '/sitemap.xml');
    [$cR] = $lire($site . '/robots.txt');
    preg_match('#<title>(.*?)</title>#is', $hG, $t);
    preg_match('#<meta\s+name="description"\s+content="([^"]*)"#i', $hG, $m);
    $titre = html_entity_decode(trim($t[1] ?? ''), ENT_QUOTES, 'UTF-8');
    $desc = html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES, 'UTF-8');
    $vg = $visible($hG);
    $controles = [
        ['cle' => 'page', 'libelle' => 'Page de la gamme Noël en ligne', 'ok' => $cG === 200, 'detail' => $page . ' → HTTP ' . $cG],
        ['cle' => 'titre', 'libelle' => '« cougnou » dans le titre de la page', 'ok' => stripos($titre, 'cougnou') !== false, 'detail' => $titre ?: '—'],
        ['cle' => 'description', 'libelle' => '« cougnou » dans la meta description', 'ok' => stripos($desc, 'cougnou') !== false, 'detail' => $desc ?: '—'],
        ['cle' => 'texte', 'libelle' => 'Cougnous lisibles dans le HTML servi', 'ok' => substr_count($vg, 'cougnou') >= 2, 'detail' => substr_count($vg, 'cougnou') . ' mention(s)'],
        ['cle' => 'prix', 'libelle' => 'Prix affiché sur la page', 'ok' => (bool) preg_match('/\d+[,.]\d{2}\s*(€|eur)/u', $vg), 'detail' => ''],
        ['cle' => 'poids', 'libelle' => 'Poids affiché (g) pour comparer au kilo', 'ok' => ($nPoids = preg_match_all('/cougnou[^€]{0,60}?\d{2,4}\s*g\b/u', $vg)) >= 3, 'detail' => $nPoids . ' cougnou(s) avec poids'],
        ['cle' => 'schema', 'libelle' => 'Balisage schema.org Product / Offer', 'ok' => (bool) preg_match('/"@type"\s*:\s*"(Product|Offer)"/', $hG), 'detail' => ''],
        ['cle' => 'accueil', 'libelle' => 'Cougnou visible sur l\'accueil (HTML servi)', 'ok' => str_contains($visible($hA), 'cougnou'), 'detail' => $cA === 200 ? '' : 'HTTP ' . $cA],
        ['cle' => 'sitemap', 'libelle' => 'Page de gamme dans le sitemap', 'ok' => $cS === 200 && str_contains($hS, (string) ($d['site']['pageGamme'] ?? 'gamme-noel')), 'detail' => ''],
        ['cle' => 'robots', 'libelle' => 'robots.txt présent', 'ok' => $cR === 200, 'detail' => 'HTTP ' . $cR],
        ['cle' => 'nl', 'libelle' => '« koekebrood » sur la version NL (Halle)', 'ok' => false, 'detail' => ''],
    ];
    [$cN, $hN] = $lire($site . '/nl/gammas/' . basename(rtrim((string) ($d['site']['pageGamme'] ?? 'gamme-noel-nouvel-an'), '/')) . '/');
    $controles[count($controles) - 1]['ok'] = $cN === 200 && str_contains($visible($hN), 'koekebrood');
    $controles[count($controles) - 1]['detail'] = 'HTTP ' . $cN;
    $okN = count(array_filter($controles, fn ($c) => $c['ok']));
    return ['score' => (int) round($okN / count($controles) * 100), 'ok' => $okN, 'total' => count($controles), 'controles' => $controles, 'lu_le' => date('Y-m-d H:i')];
}

/**
 * Le rang de nos boutiques sur Google Maps pour « cougnou <ville> » et
 * « boulangerie <ville> » — si la clé Google (Places) est posée. Sinon null,
 * et le rapport le dit.
 */
function benchmarkVisibiliteGoogle(): ?array
{
    if (!class_exists('GoogleApi') || !GoogleApi::configured()) { return null; }
    $out = [];
    foreach (benchmarkCougnouDonnees()['nous']['boutiques'] as $b) {
        foreach (['cougnou ' . $b['ville'], 'boulangerie ' . $b['ville']] + ($b['nl'] ?? false ? [2 => 'koekebrood ' . $b['ville']] : []) as $q) {
            $res = GoogleApi::chercher($q);
            if ($res === null) { $out[] = ['requete' => $q, 'erreur' => GoogleApi::$lastError]; continue; }
            $rang = null;
            foreach ($res as $k => $p) { if (stripos((string) $p['nom'], 'atelier') !== false) { $rang = $k + 1; break; } }
            $out[] = ['requete' => $q, 'magasin' => $b['id'], 'rang' => $rang, 'top' => array_map(fn ($p) => ['nom' => $p['nom'], 'note' => $p['note'], 'avis' => $p['avis']], array_slice($res, 0, 5))];
        }
    }
    return $out;
}

/** Le rapport complet, en données. */
function benchmarkCougnouRapport(bool $avecWeb = true): array
{
    $d = benchmarkCougnouDonnees();
    $poids = benchmarkPoidsCougnou();
    $nous = [];
    foreach ($d['nous']['produits'] as $p) {
        $g = (int) ($p['poids_g'] ?? 0) ?: ((!empty($p['individuel'])) ? $poids : 0);
        $prix = (float) ($p['prix'] ?? 0);
        $nous[] = $p + ['poids_retenu_g' => $g ?: null, 'eur_kg' => $prix > 0 && $g > 0 ? round($prix / $g * 1000, 2) : null];
    }
    $refKg = null;
    foreach ($nous as $p) { if (!empty($p['individuel']) && $p['eur_kg'] !== null) { $refKg = $p['eur_kg']; break; } }

    ['source' => $source, 'items' => $items] = benchmarkCougnouItems();
    $lignes = []; $parType = [];
    foreach ($items as $i) {
        $kg = benchmarkPrixKg($i);
        $lignes[] = ['concurrent' => $i['concurrent'], 'type' => $i['type'], 'produit' => $i['name_raw'], 'poids_g' => $i['unit_size'] ?? null,
            'prix' => (float) $i['price'], 'promo' => !empty($i['is_promo']) ? (float) ($i['promo_price'] ?? 0) : null, 'eur_kg' => $kg,
            'ecart_kg_pct' => $kg !== null && $refKg !== null ? round(($refKg - $kg) / $kg * 100, 1) : null,
            'vu_le' => $i['vu_le'] ?? null, 'source' => $i['source'] ?? null, 'magasins' => $i['magasins'] ?? []];
        if ($kg !== null) { $parType[(string) $i['type']][] = $kg; }
    }
    usort($lignes, fn ($a, $b) => ($a['eur_kg'] ?? 999) <=> ($b['eur_kg'] ?? 999));
    $types = [];
    foreach ($parType as $t => $v) {
        $med = benchmarkMediane($v);
        $types[] = ['type' => $t, 'n' => count($v), 'min' => min($v), 'mediane' => $med, 'max' => max($v),
            'ecart_pct' => $refKg !== null && $med ? round(($refKg - $med) / $med * 100, 1) : null];
    }
    $tous = array_values(array_filter(array_column($lignes, 'eur_kg'), 'is_numeric'));
    $moinsCher = $refKg !== null ? count(array_filter($tous, fn ($k) => $k < $refKg)) : null;

    $analyses = [];
    if (BenchmarkApi::disponible()) {
        foreach (BenchmarkApi::magasins() as $shop => $ville) {
            $a = BenchmarkApi::analyse($shop, date('Y-m-d', strtotime('-60 days')), date('Y-m-d'));
            if ($a !== null) { $analyses[$shop] = ['ville' => $ville, 'kpi' => $a['kpi'], 'cougnous' => array_values(array_filter((array) $a['products'], fn ($p) => preg_match('/cougn/i', (string) ($p['my_product_name'] ?? ''))))]; }
        }
    }
    return [
        'genere_le' => date('Y-m-d H:i'), 'releve' => $d['releve'], 'source' => $source,
        'poids_cougnou_g' => $poids, 'poids_confirme' => (bool) ($d['nous']['poidsConfirme'] ?? false),
        'nous' => $nous, 'ref_eur_kg' => $refKg,
        'concurrents' => $lignes, 'par_type' => $types,
        'position' => $refKg !== null ? ['moins_chers' => $moinsCher, 'sur' => count($tous), 'mediane' => benchmarkMediane($tous)] : null,
        'analyses_api' => $analyses,
        'web' => $avecWeb ? benchmarkVisibiliteSite() : null,
        'google' => $avecWeb ? benchmarkVisibiliteGoogle() : null,
        'constats' => $d['constats'] ?? [],
    ];
}

/** Le rapport en e-mail (gabarit commun, §13.6). */
function benchmarkCougnouHtml(array $r): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn (?float $v) => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' €';
    $pct = static fn (?float $v) => $v === null ? '—' : ($v > 0 ? '+' : '') . number_format($v, 1, ',', ' ') . ' %';
    $th = 'style="text-align:left;padding:6px 8px;border-bottom:1px solid ' . DOC_LINE . ';font-size:11px;color:' . DOC_MUTED . ';font-weight:700"';
    $td = 'style="padding:6px 8px;border-bottom:1px solid ' . DOC_LINE . ';font-size:12px;vertical-align:top"';
    $tdr = 'style="padding:6px 8px;border-bottom:1px solid ' . DOC_LINE . ';font-size:12px;text-align:right;white-space:nowrap"';
    $h = '';
    $pos = $r['position'];
    $h .= docMailP('Relevé du <b>' . $e(docDate((string) $r['releve'])) . '</b> · source des prix concurrents : '
        . ($r['source'] === 'api' ? 'module Benchmark de TFBuddy' : 'référentiel du cockpit (l\'API n\'a rien rendu)') . '.');
    if ($pos !== null) {
        $h .= docMailP('Notre cougnou individuel (2,90 € pour ' . (int) $r['poids_cougnou_g'] . ' g' . ($r['poids_confirme'] ? '' : ', <i>poids à confirmer</i>')
            . ') revient à <b>' . $eur($r['ref_eur_kg']) . '/kg</b>. ' . (int) $pos['moins_chers'] . ' produit(s) concurrent(s) sur ' . (int) $pos['sur']
            . ' sont moins chers au kilo ; médiane observée ' . $eur($pos['mediane']) . '/kg.');
    }
    // Par type
    $h .= '<div style="font-size:15px;font-weight:700;margin:16px 0 6px">Prix au kilo par type de concurrent</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>Type</th><th ' . $th . '>Relevés</th><th ' . $th . '>Min</th><th ' . $th . '>Médiane</th><th ' . $th . '>Max</th><th ' . $th . '>Nous vs médiane</th></tr>';
    foreach ($r['par_type'] as $t) {
        $h .= '<tr><td ' . $td . '>' . $e($t['type']) . '</td><td ' . $tdr . '>' . (int) $t['n'] . '</td><td ' . $tdr . '>' . $eur($t['min']) . '</td><td ' . $tdr . '>' . $eur($t['mediane']) . '</td><td ' . $tdr . '>' . $eur($t['max']) . '</td><td ' . $tdr . '>' . $pct($t['ecart_pct']) . '</td></tr>';
    }
    $h .= '</table>';
    // Détail
    $h .= '<div style="font-size:15px;font-weight:700;margin:16px 0 6px">Détail des relevés</div>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse"><tr><th ' . $th . '>Concurrent</th><th ' . $th . '>Produit</th><th ' . $th . '>Poids</th><th ' . $th . '>Prix</th><th ' . $th . '>€/kg</th></tr>';
    foreach (array_slice($r['concurrents'], 0, 40) as $l) {
        $src = $l['source'] ? ' <a href="' . $e($l['source']) . '" style="color:' . DOC_RUBY . ';text-decoration:none">↗</a>' : '';
        $h .= '<tr><td ' . $td . '>' . $e($l['concurrent']) . '<br><span style="color:' . DOC_MUTED . ';font-size:10px">' . $e($l['type']) . ($l['vu_le'] ? ' · ' . $e($l['vu_le']) : '') . '</span></td><td ' . $td . '>' . $e($l['produit']) . $src . '</td><td ' . $tdr . '>' . ($l['poids_g'] ? (int) $l['poids_g'] . ' g' : '—') . '</td><td ' . $tdr . '>' . $eur($l['prix']) . '</td><td ' . $tdr . '>' . $eur($l['eur_kg']) . '</td></tr>';
    }
    $h .= '</table>';
    // Web
    if (is_array($r['web'])) {
        $h .= '<div style="font-size:15px;font-weight:700;margin:16px 0 6px">Visibilité web du cougnou sur atelierby.be — ' . (int) $r['web']['ok'] . ' / ' . (int) $r['web']['total'] . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">';
        foreach ($r['web']['controles'] as $c) {
            $h .= '<tr><td ' . $td . ' width="22"><b style="color:' . ($c['ok'] ? DOC_GREEN : DOC_STOP) . '">' . ($c['ok'] ? '✓' : '✗') . '</b></td><td ' . $td . '>' . $e($c['libelle']) . ($c['detail'] !== '' ? '<br><span style="color:' . DOC_MUTED . ';font-size:10px">' . $e(mb_substr($c['detail'], 0, 140)) . '</span>' : '') . '</td></tr>';
        }
        $h .= '</table>';
    }
    if (is_array($r['google'])) {
        $h .= '<div style="font-size:15px;font-weight:700;margin:16px 0 6px">Google Maps — notre rang</div><table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">';
        foreach ($r['google'] as $g) {
            $top = isset($g['top']) ? implode(', ', array_map(fn ($p) => $p['nom'] . ($p['note'] ? ' ' . number_format((float) $p['note'], 1, ',', '') . '★ (' . (int) $p['avis'] . ')' : ''), $g['top'])) : ($g['erreur'] ?? '');
            $h .= '<tr><td ' . $td . '>' . $e($g['requete']) . '</td><td ' . $tdr . '>' . (isset($g['rang']) && $g['rang'] ? '#' . (int) $g['rang'] : 'absent du top 8') . '</td><td ' . $td . ' style="font-size:10px">' . $e($top) . '</td></tr>';
        }
        $h .= '</table>';
    } else {
        $h .= docMailP('<span style="color:' . DOC_MUTED . ';font-size:12px">Rang Google Maps non mesuré : clé Google (Places) absente des Paramètres.</span>');
    }
    if ($r['constats']) {
        $h .= '<div style="font-size:15px;font-weight:700;margin:16px 0 6px">À faire</div>';
        foreach ($r['constats'] as $c) { $h .= docMailP('— ' . $e($c)); }
    }
    return docMail(['sujet' => 'Benchmark cougnou', 'service' => 'Cockpit', 'titre' => 'Cougnou : prix et visibilité face à la concurrence', 'corps' => $h,
        'pied' => docMailPied('Rapport automatique du cockpit (routine Benchmark : le lundi, et aussi le jeudi pendant la saison du cougnou).')]);
}

/* =================================================================================
   4. LA ROUTINE — appelée chaque heure par bin/rapports_cron.sh, elle décide
   ================================================================================= */

/** Faut-il passer maintenant ? Lundi 7 h toute l'année ; lundi et jeudi 7 h du 1er novembre au 6 janvier. */
function benchmarkCronDu(DateTimeInterface $now): bool
{
    $h = (int) $now->format('G'); $dow = (int) $now->format('N'); $md = (int) $now->format('md');
    $saison = $md >= 1101 || $md <= 106;
    return $h === 7 && ($dow === 1 || ($saison && $dow === 4));
}

function benchmarkTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_benchmark_snapshot ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'pris_le DATETIME NOT NULL,'
        . 'shop_id INT NOT NULL DEFAULT 0,'
        . 'sujet VARCHAR(40) NOT NULL,'
        . 'contenu MEDIUMTEXT NOT NULL,'
        . 'KEY idx_sujet (sujet, pris_le)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** La routine elle-même — aussi lançable à la main (`forcer`). */
function benchmarkRoutine(bool $forcer = false, bool $envoyer = true): array
{
    $now = new DateTime('now', new DateTimeZone('Europe/Brussels'));
    if (!$forcer && !benchmarkCronDu($now)) { return ['fait' => false, 'motif' => 'pas l\'heure (lundi 7 h ; + jeudi 7 h en saison cougnou)']; }
    $s = setting('benchmark', []);
    if (!is_array($s)) { $s = []; }
    $jour = $now->format('Y-m-d');
    if (!$forcer && ($s['dernierPassage'] ?? '') === $jour) { return ['fait' => false, 'motif' => 'déjà passé aujourd\'hui']; }
    benchmarkTables();

    $verif = benchmarkVerifier(4, false);
    $snap = [];
    foreach (BenchmarkApi::magasins() as $shop => $ville) {
        $a = BenchmarkApi::analyse($shop, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
        if ($a === null) { continue; }
        Db::exec('INSERT INTO ceo_benchmark_snapshot (pris_le, shop_id, sujet, contenu) VALUES (?,?,?,?)',
            [$now->format('Y-m-d H:i:s'), $shop, 'analyse', json_encode($a, JSON_UNESCAPED_UNICODE)]);
        $snap[$shop] = $a['kpi'] ?? null;
    }
    $rapport = benchmarkCougnouRapport(true);
    Db::exec('INSERT INTO ceo_benchmark_snapshot (pris_le, shop_id, sujet, contenu) VALUES (?,?,?,?)',
        [$now->format('Y-m-d H:i:s'), 0, 'cougnou', json_encode($rapport, JSON_UNESCAPED_UNICODE)]);

    $envois = [];
    $dest = array_values(array_filter(array_map('trim', (array) ($s['destinataires'] ?? [])), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
    if ($envoyer && $dest !== [] && class_exists('Smtp')) {
        $html = benchmarkCougnouHtml($rapport);
        $sujet = 'Benchmark cougnou — ' . $now->format('d/m/Y') . ($verif['ok'] ? '' : ' (API Benchmark : ' . count(array_filter($verif['etapes'], fn ($x) => !$x['ok'])) . ' route(s) en échec)');
        foreach ($dest as $m) { $envois[$m] = Smtp::envoyer($m, $sujet, $html) ? 'envoye' : ('erreur : ' . (Smtp::$lastError ?? '?')); }
    }
    $s['dernierPassage'] = $jour;
    $s['dernierResume'] = $verif['resume'];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['benchmark', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    if (function_exists('journalAdd')) {
        journalAdd('Cockpit', 'benchmark', 'Benchmark cougnou', 'Routine benchmark : ' . $verif['resume'] . ' ; ' . count($snap) . ' magasin(s) photographié(s) ; ' . count($envois) . ' envoi(s).');
    }
    return ['fait' => true, 'verification' => $verif, 'kpi' => $snap, 'envois' => $envois, 'web' => $rapport['web']['score'] ?? null];
}

/* =================================================================================
   5. LES ROUTES DU COCKPIT
   ================================================================================= */

/** GET /benchmarks/verification?shop=4 — lecture seule. */
function ep_benchmark_verification(): array
{
    return benchmarkVerifier(max(1, (int) ($_GET['shop'] ?? 4)), false);
}

/** POST /benchmarks/verification {shop, ecrire} — avec le cycle d'écriture sur un témoin. */
function wr_benchmark_verification(): array
{
    $b = body();
    return benchmarkVerifier(max(1, (int) ($b['shop'] ?? 4)), !empty($b['ecrire']));
}

/** POST /benchmarks/cougnou/semer {simuler?: bool (défaut vrai), magasins?: int[]} */
function wr_benchmark_cougnou_semer(): array
{
    $b = body();
    return benchmarkCougnouSemer(($b['simuler'] ?? true) !== false, array_map('intval', (array) ($b['magasins'] ?? [])));
}

/** GET /benchmarks/cougnou/rapport — en données ; `?web=0` sans les contrôles web. */
function ep_benchmark_cougnou_rapport(): array
{
    return benchmarkCougnouRapport(($_GET['web'] ?? '1') !== '0');
}

/** GET /benchmarks/cron?jeton=… — même jeton que les rapports ; `forcer=1` pour passer hors horaire. */
function ep_benchmark_cron(): array
{
    $jeton = (string) setting('rapportsJeton', '');
    if ($jeton === '' || !hash_equals($jeton, (string) ($_GET['jeton'] ?? ''))) {
        http_response_code(403);
        return ['error' => 'jeton absent ou invalide'];
    }
    return benchmarkRoutine(!empty($_GET['forcer']));
}

/** PUT /benchmarks/reglages {destinataires?: string[], poidsCougnou?: int, magasins?: {id: nom}} */
function wr_benchmark_reglages(): array
{
    $b = body();
    $s = setting('benchmark', []);
    if (!is_array($s)) { $s = []; }
    if (isset($b['destinataires'])) {
        $s['destinataires'] = array_values(array_filter(array_map('trim', (array) $b['destinataires']), fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
    }
    if (isset($b['poidsCougnou'])) { $s['poidsCougnou'] = max(0, min(2000, (int) $b['poidsCougnou'])); }
    if (isset($b['magasins']) && is_array($b['magasins'])) {
        $s['magasins'] = [];
        foreach ($b['magasins'] as $id => $nom) { if ((int) $id > 0) { $s['magasins'][(int) $id] = mb_substr((string) $nom, 0, 60); } }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['benchmark', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'reglages' => array_diff_key($s, ['dernierResume' => 1])];
}
