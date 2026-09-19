<?php
declare(strict_types=1);

/**
 * Scouting commercial — le cache OpenStreetMap, côté serveur.
 *
 * Les boulangeries, communes et lieux peuplés de Belgique viennent d'Overpass,
 * en neuf secteurs. Interroger Overpass prend une à trois minutes par secteur :
 * l'écran ne le fait donc plus lui-même quand l'API répond. Il lit la table
 * `ceo_scouting_tile`, et c'est le serveur qui rafraîchit — chaque semaine par
 * cron (bin/scouting_refresh.php), ou à la demande, secteur par secteur
 * (POST /scouting/refresh/{n}, le bouton « Recharger les données »).
 *
 * Les secteurs et l'analyse de la réponse sont la copie exacte de
 * public/assets/js/scouting.js (TILES, parseTile) : les deux côtés produisent la
 * même forme { t, c, b, p }, et le navigateur garde son propre chemin Overpass
 * en repli, hors ligne ou si le serveur n'atteint pas Overpass. Toute
 * modification de l'un se reporte dans l'autre.
 */
final class ScoutingOsm
{
    public static ?string $lastError = null;

    /** Belgique en 9 secteurs — même ordre, mêmes emprises que TILES côté écran. */
    public const SECTEURS = [
        ['50.60,2.52,51.55,3.45', 'Flandre-Occidentale'],
        ['50.60,3.45,51.55,4.35', 'Flandre-Orientale'],
        ['50.60,4.35,51.55,5.15', 'Anvers · Brabant flamand · Bruxelles'],
        ['50.60,5.15,51.55,6.10', 'Limbourg'],
        ['50.20,2.80,50.60,4.35', 'Hainaut occidental'],
        ['49.90,4.35,50.60,5.20', 'Brabant wallon · Charleroi · Namur'],
        ['50.20,5.20,50.85,6.41', 'Liège · Verviers'],
        ['49.90,4.30,50.35,5.20', 'Dinant · Philippeville'],
        ['49.44,4.85,50.20,6.05', 'Province de Luxembourg'],
    ];

    /** Un secteur relu depuis moins longtemps que cela n'est pas relu par le cron. */
    public const FRAICHEUR_S = 6 * 86400;

