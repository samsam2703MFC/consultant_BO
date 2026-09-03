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
        51 => 'Ath', 52 => 'Charleroi', 53 => 'Mons', 54 => 'Mouscron', 55 => 'Soignies', 56 => 'Thuin', 57 => 'Tournai',
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
        '51' => 'WHT', '52' => 'WHT', '53' => 'WHT', '54' => 'WHT', '55' => 'WHT', '56' => 'WHT', '57' => 'WHT',
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
        $q = self::requete(self::SECTEURS[$secteur][0]);
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
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 250, CURLOPT_CONNECTTIMEOUT => 15,
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
}
