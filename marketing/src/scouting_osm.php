<?php
declare(strict_types=1);

/**
 * Les lieux à démarcher autour d'un magasin, relevés dans OpenStreetMap
 * (GET /scouting/demarchage : Développement commercial, Prospection).
 *
 * Ce fichier portait tout le cache OpenStreetMap du Scouting commercial
 * (secteurs, zoning d'activité, étude locale, relecture hebdomadaire). L'écran
 * et sa relecture ont quitté le cockpit le 31/10/2026 pour le CRM (Étude de
 * faisabilité, sa propre copie de cette classe, même base) : il ne reste ici
 * que le démarchage — la requête, l'analyse, et l'appel aux miroirs Overpass.
 */
final class ScoutingOsm
{
    public static ?string $lastError = null;

    private const MIROIRS = [
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

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
}