    private const MIROIRS = [
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    /** Arrondissements administratifs : deux premiers chiffres du code NIS. */
    private const ARR = [
        11 => 'Anvers', 12 => 'Malines', 13 => 'Turnhout',
        21 => 'Bruxelles-Capitale', 23 => 'Hal-Vilvorde', 24 => 'Louvain', 25 => 'Nivelles',
        31 => 'Bruges', 32 => 'Dixmude', 33 => 'Ypres', 34 => 'Courtrai', 35 => 'Ostende',
        36 => 'Roulers', 37 => 'Tielt', 38 => 'Furnes',
        41 => 'Alost', 42 => 'Termonde', 43 => 'Eeklo', 44 => 'Gand', 45 => 'Audenarde', 46 => 'Saint-Nicolas',
        51 => 'Ath', 52 => 'Charleroi', 53 => 'Mons', 54 => 'Mouscron', 55 => 'Soignies', 56 => 'Thuin', 57 => 'Tournai', 58 => 'La Louvière',
        61 => 'Huy', 62 => 'Liège', 63 => 'Verviers', 64 => 'Waremme',
        71 => 'Hasselt', 72 => 'Maaseik', 73 => 'Tongres',
        81 => 'Arlon', 82 => 'Bastogne', 83 => 'Marche-en-Famenne', 84 => 'Neufchâteau', 85 => 'Virton',
        91 => 'Dinant', 92 => 'Namur', 93 => 'Philippeville',
    ];

    /** Préfixe NIS → province ; un code absent est hors Belgique. */
    private const INS_PROV = [
        '11' => 'VAN', '12' => 'VAN', '13' => 'VAN',
        '21' => 'BRU', '23' => 'VBR', '24' => 'VBR', '25' => 'WBR',
        '31' => 'VWV', '32' => 'VWV', '33' => 'VWV', '34' => 'VWV', '35' => 'VWV', '36' => 'VWV', '37' => 'VWV', '38' => 'VWV',
        '41' => 'VOV', '42' => 'VOV', '43' => 'VOV', '44' => 'VOV', '45' => 'VOV', '46' => 'VOV',
        '51' => 'WHT', '52' => 'WHT', '53' => 'WHT', '54' => 'WHT', '55' => 'WHT', '56' => 'WHT', '57' => 'WHT', '58' => 'WHT',   // 58 : arrondissement de La Louvière (2019)
        '61' => 'WLG', '62' => 'WLG', '63' => 'WLG', '64' => 'WLG',
        '71' => 'VLI', '72' => 'VLI', '73' => 'VLI',
        '81' => 'WLX', '82' => 'WLX', '83' => 'WLX', '84' => 'WLX', '85' => 'WLX',
        '91' => 'WNA', '92' => 'WNA', '93' => 'WNA',
    ];

    /** La requête Overpass d'un secteur — identique à celle de l'écran. */
    public static function requete(string $bbox): string
    {
        // « bb » : la boîte englobante de chaque commune, dont on tire son emprise ;
        // son centre est celui que « center » rendait.
        return '[out:json][timeout:240];rel(' . $bbox . ')["boundary"="administrative"]["admin_level"="8"];out tags bb;'
            . 'node(' . $bbox . ')["place"]["population"];out tags center;'
            . '(nwr["shop"="bakery"](' . $bbox . ');nwr["shop"="pastry"](' . $bbox . '););out center tags;';
    }

    /** Interroge Overpass pour un secteur : les miroirs à tour de rôle, deux passes. */
    public static function interroger(int $secteur): ?array
    {
        if (!isset(self::SECTEURS[$secteur])) { self::$lastError = 'secteur inconnu'; return null; }
        return self::appel(self::requete(self::SECTEURS[$secteur][0]), $secteur, 250);
    }

    /** Un appel Overpass : les miroirs à tour de rôle, deux passes. */
    private static function appel(string $q, int $secteur, int $timeout): ?array
    {
        $n = count(self::MIROIRS);
        for ($essai = 0; $essai < 2; $essai++) {
            for ($k = 0; $k < $n; $k++) {
                // Chaque secteur part d'un miroir différent : trois secteurs relus
                // en parallèle ne font pas la file sur le même serveur.
                $ep = self::MIROIRS[($secteur + $k) % $n];
                $ch = curl_init($ep);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => 'data=' . rawurlencode($q),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: cockpit-ceo-scouting/1.0'],
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
                ]);
                $raw = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($raw === false) { self::$lastError = $ep . ' : ' . ($err ?: 'appel impossible'); continue; }
                if ($code !== 200) { self::$lastError = $ep . ' : HTTP ' . $code; continue; }
                $json = json_decode((string) $raw, true);
                if (!is_array($json) || !isset($json['elements'])) { self::$lastError = $ep . ' : réponse vide'; continue; }
                return $json;
            }
            sleep(3);
        }
        return null;
    }

    /** Première valeur non vide, comme le `||` de l'écran. */
    private static function ou(array $t, array $cles, string $defaut = ''): string
    {
        foreach ($cles as $k) {
            $v = $t[$k] ?? null;
            if ($v !== null && $v !== '' && $v !== false) { return (string) $v; }
        }
        return $defaut;
    }

    /** Réponse Overpass → { t, c, b, p } — port exact de parseTile (scouting.js). */
    public static function analyser(array $r): array
    {
        $tc = []; $tb = []; $tp = [];
        foreach ((array) ($r['elements'] ?? []) as $e) {
            if (!is_array($e)) { continue; }
            $t = (array) ($e['tags'] ?? []);
            $centre = (array) ($e['center'] ?? []);
            if (($t['boundary'] ?? null) === 'administrative') {
                $ins = preg_replace('/[^0-9]/', '', self::ou($t, ['ref:INS', 'ref']));
                $prov = self::INS_PROV[substr($ins, 0, 2)] ?? null;
                $bb = (array) ($e['bounds'] ?? []);
                if (!isset($centre['lat']) && isset($bb['minlat'])) {
                    $centre = ['lat' => ((float) $bb['minlat'] + (float) $bb['maxlat']) / 2, 'lon' => ((float) $bb['minlon'] + (float) $bb['maxlon']) / 2];
                }
                if ($prov === null || !isset($centre['lat'])) { continue; }
                $pop = (int) preg_replace('/[^0-9]/', '', (string) ($t['population'] ?? ''));
                $c = [
                    'id' => $e['id'], 'name' => self::ou($t, ['name:fr', 'name'], '—'), 'nl' => self::ou($t, ['name']), 'ins' => $ins,
                    'arr' => self::ARR[(int) substr($ins, 0, 2)] ?? '—', 'prov' => $prov, 'pop' => $pop,
                    'lat' => $centre['lat'], 'lng' => $centre['lon'],
                ];
                if (isset($bb['minlat'])) { $c['bb'] = [(float) $bb['minlat'], (float) $bb['minlon'], (float) $bb['maxlat'], (float) $bb['maxlon']]; }
                $tc[] = $c;
                continue;
            }
            $lat = $e['lat'] ?? ($centre['lat'] ?? null);
            $lng = $e['lon'] ?? ($centre['lon'] ?? null);
            if (self::ou($t, ['place']) !== '' && self::ou($t, ['population']) !== '') {
                $pop = (int) preg_replace('/[^0-9]/', '', (string) $t['population']);
                if ($pop > 0 && $lat !== null) {
                    $tp[] = ['name' => self::ou($t, ['name:fr', 'name']), 'nl' => self::ou($t, ['name']), 'pop' => $pop, 'lat' => $lat, 'lng' => $lng];
                }
                continue;
            }
            $shop = $t['shop'] ?? null;
            if ($shop !== 'bakery' && $shop !== 'pastry') { continue; }
            if ($lat === null) { continue; }
            $rue = trim(implode(' ', array_filter([$t['addr:street'] ?? '', $t['addr:housenumber'] ?? ''], static fn ($v) => $v !== '' && $v !== null)));
            $ville = trim(implode(' ', array_filter([$t['addr:postcode'] ?? '', $t['addr:city'] ?? ''], static fn ($v) => $v !== '' && $v !== null)));
            $tb[] = [
                'id' => substr((string) ($e['type'] ?? 'n'), 0, 1) . $e['id'],
                'name' => self::ou($t, ['name', 'brand'], 'Boulangerie sans nom'),
                'lat' => $lat, 'lng' => $lng,
                'addr' => implode(', ', array_filter([$rue, $ville], static fn ($v) => $v !== '')),
                'brand' => self::ou($t, ['brand']),
                'web' => self::ou($t, ['website', 'contact:website']) !== '',
                'hours' => self::ou($t, ['opening_hours']),
                'pastry' => $shop === 'pastry',
                'cuisine' => self::ou($t, ['cuisine']),
            ];
        }
        return ['t' => (int) round(microtime(true) * 1000), 'c' => $tc, 'b' => $tb, 'p' => $tp];
    }

    /* ---------------------------------------------------------------------
     * Le zoning industriel — une requête À PART, et des secteurs à part.
     *
     * Les zones d'activité sont utiles au scouting : elles portent des
     * travailleurs qui déjeunent, et du passage en semaine. Mais elles ne
     * doivent RIEN coûter au relevé des boulangeries : une requête alourdie
     * qui dépasse son temps chez Overpass cesserait de rafraîchir tout le
     * reste. Elles ont donc leur propre requête, et se rangent dans la même
     * table sous les secteurs 100 à 108 — aucun DDL, et un échec du zoning
     * laisse les secteurs 0 à 8 intacts.
     * ------------------------------------------------------------------- */

    /** Décalage des secteurs de zoning dans `ceo_scouting_tile`. */
    public const ZONING_BASE = 100;

    /** Une zone plus petite que cela n'est pas un zoning : c'est une parcelle. */
    private const ZONING_MIN_M = 260.0;

    /** La requête du zoning : surfaces d'activité, avec leur emprise. */
    public static function requeteZoning(string $bbox): string
    {
        // `out tags bb` : la boîte englobante donne le centre ET la taille, dont
        // on tire un rayon. Les nœuds seuls sont ignorés — sans emprise, on ne
        // saurait pas si c'est un parc d'activité ou un atelier.
        // Même budget de temps que la requête des commerces : le secteur d'Anvers
        // et de Bruxelles dépassait 180 s, et l'appel HTTP rendait la main avant
        // Overpass — on relevait alors un secteur sur deux.
        return '[out:json][timeout:240];'
            . '(way(' . $bbox . ')["landuse"~"^(industrial|commercial|retail)$"];'
            . 'rel(' . $bbox . ')["landuse"~"^(industrial|commercial|retail)$"];);out tags bb;';
    }

    /** Réponse Overpass → { t, z: [ {lat,lng,rKm,nom,genre} ] }. */
    public static function analyserZoning(array $r): array
    {
        $tz = [];
        foreach ((array) ($r['elements'] ?? []) as $e) {
            if (!is_array($e)) { continue; }
            $bb = (array) ($e['bounds'] ?? []);
            if (!isset($bb['minlat'], $bb['maxlat'], $bb['minlon'], $bb['maxlon'])) { continue; }
            $t = (array) ($e['tags'] ?? []);
            $genre = (string) ($t['landuse'] ?? '');
            if ($genre === '') { continue; }
            $lat = ((float) $bb['minlat'] + (float) $bb['maxlat']) / 2;
            $lng = ((float) $bb['minlon'] + (float) $bb['maxlon']) / 2;
            // Demi-diagonale de la boîte, en mètres : le « rayon » de la zone.
            $dLat = ((float) $bb['maxlat'] - (float) $bb['minlat']) * 111000.0;
            $dLng = ((float) $bb['maxlon'] - (float) $bb['minlon']) * 111000.0 * cos($lat * M_PI / 180);
            $r2 = sqrt($dLat * $dLat + $dLng * $dLng) / 2;
            if ($r2 < self::ZONING_MIN_M) { continue; }   // parcelle isolée : pas un zoning
            $tz[] = [
                'nom'   => self::ou($t, ['name:fr', 'name']),
                'genre' => $genre,
                'lat'   => round($lat, 5), 'lng' => round($lng, 5),
                'rKm'   => round($r2 / 1000, 3),
            ];
        }
        return ['t' => (int) round(microtime(true) * 1000), 'z' => $tz];
    }

    /** Interroge Overpass pour le zoning d'un secteur. */
    public static function interrogerZoning(int $secteur): ?array
    {
        if (!isset(self::SECTEURS[$secteur])) { self::$lastError = 'secteur inconnu'; return null; }
        return self::appel(self::requeteZoning(self::SECTEURS[$secteur][0]), $secteur, 250);
    }

    /** Relit le zoning d'un secteur et le dépose sous 100 + n. */
    public static function rafraichirZoning(int $secteur): ?array
    {
        self::$lastError = null;
        $r = self::interrogerZoning($secteur);
        if ($r === null) { return null; }
        $d = self::analyserZoning($r);
        unset($r);
        // Un secteur sans aucune zone est une réponse tronquée : on garde ce
        // qu'on avait plutôt que d'écrire un vide.
        if ($d['z'] === []) { self::$lastError = 'réponse sans zone — secteur tronqué, cache conservé'; return null; }
        self::stocker(self::ZONING_BASE + $secteur, $d);
        return $d;
    }

    /** Dépose un secteur dans le cache partagé — même écriture que PUT /scouting/tiles/{n}. */
    public static function stocker(int $secteur, array $d): void
    {
        $ts = (int) round(((float) ($d['t'] ?? 0)) / 1000);
        Db::exec('INSERT INTO ceo_scouting_tile (sector, fetched_at, payload) VALUES (?,?,?)'
            . ' ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), payload = VALUES(payload)',
            [$secteur, date('Y-m-d H:i:s', $ts > 0 ? $ts : time()), json_encode($d, JSON_UNESCAPED_UNICODE)]);
    }

    /** Relit un secteur chez Overpass et le dépose ; rend le secteur, ou null (voir $lastError). */
    public static function rafraichir(int $secteur): ?array
    {
        self::$lastError = null;
        $r = self::interroger($secteur);
        if ($r === null) { return null; }
        $d = self::analyser($r);
        unset($r);
        if ($d['c'] === []) {
            // Une réponse sans aucune commune est un secteur tronqué (Overpass à
            // bout de temps) : on garde le cache en place plutôt que de l'écraser.
            self::$lastError = 'réponse sans commune — secteur tronqué, cache conservé';
            return null;
        }
        self::stocker($secteur, $d);
        return $d;
    }

    /** L'âge de chaque secteur en cache : secteur → horodatage (s), absent = jamais relu. */
    public static function ages(): array
    {
        $out = [];
        foreach (Db::rows('SELECT sector, fetched_at FROM ceo_scouting_tile') as $r) {
            $out[(int) $r['sector']] = strtotime((string) $r['fetched_at']) ?: 0;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * L'étude de marché locale d'un point : ce qu'OpenStreetMap sait du
     * rayon — entreprises par famille, zonings et ce qu'ils abritent,
     * écoles, générateurs de flux, concurrence indirecte. Une requête par
     * point, servie et mise en cache par GET /scouting/etude.
     * ------------------------------------------------------------------- */

    /** La requête du rayon d'un point : comptes par famille, puis les listes. */
    public static function requeteEtude(float $lat, float $lng, int $rM): string
    {
        $a = 'around:' . $rM . ',' . number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '');
        // Les entreprises sortent sans étiquette (« skel center ») : il en
        // faut la position pour les compter dans les zonings, pas le nom.
        // Chaque famille est précédée de son compte, ce qui permet de la
        // reconnaître dans le flot de la réponse.
        return '[out:json][timeout:120];'
            . 'nwr(' . $a . ')["shop"]->.s;.s out count;.s out skel center;'
            . 'nwr(' . $a . ')["amenity"~"^(restaurant|cafe|fast_food|bar|pub|food_court|ice_cream)$"]->.h;.h out count;.h out skel center;'
            . 'nwr(' . $a . ')["office"]->.o;.o out count;.o out skel center;'
            . 'nwr(' . $a . ')["craft"]->.c;.c out count;.c out skel center;'
            . '(nwr(' . $a . ')["industrial"];nwr(' . $a . ')["man_made"="works"];)->.i;.i out count;.i out skel center;'
            . '(way(' . $a . ')["landuse"~"^(industrial|commercial|retail)$"];rel(' . $a . ')["landuse"~"^(industrial|commercial|retail)$"];);out tags geom;'
            . 'nwr(' . $a . ')["amenity"~"^(school|college|university|kindergarten|childcare)$"];out tags center;'
            . '(nwr(' . $a . ')["amenity"~"^(hospital|clinic|nursing_home|townhall|social_facility|marketplace|fuel|cafe)$"];'
            . 'nwr(' . $a . ')["amenity"="fast_food"]["cuisine"~"sandwich|bakery|coffee|donut|bagel"];'
            . 'nwr(' . $a . ')["railway"~"^(station|halt)$"];nwr(' . $a . ')["leisure"~"^(sports_centre|stadium|swimming_pool)$"];'
            . 'nwr(' . $a . ')["shop"~"^(supermarket|convenience|mall|department_store|coffee|confectionery|deli|tea|frozen_food)$"];'
            . 'nwr(' . $a . ')["office"="government"];);out tags center;';
    }

    /** Réponse Overpass → l'étude, compacte : comptes, zonings, écoles, flux, concurrence indirecte. */
    public static function analyserEtude(array $r, float $lat0, float $lng0): array
    {
        $dist = static function (float $a, float $b, float $c, float $d): float {
            $p = M_PI / 180; $x = sin(($c - $a) * $p / 2); $y = sin(($d - $b) * $p / 2);
            return 2 * 6371 * asin(sqrt($x * $x + cos($a * $p) * cos($c * $p) * $y * $y));
        };
        $centre = static function (array $e): ?array {
            if (isset($e['lat'], $e['lon'])) { return [(float) $e['lat'], (float) $e['lon']]; }
            if (isset($e['center']['lat'], $e['center']['lon'])) { return [(float) $e['center']['lat'], (float) $e['center']['lon']]; }
            if (isset($e['bounds']['minlat'])) { $b = $e['bounds']; return [((float) $b['minlat'] + (float) $b['maxlat']) / 2, ((float) $b['minlon'] + (float) $b['maxlon']) / 2]; }
            return null;
        };
        // 1. Les familles d'entreprises, dans l'ordre de la requête : chaque
        //    « count » ouvre une famille, les éléments sans étiquette qui
        //    suivent en font partie.
        $familles = ['commerces', 'horeca', 'bureaux', 'artisans', 'industries'];
        $ent = ['commerces' => 0, 'horeca' => 0, 'bureaux' => 0, 'artisans' => 0, 'industries' => 0];
        $pts = [];   // [lat, lng] de chaque entreprise
        $k = -1;
        $zonesBrutes = []; $ecoles = []; $flux = []; $indirecte = [];
        foreach ((array) ($r['elements'] ?? []) as $e) {
            if (!is_array($e)) { continue; }
            if (($e['type'] ?? '') === 'count') {
                $k++;
                if (isset($familles[$k])) { $ent[$familles[$k]] = (int) ($e['tags']['total'] ?? 0); }
                continue;
            }
            $t = (array) ($e['tags'] ?? []);
            if ($t === []) {
                // une entreprise, sans étiquette
                if ($k >= 0 && $k < count($familles)) { $c = $centre($e); if ($c !== null) { $pts[] = $c; } }
                continue;
            }
            if (isset($t['landuse'])) { $zonesBrutes[] = $e; continue; }
            $c = $centre($e);
            if ($c === null) { continue; }
            $d = round($dist($lat0, $lng0, $c[0], $c[1]), 2);
            $nom = self::ou($t, ['name:fr', 'name', 'brand', 'operator']);
            $am = (string) ($t['amenity'] ?? '');
            if (in_array($am, ['school', 'college', 'university', 'kindergarten', 'childcare'], true)) {
                $isced = (string) ($t['isced:level'] ?? '');
                if ($am === 'kindergarten') { $genre = 'école maternelle'; }
                elseif ($am === 'childcare') { $genre = 'crèche'; }
                elseif ($am === 'college' || $am === 'university') { $genre = 'enseignement supérieur'; }
                elseif ($isced !== '') {
                    $niv = [];
                    if (preg_match('/\b0\b/', $isced)) { $niv[] = 'maternelle'; }
                    if (preg_match('/\b1\b/', $isced)) { $niv[] = 'primaire'; }
                    if (preg_match('/\b[23]\b/', $isced)) { $niv[] = 'secondaire'; }
                    if (preg_match('/\b[4-8]\b/', $isced)) { $niv[] = 'supérieur'; }
                    $genre = $niv === [] ? 'école' : 'école ' . implode(' et ', array_unique($niv));
                } else { $genre = 'école'; }
                $ecoles[] = ['nom' => $nom !== '' ? $nom : ucfirst($genre) . ' sans nom', 'genre' => $genre, 'lat' => round($c[0], 5), 'lng' => round($c[1], 5), 'dKm' => $d,
                    'eleves' => (int) ($t['capacity'] ?? 0)];
                continue;
            }
            $shop = (string) ($t['shop'] ?? '');
            $indir = [
                'supermarket' => 'supermarché', 'convenience' => 'supérette', 'mall' => 'centre commercial', 'department_store' => 'grand magasin',
                'coffee' => 'torréfacteur / café', 'confectionery' => 'confiserie', 'deli' => 'épicerie fine', 'tea' => 'salon de thé', 'frozen_food' => 'surgelés',
            ];
            if ($shop !== '' && isset($indir[$shop])) {
                $indirecte[] = ['nom' => $nom !== '' ? $nom : ucfirst($indir[$shop]), 'genre' => $indir[$shop], 'lat' => round($c[0], 5), 'lng' => round($c[1], 5), 'dKm' => $d];
                continue;
            }
            if ($am === 'cafe' || $am === 'fast_food') {
                $cui = (string) ($t['cuisine'] ?? '');
                $genre = $am === 'cafe' ? (preg_match('/coffee/', $cui) ? 'coffee shop' : 'café / salon de thé') : (preg_match('/sandwich/', $cui) ? 'sandwicherie' : 'snack');
                $indirecte[] = ['nom' => $nom !== '' ? $nom : ucfirst($genre), 'genre' => $genre, 'lat' => round($c[0], 5), 'lng' => round($c[1], 5), 'dKm' => $d];
                continue;
            }
            $genre = '';
            if ($am === 'hospital') { $genre = 'hôpital'; }
            elseif ($am === 'clinic') { $genre = 'clinique / polyclinique'; }
            elseif ($am === 'nursing_home') { $genre = 'maison de repos'; }
            elseif ($am === 'social_facility') {
                $sf = (string) ($t['social_facility'] ?? '');
                $genre = preg_match('/nursing|assisted|group_home|senior/', $sf . ' ' . (string) ($t['social_facility:for'] ?? '')) ? 'maison de repos' : 'service social';
            }
            elseif ($am === 'townhall') { $genre = 'maison communale'; }
            elseif ($am === 'marketplace') { $genre = 'marché'; }
            elseif ($am === 'fuel') { $genre = 'station-service'; }
            elseif (isset($t['railway'])) { $genre = $t['railway'] === 'station' ? 'gare' : 'arrêt de train'; }
            elseif (isset($t['leisure'])) { $genre = ['sports_centre' => 'centre sportif', 'stadium' => 'stade', 'swimming_pool' => 'piscine'][$t['leisure']] ?? 'sport'; }
            elseif (($t['office'] ?? '') === 'government') { $genre = 'administration'; }
            if ($genre === '') { continue; }
            $flux[] = ['nom' => $nom !== '' ? $nom : ucfirst($genre), 'genre' => $genre, 'lat' => round($c[0], 5), 'lng' => round($c[1], 5), 'dKm' => $d];
        }
        // 2. Les zonings : chaque surface, son emprise (hectares), et les
        //    entreprises dont le point tombe dedans. Les morceaux qui portent
        //    le même nom se rassemblent — un parc d'activité est souvent
        //    cartographié parcelle par parcelle.
        $dansPoly = static function (float $y, float $x, array $poly): bool {
            $in = false; $n = count($poly);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $yi = $poly[$i][0]; $xi = $poly[$i][1]; $yj = $poly[$j][0]; $xj = $poly[$j][1];
                if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) { $in = !$in; }
            }
            return $in;
        };
        $aireHa = static function (array $poly, float $latRef): float {
            $kx = 111.32 * cos($latRef * M_PI / 180); $ky = 110.57; $a = 0.0; $n = count($poly);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) { $a += ($poly[$j][1] * $kx) * ($poly[$i][0] * $ky) - ($poly[$i][1] * $kx) * ($poly[$j][0] * $ky); }
            return abs($a) / 2 * 100;   // km² → ha
        };
        $genres = ['industrial' => 'industriel', 'commercial' => 'commercial', 'retail' => 'commerces'];
        $zones = [];
        foreach ($zonesBrutes as $e) {
            $t = (array) ($e['tags'] ?? []);
            $poly = [];
            if (($e['type'] ?? '') === 'way' && isset($e['geometry']) && is_array($e['geometry'])) {
                foreach ($e['geometry'] as $g) { if (isset($g['lat'], $g['lon'])) { $poly[] = [(float) $g['lat'], (float) $g['lon']]; } }
            } elseif (isset($e['bounds']['minlat'])) {
                // relation : l'anneau extérieur est fait de plusieurs chemins ;
                // on prend la boîte englobante, et on le dit
                $b = $e['bounds'];
                $poly = [[(float) $b['minlat'], (float) $b['minlon']], [(float) $b['minlat'], (float) $b['maxlon']], [(float) $b['maxlat'], (float) $b['maxlon']], [(float) $b['maxlat'], (float) $b['minlon']]];
            }
            if (count($poly) < 3) { continue; }
            $la = 0.0; $lo = 0.0;
            foreach ($poly as $p) { $la += $p[0]; $lo += $p[1]; }
            $la /= count($poly); $lo /= count($poly);
            $ha = $aireHa($poly, $la);
            if ($ha < 0.5) { continue; }   // une parcelle, pas un zoning
            $n = 0;
            foreach ($pts as $p) { if ($dansPoly($p[0], $p[1], $poly)) { $n++; } }
            $nom = self::ou($t, ['name:fr', 'name']);
            $genre = $genres[(string) ($t['landuse'] ?? '')] ?? 'activité';
            $cle = $nom !== '' ? mb_strtolower($nom) : 'z' . count($zones);
            if (isset($zones[$cle])) {
                $z = &$zones[$cle];
                $z['lat'] = ($z['lat'] * $z['ha'] + $la * $ha) / ($z['ha'] + $ha);
                $z['lng'] = ($z['lng'] * $z['ha'] + $lo * $ha) / ($z['ha'] + $ha);
                $z['ha'] += $ha; $z['n'] += $n; $z['morceaux']++;
                if ($z['genre'] !== $genre) { $z['genre'] = 'mixte'; }
                unset($z);
            } else {
                $zones[$cle] = ['nom' => $nom, 'genre' => $genre, 'lat' => $la, 'lng' => $lo, 'ha' => $ha, 'n' => $n, 'morceaux' => 1,
                    'approx' => ($e['type'] ?? '') !== 'way'];
            }
        }
        $zonings = [];
        foreach ($zones as $z) {
            $zonings[] = ['nom' => $z['nom'], 'genre' => $z['genre'], 'lat' => round($z['lat'], 5), 'lng' => round($z['lng'], 5),
                'ha' => round($z['ha'], 1), 'n' => $z['n'], 'morceaux' => $z['morceaux'], 'approx' => $z['approx'],
                'dKm' => round($dist($lat0, $lng0, $z['lat'], $z['lng']), 2)];
        }
        // Les zonings qui comptent d'abord (nommés, ou d'au moins 2 ha, ou
        // abritant au moins 3 entreprises), du plus près au plus loin ; les
        // petites parcelles restantes se résument en une ligne.
        $tri = static fn ($a, $b) => $a['dKm'] <=> $b['dKm'];
        usort($zonings, $tri);
        $gros = array_values(array_filter($zonings, static fn ($z) => $z['nom'] !== '' || $z['ha'] >= 2 || $z['n'] >= 3));
        $petits = array_values(array_filter($zonings, static fn ($z) => !($z['nom'] !== '' || $z['ha'] >= 2 || $z['n'] >= 3)));
        $zonings = array_slice($gros, 0, 25);
        if ($petits !== []) {
            $ha = 0.0; $n = 0; $dmin = 99.0;
            foreach ($petits as $z) { $ha += $z['ha']; $n += $z['n']; $dmin = min($dmin, $z['dKm']); }
            $zonings[] = ['nom' => count($petits) . ' petites zones sans nom (moins de 2 ha)', 'genre' => 'parcelles', 'lat' => $lat0, 'lng' => $lng0,
                'ha' => round($ha, 1), 'n' => $n, 'morceaux' => count($petits), 'approx' => false, 'dKm' => round($dmin, 2), 'reste' => true];
        }
        // Un même lieu est souvent cartographié deux fois (le bâtiment et le
        // point) : même nom à moins de 250 m, on ne garde que le premier.
        $dedoublonne = static function (array $l) use ($dist): array {
            usort($l, static fn ($a, $b) => $a['dKm'] <=> $b['dKm']);
            $out = [];
            foreach ($l as $e) {
                $nom = mb_strtolower($e['nom']);
                foreach ($out as $o) {
                    if (mb_strtolower($o['nom']) === $nom && $dist($o['lat'], $o['lng'], $e['lat'], $e['lng']) < 0.25) { continue 2; }
                }
                $out[] = $e;
            }
            return $out;
        };
        $ecoles = $dedoublonne($ecoles); $flux = $dedoublonne($flux); $indirecte = $dedoublonne($indirecte);
        $ent['total'] = array_sum($ent);
        $ent['zonings'] = 0;
        foreach ($zonings as $z) { $ent['zonings'] += $z['n']; }
        return [
            't' => (int) round(microtime(true) * 1000),
            'osm' => (string) ($r['osm3s']['timestamp_osm_base'] ?? ''),
            'ent' => $ent, 'zonings' => $zonings,
            'ecoles' => array_slice($ecoles, 0, 80), 'flux' => array_slice($flux, 0, 80), 'indirecte' => array_slice($indirecte, 0, 60),
        ];
    }

    /* ---------------------------------------------------------------------
     * Le démarchage : les lieux qui rassemblent du monde autour d'un magasin
     * — entreprises, écoles, santé, administrations, formation, funéraire,
     * sport, hôtels — avec leur adresse, leur téléphone et ce qu'on sait de
     * leur taille. Servi par GET /scouting/demarchage.
     * ------------------------------------------------------------------- */

    /** La requête du rayon : chaque lieu avec ses étiquettes, plus les zonings pour dire qui est dedans. */
    public static function requeteDemarchage(float $lat, float $lng, int $rM): string
    {
        $a = 'around:' . $rM . ',' . number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '');
        return '[out:json][timeout:150];('
            . 'nwr(' . $a . ')["office"];'
            . 'nwr(' . $a . ')["craft"];'
            . 'nwr(' . $a . ')["industrial"];nwr(' . $a . ')["man_made"="works"];'
            . 'nwr(' . $a . ')["amenity"~"^(school|college|university|kindergarten|childcare|hospital|clinic|nursing_home|social_facility|townhall|police|fire_station|courthouse|post_office|community_centre|library|conference_centre|events_venue|funeral_hall|crematorium|training|bank|prison|research_institute|coworking_space)$"];'
            . 'nwr(' . $a . ')["shop"~"^(funeral_directors|supermarket|car|department_store|mall|garden_centre|doityourself|furniture|wholesale)$"];'
            . 'nwr(' . $a . ')["leisure"~"^(sports_centre|fitness_centre|stadium|swimming_pool)$"];'
            . 'nwr(' . $a . ')["tourism"~"^(hotel|hostel)$"];'
            . 'nwr(' . $a . ')["healthcare"~"^(hospital|clinic|centre|rehabilitation)$"];'
            . ');out tags center;'
            . '(way(' . $a . ')["landuse"~"^(industrial|commercial|retail)$"];rel(' . $a . ')["landuse"~"^(industrial|commercial|retail)$"];);out tags geom;'
            . 'nwr(' . $a . ')["shop"]["name"]->.z;.z out tags center;';
    }

    /** Réponse Overpass → la liste des prospects, typée, adressée, et ce qu'on sait de leur taille. */
    public static function analyserDemarchage(array $r, float $lat0, float $lng0): array
    {
        $dist = static function (float $a, float $b, float $c, float $d): float {
            $p = M_PI / 180; $x = sin(($c - $a) * $p / 2); $y = sin(($d - $b) * $p / 2);
            return 2 * 6371 * asin(sqrt($x * $x + cos($a * $p) * cos($c * $p) * $y * $y));
        };
        $centre = static function (array $e): ?array {
            if (isset($e['lat'], $e['lon'])) { return [(float) $e['lat'], (float) $e['lon']]; }
            if (isset($e['center']['lat'], $e['center']['lon'])) { return [(float) $e['center']['lat'], (float) $e['center']['lon']]; }
            if (isset($e['bounds']['minlat'])) { $b = $e['bounds']; return [((float) $b['minlat'] + (float) $b['maxlat']) / 2, ((float) $b['minlon'] + (float) $b['maxlon']) / 2]; }
            return null;
        };
        $dansPoly = static function (float $y, float $x, array $poly): bool {
            $in = false; $n = count($poly);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                $yi = $poly[$i][0]; $xi = $poly[$i][1]; $yj = $poly[$j][0]; $xj = $poly[$j][1];
                if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) { $in = !$in; }
            }
            return $in;
        };
        // 1. les zonings, pour dire dans lequel un lieu se trouve
        $zones = [];
        foreach ((array) ($r['elements'] ?? []) as $e) {
            if (!is_array($e) || !isset($e['tags']['landuse'])) { continue; }
            $poly = [];
            if (($e['type'] ?? '') === 'way' && isset($e['geometry']) && is_array($e['geometry'])) {
                foreach ($e['geometry'] as $g) { if (isset($g['lat'], $g['lon'])) { $poly[] = [(float) $g['lat'], (float) $g['lon']]; } }
            } elseif (isset($e['bounds']['minlat'])) {
                $b = $e['bounds'];
                $poly = [[(float) $b['minlat'], (float) $b['minlon']], [(float) $b['minlat'], (float) $b['maxlon']], [(float) $b['maxlat'], (float) $b['maxlon']], [(float) $b['maxlat'], (float) $b['minlon']]];
            }
            if (count($poly) < 3) { continue; }
            $zones[] = ['nom' => self::ou((array) $e['tags'], ['name:fr', 'name']), 'genre' => (string) $e['tags']['landuse'], 'poly' => $poly];
        }
        $zoningDe = static function (float $la, float $lo) use ($zones, $dansPoly): ?string {
            foreach ($zones as $z) { if ($dansPoly($la, $lo, $z['poly'])) { return $z['nom'] !== '' ? $z['nom'] : ('zone ' . ($z['genre'] === 'industrial' ? 'industrielle' : ($z['genre'] === 'retail' ? 'de commerces' : 'commerciale'))); } }
            return null;
        };
        // 2. chaque lieu : sa famille, son genre, son adresse, sa taille
        $genresOffice = ['company' => 'entreprise', 'it' => 'informatique', 'insurance' => 'assurances', 'lawyer' => 'avocats', 'accountant' => 'comptabilité', 'estate_agent' => 'immobilier',
            'architect' => 'architectes', 'engineer' => 'bureau d’études', 'consulting' => 'conseil', 'financial' => 'finance', 'government' => 'administration', 'association' => 'association',
            'ngo' => 'ONG', 'notary' => 'notaire', 'telecommunication' => 'télécoms', 'employment_agency' => 'intérim', 'advertising_agency' => 'publicité', 'educational_institution' => 'centre de formation',
            'research' => 'recherche', 'logistics' => 'logistique', 'transport' => 'transport', 'construction_company' => 'construction', 'energy_supplier' => 'énergie', 'coworking' => 'coworking',
            'newspaper' => 'presse', 'travel_agent' => 'agence de voyages', 'tax_advisor' => 'fiscaliste', 'water_utility' => 'eaux', 'political_party' => 'parti politique', 'religion' => 'religieux'];
        $out = [];
        foreach ((array) ($r['elements'] ?? []) as $e) {
            if (!is_array($e) || ($e['type'] ?? '') === 'count') { continue; }
            $t = (array) ($e['tags'] ?? []);
            if ($t === [] || isset($t['landuse'])) { continue; }
            $c = $centre($e);
            if ($c === null) { continue; }
            $am = (string) ($t['amenity'] ?? ''); $of = (string) ($t['office'] ?? ''); $cr = (string) ($t['craft'] ?? '');
            $sh = (string) ($t['shop'] ?? ''); $le = (string) ($t['leisure'] ?? ''); $to = (string) ($t['tourism'] ?? ''); $hc = (string) ($t['healthcare'] ?? '');
            $famille = ''; $genre = ''; $grand = null;   // grand : vraisemblablement 20 personnes et plus
            if (in_array($am, ['school', 'college', 'university', 'kindergarten', 'childcare'], true)) {
                $famille = 'ecoles'; $grand = $am !== 'childcare';
                $genre = ['school' => 'école', 'college' => 'haute école / collège', 'university' => 'université', 'kindergarten' => 'école maternelle', 'childcare' => 'crèche'][$am];
            } elseif ($am === 'training' || $of === 'educational_institution') { $famille = 'formation'; $genre = 'centre de formation'; $grand = true; }
            elseif (in_array($am, ['hospital', 'clinic', 'nursing_home', 'social_facility'], true) || $hc !== '') {
                $famille = 'sante'; $grand = $am !== 'social_facility' || preg_match('/nursing|assisted|group_home|senior/', (string) ($t['social_facility'] ?? '') . ' ' . (string) ($t['social_facility:for'] ?? '')) === 1;
                $genre = $am === 'hospital' || $hc === 'hospital' ? 'hôpital' : ($am === 'clinic' || $hc === 'clinic' ? 'clinique / polyclinique' : ($am === 'nursing_home' || $grand && $am === 'social_facility' ? 'maison de repos' : ($hc !== '' ? 'centre de soins' : 'service social')));
            } elseif ($am === 'funeral_hall' || $am === 'crematorium' || $sh === 'funeral_directors') { $famille = 'funeraire'; $genre = $am === 'crematorium' ? 'crématorium' : ($am === 'funeral_hall' ? 'funérarium' : 'pompes funèbres'); $grand = true; }
            elseif (in_array($am, ['townhall', 'police', 'fire_station', 'courthouse', 'post_office', 'community_centre', 'library', 'prison'], true) || $of === 'government') {
                $famille = 'administration'; $grand = $am !== 'post_office' && $am !== 'library';
                $genre = ['townhall' => 'maison communale', 'police' => 'police', 'fire_station' => 'pompiers', 'courthouse' => 'justice', 'post_office' => 'poste', 'community_centre' => 'centre communautaire', 'library' => 'bibliothèque', 'prison' => 'prison'][$am] ?? 'administration';
            } elseif (in_array($am, ['conference_centre', 'events_venue'], true) || $to !== '') { $famille = 'evenements'; $genre = $to !== '' ? ($to === 'hotel' ? 'hôtel' : 'auberge') : ($am === 'conference_centre' ? 'centre de conférences' : 'salle d’événements'); $grand = true; }
            elseif ($le !== '') { $famille = 'sport'; $genre = ['sports_centre' => 'centre sportif', 'fitness_centre' => 'salle de fitness', 'stadium' => 'stade', 'swimming_pool' => 'piscine'][$le] ?? 'sport'; $grand = $le !== 'fitness_centre'; }
            elseif (isset($t['industrial']) || ($t['man_made'] ?? '') === 'works') { $famille = 'industrie'; $genre = 'site industriel'; $grand = true; }
            elseif ($am === 'bank' || $of !== '') { $famille = 'bureaux'; $genre = $am === 'bank' ? 'banque' : ($genresOffice[$of] ?? ('bureau · ' . str_replace('_', ' ', $of))); $grand = in_array($of, ['company', 'government', 'insurance', 'telecommunication', 'logistics', 'construction_company', 'energy_supplier', 'research', 'employment_agency'], true) || $am === 'bank' ? null : false; }
            elseif ($am === 'research_institute' || $am === 'coworking_space') { $famille = 'bureaux'; $genre = $am === 'research_institute' ? 'institut de recherche' : 'coworking'; $grand = true; }
            elseif ($cr !== '') { $famille = 'artisans'; $genre = 'artisan · ' . str_replace('_', ' ', $cr); $grand = false; }
            elseif ($sh !== '') {
                // les commerces : seuls les grands, ou ceux qui sont dans un zoning, valent un démarchage
                $grands = ['supermarket' => 'supermarché', 'car' => 'concession automobile', 'department_store' => 'grand magasin', 'mall' => 'centre commercial', 'garden_centre' => 'jardinerie', 'doityourself' => 'bricolage', 'furniture' => 'meubles', 'wholesale' => 'grossiste'];
                $zn = $zoningDe($c[0], $c[1]);
                if (isset($grands[$sh])) { $famille = 'commerces'; $genre = $grands[$sh]; $grand = true; }
                elseif ($zn !== null) { $famille = 'commerces'; $genre = 'commerce en zoning · ' . str_replace('_', ' ', $sh); $grand = null; }
                else { continue; }
            } else { continue; }
            $nom = self::ou($t, ['name:fr', 'name', 'brand', 'operator']);
            if ($nom === '' && !in_array($famille, ['ecoles', 'sante', 'administration', 'industrie', 'funeraire'], true)) { continue; }   // un bureau sans nom ne se démarche pas
            // la taille : ce que la carte dit (employés, élèves, lits, capacité), sinon la vraisemblance du genre
            $pers = null; $persDe = '';
            foreach (['employees' => 'employés', 'capacity' => 'places', 'beds' => 'lits', 'capacity:persons' => 'personnes', 'students' => 'élèves'] as $k => $lib) {
                if (isset($t[$k]) && is_numeric($t[$k]) && (int) $t[$k] > 0) { $pers = (int) $t[$k]; $persDe = $lib; break; }
            }
            if ($pers !== null) { $grand = $pers >= 20; }
            $rue = trim((string) ($t['addr:street'] ?? '')); $num = trim((string) ($t['addr:housenumber'] ?? ''));
            $cp = trim((string) ($t['addr:postcode'] ?? '')); $ville = trim((string) ($t['addr:city'] ?? ''));
            $adresse = trim(trim($rue . ' ' . $num) . ($cp !== '' || $ville !== '' ? ', ' . trim($cp . ' ' . $ville) : ''));
            $tel = self::ou($t, ['contact:phone', 'phone', 'contact:mobile']);
            $site = self::ou($t, ['contact:website', 'website', 'url']);
            $mail = self::ou($t, ['contact:email', 'email']);
            $out[] = [
                'id' => substr((string) ($e['type'] ?? 'n'), 0, 1) . (int) ($e['id'] ?? 0),
                'nom' => $nom !== '' ? $nom : ucfirst($genre) . ' sans nom',
                'famille' => $famille, 'genre' => $genre,
                'adresse' => $adresse, 'commune' => $ville, 'tel' => $tel, 'site' => $site, 'mail' => $mail,
                'lat' => round($c[0], 5), 'lng' => round($c[1], 5), 'dKm' => round($dist($lat0, $lng0, $c[0], $c[1]), 2),
                'zoning' => $zoningDe($c[0], $c[1]),
                'grand' => $grand, 'personnes' => $pers, 'personnesDe' => $persDe,
            ];
        }
        // un même lieu cartographié deux fois (le bâtiment et le point) : même nom à moins de 150 m, on garde le premier
        usort($out, static fn ($a, $b) => $a['dKm'] <=> $b['dKm']);
        $uniq = [];
        foreach ($out as $e) {
            $n = mb_strtolower($e['nom']);
            foreach ($uniq as $o) { if (mb_strtolower($o['nom']) === $n && $dist($o['lat'], $o['lng'], $e['lat'], $e['lng']) < 0.15) { continue 2; } }
            $uniq[] = $e;
        }
        $parFamille = [];
        foreach ($uniq as $e) { $parFamille[$e['famille']] = ($parFamille[$e['famille']] ?? 0) + 1; }
        return [
            't' => (int) round(microtime(true) * 1000),
            'osm' => (string) ($r['osm3s']['timestamp_osm_base'] ?? ''),
            'n' => count($uniq), 'parFamille' => $parFamille, 'zonings' => count($zones),
            'lieux' => array_slice($uniq, 0, 600),
        ];
    }

    /** Le démarchage d'un point : Overpass, puis l'analyse ; null si aucun miroir ne répond. */
    public static function demarchage(float $lat, float $lng, int $rM): ?array
    {
        self::$lastError = null;
        $r = self::appel(self::requeteDemarchage($lat, $lng, $rM), (int) abs(round($lat * 1000 + $lng * 1000)) + 7, 90);
        if ($r === null) { return null; }
        $d = self::analyserDemarchage($r, $lat, $lng);
        unset($r);
        $d['r'] = $rM;
        return $d;
    }

    /** L'étude d'un point : Overpass, puis l'analyse ; null si aucun miroir ne répond (voir $lastError). */
    public static function etude(float $lat, float $lng, int $rM): ?array
    {
        self::$lastError = null;
        // 90 s par miroir : un miroir qui traîne cède la place au suivant, et
        // l'appel HTTP (200 s au plus) rend la main avant de mourir.
        $r = self::appel(self::requeteEtude($lat, $lng, $rM), (int) abs(round($lat * 1000 + $lng * 1000)), 90);
        if ($r === null) { return null; }
        $d = self::analyserEtude($r, $lat, $lng);
        unset($r);
        $d['r'] = $rM;
        return $d;
    }
}
