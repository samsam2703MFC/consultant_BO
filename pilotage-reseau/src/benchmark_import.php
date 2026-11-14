<?php
declare(strict_types=1);

/**
 * Benchmark › Import, Classement, Zones (14/11/2026, demande de Sam : « code le mode import PDF, soit import soit
 * recherche sur internet via IA, dans le rail Benchmark, et ensuite classé quel concurrent et sur quel franchisé,
 * en fonction des codes postaux » ; puis « rajouter dans Concurrence les codes postaux avec les concurrents »).
 * Maquette : docs/maquettes/benchmark-import-pdf.
 *
 *  1. Lire : un PDF du concurrent (tarif, folder, carte des fêtes, scan) ou une recherche internet. Le modèle relève
 *     ce qui est écrit (src/anthropic.php, releveDepuisPdf / releveDepuisWeb), rien d'autre.
 *  2. Rapprocher, ici et sans IA : chaque ligne face au catalogue du magasin (GET /shops/{id}/products/available),
 *     la mémoire des correspondances confirmées d'abord, les mots du libellé ensuite ; les deux prix ramenés sur une
 *     même base (au kilo, à surface égale, à la pièce, à la part).
 *  3. Classer par code postal : un concurrent va aux magasins dont la zone couvre son code postal. Une zone est une
 *     liste de plages {de, a, shopId, libelle}, le format des plages de MétaDonnée (le magasin d'un lead par son code
 *     postal) ; par défaut, les codes postaux à moins de 10 km de la boutique.
 *  4. Encoder, au clic seulement : concurrent, observation datée, items rattachés à nos produits, dans le module
 *     Benchmark de TFBuddy (src/benchmark.php, BenchmarkApi), pour chaque magasin retenu ; chaque écriture relue.
 *
 * Tables : ceo_bench_import (un relevé lu, ses lignes et les choix), ceo_bench_correspondance (la mémoire
 * libellé du concurrent → notre produit). Le PDF reste dans le dossier privé du serveur, jamais servi sur le web.
 */

/**
 * Les codes postaux à moins de 10 km de chaque boutique, clé = le code postal de la boutique (localités bpost,
 * coordonnées OpenStreetMap, calculé le 14/11/2026). Le point de départ des zones ; elles se règlent dans
 * Benchmark › Zones.
 */
const BI_ZONES_DEFAUT = [
    '5140' => ['5140' => 'Sombreffe (Ligny, Tongrinne, Boignée)', '6222' => 'Brye', '5032' => 'Bothey', '5190' => 'Jemeppe-sur-Sambre (Balâtre)',
        '6221' => 'Saint-Amand', '6223' => 'Wagnelée', '1495' => 'Villers-la-Ville (Marbais)', '6224' => 'Wanfercée-Baulet', '1450' => 'Chastre (Saint-Géry)',
        '5060' => 'Sambreville (Velaine)', '5030' => 'Gembloux', '6220' => 'Fleurus', '6210' => 'Les Bons Villers', '6211' => 'Mellet'],
    '1435' => ['1435' => 'Mont-Saint-Guibert (Corbais)', '1457' => 'Walhain (Nil-Saint-Vincent)', '1325' => 'Chaumont-Gistoux (Corroy-le-Grand)',
        '1348' => 'Louvain-la-Neuve', '1450' => 'Chastre', '1340' => 'Ottignies', '1490' => 'Court-Saint-Étienne', '5030' => 'Gembloux (Ernage)',
        '1300' => 'Wavre (Limal)', '1342' => 'Limelette', '1301' => 'Bierges', '1341' => 'Céroux-Mousty', '1360' => 'Perwez (Orbais)',
        '1470' => 'Genappe (Bousval)', '1495' => 'Villers-la-Ville (Mellery)', '1390' => 'Grez-Doiceau (Biez)'],
    '6041' => ['6041' => 'Gosselies', '6230' => 'Pont-à-Celles (Thiméon)', '6040' => 'Jumet', '6043' => 'Ransart', '6044' => 'Roux', '6180' => 'Courcelles',
        '6042' => 'Lodelinsart', '6210' => 'Les Bons Villers (Wayaux)', '6220' => 'Fleurus (Heppignies)', '6020' => 'Dampremy', '6211' => 'Mellet',
        '6238' => 'Liberchies', '6000' => 'Charleroi', '6060' => 'Gilly', '6182' => 'Souvret', '6031' => 'Monceau-sur-Sambre',
        '6061' => 'Montignies-sur-Sambre', '6030' => 'Marchienne-au-Pont', '6183' => 'Trazegnies', '6001' => 'Marcinelle', '6181' => 'Gouy-lez-Piéton',
        '6240' => 'Farciennes (Pironchamps)', '6200' => 'Châtelet', '6141' => 'Forchies-la-Marche', '6032' => 'Mont-sur-Marchienne', '6010' => 'Couillet',
        '6221' => 'Saint-Amand', '6223' => 'Wagnelée', '6140' => 'Fontaine-l’Évêque', '7160' => 'Chapelle-lez-Herlaimont (Piéton)', '6250' => 'Aiseau-Presles'],
    '1500' => ['1500' => 'Halle', '1501' => 'Buizingen', '1654' => 'Huizingen', '1502' => 'Lembeek', '1673' => 'Beert', '1653' => 'Dworp', '1651' => 'Lot',
        '1670' => 'Pepingen', '1600' => 'Sint-Pieters-Leeuw', '1480' => 'Tubize', '1674' => 'Bellingen', '1650' => 'Beersel', '1652' => 'Alsemberg',
        '1440' => 'Braine-le-Château', '1671' => 'Elingen', '1601' => 'Ruisbroek', '1640' => 'Rhode-Saint-Genèse', '1602' => 'Vlezenbeek',
        '1750' => 'Lennik (Gaasbeek)', '1630' => 'Linkebeek', '1620' => 'Drogenbos', '1755' => 'Gooik (Kester)', '1430' => 'Rebecq (Quenast)',
        '1420' => 'Braine-l’Alleud', '1190' => 'Forest', '1540' => 'Herne (Herfelingen)'],
];

/** Le code postal des villes des concurrents relevés par le cockpit (src/data/benchmark_*.json), qui n'en portent pas. */
const BI_CP_VILLES = ['andenne' => '5300', 'anderlecht' => '1070', 'braine le chateau' => '1440', 'bruxelles' => '1000', 'charleroi' => '6000',
    'ciney' => '5590', 'dilbeek' => '1700', 'fernelmont' => '5380', 'gembloux' => '5030', 'genappe' => '1470', 'gilly' => '6060', 'gosselies' => '6041',
    'gouy lez pieton' => '6181', 'lasne' => '1380', 'marche en famenne' => '6900', 'mont saint guibert' => '1435', 'namur' => '5000', 'bouge' => '5004',
    'ronquieres' => '7090', 'saint gilles' => '1060', 'wavre' => '1300', 'halle' => '1500', 'tongrinne' => '5140', 'sombreffe' => '5140', 'corbais' => '1435'];

const BI_MAX_PDF = 15 * 1024 * 1024;

/* =================================================================================
   1. TABLES, DOSSIER, MAGASINS
   ================================================================================= */

function biTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_bench_import ('
        . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'cree_le DATETIME NOT NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . "source VARCHAR(8) NOT NULL,"                 // pdf | web
        . "statut VARCHAR(12) NOT NULL DEFAULT 'lu',"    // lu | encode
        . 'titre VARCHAR(190) NOT NULL,'
        . 'fichier VARCHAR(80) NULL,'                    // nom sur disque, tiré au hasard
        . 'nom_origine VARCHAR(190) NULL,'
        . 'octets INT NOT NULL DEFAULT 0,'
        . 'empreinte CHAR(64) NULL,'
        . 'demande VARCHAR(500) NULL,'
        . 'concurrent TEXT NOT NULL,'
        . 'magasins VARCHAR(200) NOT NULL,'
        . 'date_releve DATE NULL,'
        . 'lignes MEDIUMTEXT NOT NULL,'
        . 'choix MEDIUMTEXT NOT NULL,'
        . 'sources TEXT NULL,'
        . 'remarques TEXT NULL,'
        . 'modele VARCHAR(60) NULL,'
        . 'jetons INT NOT NULL DEFAULT 0,'
        . 'encodage MEDIUMTEXT NULL,'
        . 'KEY idx_bi_cree (cree_le), KEY idx_bi_empreinte (empreinte)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_bench_correspondance ('
        . 'concurrent VARCHAR(120) NOT NULL,'            // nom normalisé du concurrent, « * » pour tout le réseau
        . 'libelle VARCHAR(190) NOT NULL,'               // libellé normalisé de la ligne
        . 'product_id INT NOT NULL,'
        . 'n INT NOT NULL DEFAULT 1,'
        . 'maj_le DATETIME NOT NULL,'
        . 'PRIMARY KEY (concurrent, libelle)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le dossier privé des PDF : hors du site web, que la livraison ne touche pas. */
function biDossier(): string
{
    static $d = null;
    if ($d !== null) { return $d; }
    $cfg = method_exists('Db', 'config') ? (string) (Db::config()['prive'] ?? '') : '';
    foreach (array_filter([$cfg, getenv('COCKPIT_PRIVE') ?: '', '/var/lib/consulant_bo/prive', dirname(__DIR__) . '/var/prive']) as $c) {
        $x = rtrim($c, '/') . '/benchmark';
        if ((is_dir($x) || @mkdir($x, 0700, true)) && is_writable($x)) { return $d = $x; }
    }
    throw new RuntimeException('aucun dossier privé où ranger le PDF');
}

/** La taille la plus grande d'un PDF : la nôtre, celle de PHP (le PDF voyage en base64 dans du JSON, +37 %). */
function biMaxOctets(): int
{
    $o = static function (string $v): int {
        $v = trim($v); if ($v === '' || $v === '0' || $v === '-1') { return PHP_INT_MAX; }
        $n = (int) $v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
    };
    return (int) min(BI_MAX_PDF, intdiv($o((string) ini_get('post_max_size')) * 10, 14));
}

/** Un code postal belge (4 chiffres, 1000 à 9999) tiré d'un texte, ou null. */
function biCp(?string $s): ?string
{
    if ($s === null || !preg_match('/(?<![\d])([1-9]\d{3})(?![\d])/', $s, $m)) { return null; }
    return $m[1];
}

/**
 * Les magasins du benchmark, avec leur franchisé et leur code postal :
 * [id => {id, nom, franchise, cp, ville}]. Le nom vient du réglage du benchmark ; le franchisé et le code postal de la
 * table des boutiques (ep_stores), sinon de /shops du panel.
 */
function biMagasins(): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $out = [];
    foreach (BenchmarkApi::magasins() as $id => $nom) { $out[(int) $id] = ['id' => (int) $id, 'nom' => (string) $nom, 'franchise' => '', 'cp' => '', 'ville' => (string) $nom]; }
    if (function_exists('ep_stores')) {
        try {
            foreach (ep_stores() as $s) {
                $id = (int) ($s['id'] ?? 0);
                if (!isset($out[$id])) { continue; }
                $out[$id]['franchise'] = trim((string) ($s['fr'] ?? ''));
                $out[$id]['cp'] = (string) (biCp((string) ($s['cp'] ?? '')) ?? '');
            }
        } catch (Throwable $e) { /* table des boutiques absente : le panel */ }
    }
    if (array_filter($out, fn ($m) => $m['cp'] === '' || $m['franchise'] === '') && PanelApi::configured()) {
        $r = PanelApi::get('/shops');
        foreach (is_array($r) ? PanelApi::liste($r) : [] as $s) {
            $id = (int) ($s['id'] ?? 0);
            if (!isset($out[$id])) { continue; }
            if ($out[$id]['cp'] === '') { $out[$id]['cp'] = (string) (biCp((string) ($s['zip'] ?? '')) ?? ''); }
            if ($out[$id]['franchise'] === '') {
                // « Atelier by Harmonie - Sombreffe » : le franchisé entre « Atelier by » et le tiret ; sinon la société.
                $rep = (string) ($s['representative_name'] ?? '');
                $out[$id]['franchise'] = preg_match('/^\s*l?.?atelier\s+by\s+(.+?)\s+-\s+/iu', $rep, $m) ? trim($m[1]) : trim((string) ($s['name'] ?? ''));
            }
            if (!empty($s['city'])) { $out[$id]['ville'] = trim((string) $s['city']); }
        }
    }
    return $memo = $out;
}

/* =================================================================================
   2. LES ZONES PAR CODE POSTAL (format des plages de MétaDonnée)
   ================================================================================= */

/** Des plages {de, a, shopId, libelle} propres : bornes entières, magasin connu, 400 au plus. */
function biPlagesNettoyer(mixed $plages, array $mags): array
{
    $out = [];
    foreach (is_array($plages) ? $plages : [] as $p) {
        if (!is_array($p)) { continue; }
        $de = (int) ($p['de'] ?? 0); $a = (int) ($p['a'] ?? $de);
        $sid = (string) ($p['shopId'] ?? '');
        if ($de < 1000 || $de > 9999 || $a < 1000 || $a > 9999 || !isset($mags[(int) $sid])) { continue; }
        if ($a < $de) { [$de, $a] = [$a, $de]; }
        $out[] = ['de' => $de, 'a' => $a, 'shopId' => $sid, 'libelle' => mb_substr(trim((string) ($p['libelle'] ?? '')), 0, 80)];
    }
    usort($out, static fn ($x, $y) => [$x['shopId'], $x['de']] <=> [$y['shopId'], $y['de']]);
    return array_slice($out, 0, 400);
}

/** Les plages par défaut : les codes postaux à moins de 10 km de chaque boutique dont le code postal est connu. */
function biZonesDefaut(array $mags): array
{
    $out = [];
    foreach ($mags as $id => $m) {
        foreach (BI_ZONES_DEFAUT[$m['cp']] ?? ($m['cp'] !== '' ? [$m['cp'] => $m['ville']] : []) as $cp => $lib) {
            $out[] = ['de' => (int) $cp, 'a' => (int) $cp, 'shopId' => (string) $id, 'libelle' => (string) $lib];
        }
    }
    return $out;
}

/** Les zones en vigueur : le réglage `benchmark.zones`, sinon les zones par défaut. */
function biZones(array $mags): array
{
    $s = setting('benchmark', []);
    $z = is_array($s) && isset($s['zones']) ? biPlagesNettoyer($s['zones'], $mags) : null;
    return $z !== null && $z !== [] ? $z : biZonesDefaut($mags);
}

/** La commune d'un code postal d'après les zones (ou la table des villes), vide si inconnue. */
function biCommune(string $cp, array $plages): string
{
    $n = (int) $cp;
    foreach ($plages as $p) { if ($p['de'] === $n && $p['a'] === $n && $p['libelle'] !== '') { return $p['libelle']; } }
    $v = array_search($cp, BI_CP_VILLES, true);
    return $v === false ? '' : ucwords(str_replace(' ', '-', (string) $v), '-');
}

/**
 * Le classement d'un code postal : les magasins dont une plage le couvre.
 * @return array{cp:?string, magasins:list<int>, statut:string, commune:string}  statut : zone | hors | inconnu
 */
function biClasser(?string $cp, array $plages): array
{
    if ($cp === null || $cp === '') { return ['cp' => null, 'magasins' => [], 'statut' => 'inconnu', 'commune' => '']; }
    $n = (int) $cp; $ids = [];
    foreach ($plages as $p) { if ($n >= $p['de'] && $n <= $p['a']) { $ids[(int) $p['shopId']] = true; } }
    return ['cp' => $cp, 'magasins' => array_keys($ids), 'statut' => $ids ? 'zone' : 'hors', 'commune' => biCommune($cp, $plages)];
}

/** Le code postal d'un concurrent de TFBuddy : l'étiquette « [cp:5140] » des notes, un « CP 5140 », sinon la ville. */
function biCpConcurrent(array $c): ?string
{
    $notes = (string) ($c['notes'] ?? '');
    if (preg_match('/\[cp:(\d{4})\]/', $notes, $m)) { return $m[1]; }
    if (preg_match('/\bCP\s*(\d{4})\b/i', $notes, $m)) { return $m[1]; }
    $ville = (string) ($c['city'] ?? ($c['ville'] ?? ''));
    return biCp($ville) ?? (BI_CP_VILLES[benchmarkNomCle($ville)] ?? null);
}

/** Supermarché ou chaîne présente partout : un prix national, pas de code postal à classer. */
function biNational(array $c): bool
{
    $v = benchmarkNomCle((string) ($c['city'] ?? ($c['ville'] ?? '')));
    return in_array($v, ['national', 'regional', 'e shop'], true);
}

/* =================================================================================
   3. LES LIGNES LUES ET LE RAPPROCHEMENT
   ================================================================================= */

/** Un nombre positif, ou null (le modèle rend parfois « 4,20 » en texte). */
function biNombre(mixed $v): ?float
{
    if ($v === null || $v === '') { return null; }
    $f = (float) str_replace(',', '.', (string) $v);
    return $f > 0 ? round($f, 3) : null;
}

/**
 * Le relevé du modèle → nos lignes : {i, rubrique, libelle, prix, promo, promoTexte, g, d, pieces, parts, page, url, etat}.
 * etat : prix (une ligne à rapprocher) | illi (aucun prix lisible).
 */
function biLignesDepuisIa(array $releve): array
{
    $out = [];
    foreach ((array) ($releve['lignes'] ?? []) as $l) {
        if (!is_array($l)) { continue; }
        $lib = trim((string) ($l['libelle'] ?? ''));
        if ($lib === '') { continue; }
        $prix = biNombre($l['prix'] ?? null); $promo = biNombre($l['prix_promo'] ?? null);
        $pieces = (int) ($l['pieces'] ?? 0); $parts = (int) ($l['parts'] ?? 0);
        $out[] = [
            'i' => count($out), 'rubrique' => mb_substr(trim((string) ($l['rubrique'] ?? '')), 0, 80), 'libelle' => mb_substr($lib, 0, 150),
            'prix' => $prix, 'promo' => $promo, 'promoTexte' => mb_substr(trim((string) ($l['promo_texte'] ?? '')), 0, 80),
            'g' => biNombre($l['poids_g'] ?? null), 'd' => biNombre($l['diametre_cm'] ?? null),
            'pieces' => $pieces > 1 ? $pieces : null, 'parts' => $parts > 1 ? $parts : null,
            'page' => (int) ($l['page'] ?? 0) ?: null, 'url' => mb_substr(trim((string) ($l['source_url'] ?? '')), 0, 300),
            'etat' => $prix === null && $promo === null ? 'illi' : 'prix',
        ];
    }
    return $out;
}

/** Le concurrent lu, nettoyé : {nom, type, adresse, cp, ville, site}. */
function biConcurrentDepuisIa(array $releve, array $saisi = []): array
{
    $c = is_array($releve['concurrent'] ?? null) ? $releve['concurrent'] : [];
    $pick = static fn (string $k, string $kIa) => trim((string) (($saisi[$k] ?? '') !== '' ? $saisi[$k] : ($c[$kIa] ?? '')));
    $type = (string) ($saisi['type'] ?? '') ?: (string) ($c['type'] ?? '');
    return ['nom' => mb_substr($pick('nom', 'nom'), 0, 120), 'type' => in_array($type, ['artisan', 'chaine', 'supermarche', 'autre'], true) ? $type : 'artisan',
        'adresse' => mb_substr($pick('adresse', 'adresse'), 0, 160), 'cp' => (string) (biCp($pick('cp', 'code_postal')) ?? ''),
        'ville' => mb_substr($pick('ville', 'ville'), 0, 80), 'site' => mb_substr($pick('site', 'site'), 0, 200)];
}

/** Les mots d'un libellé : minuscules sans accents, sans mots vides ni mesures, au singulier, synonymes ramenés. */
function biMots(string $s): array
{
    $n = ' ' . benchmarkNomCle($s) . ' ';
    // Ce que le réseau et les concurrents nomment autrement (tarifs belges).
    $n = strtr($n, [' pur beurre ' => ' ', ' couque suisse ' => ' suisse ', ' couques suisses ' => ' suisse ', ' couque au chocolat ' => ' pain chocolat ',
        ' couques au chocolat ' => ' pain chocolat ', ' sandwich mou ' => ' sandwich ', ' sandwichs mous ' => ' sandwich ', ' petits pains ' => ' pistolet ',
        ' pain gris ' => ' pain gris ', ' eclairs ' => ' eclair ', ' tartelette ' => ' tartelette ', ' cougnous ' => ' cougnou ', ' koekebrood ' => ' cougnou ',
        ' merveilleux ' => ' merveilleux ', ' gateau d anniversaire ' => ' anniversaire ', ' gateaux anniversaire ' => ' anniversaire ']);
    static $vides = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'au', 'aux', 'a', 'et', 'en', 'avec', 'sans', 'pur', 'maison', 'artisanal', 'artisanale',
        'piece', 'pieces', 'pc', 'pcs', 'ind', 'individuel', 'individuelle', 'g', 'gr', 'kg', 'cm', 'pers', 'personne', 'personnes', 'p', 'part', 'parts',
        'x', 'n', 'nr', 'o', 'ou', 'un', 'une', 'par', 'pour', 'the', 'el', 'een', 'het', 'met', 'kilo', 'environ', 'env',
        // Les mots des promotions : « 4 croissants + 1 offert », « −20 % le mercredi ».
        'offert', 'offerte', 'offerts', 'gratuit', 'gratuite', 'promo', 'promotion', 'achete', 'achetes', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi',
        'samedi', 'dimanche', 'semaine', 'week', 'end', 'jour', 'jours'];
    $mots = [];
    foreach (preg_split('/\s+/', trim($n)) ?: [] as $m) {
        if ($m === '' || preg_match('/\d/', $m) || in_array($m, $vides, true) || mb_strlen($m) < 2) { continue; }
        // Pluriels : « amandes » → amande, « gateaux » → gateau ; pas « suisse », pas « riz », pas « anglais ».
        if (mb_strlen($m) > 4 && !str_ends_with($m, 'ss') && (str_ends_with($m, 's') || str_ends_with($m, 'x'))) { $m = mb_substr($m, 0, -1); }
        $mots[$m] = true;
    }
    return array_keys($mots);
}

/** Deux mots proches : égaux, ou une faute d'une lettre sur un mot de 5 lettres et plus. */
function biMotProche(string $a, string $b): bool
{
    if ($a === $b) { return true; }
    return mb_strlen($a) >= 5 && mb_strlen($b) >= 5 && levenshtein($a, $b) <= 1;
}

/** Ressemblance de deux listes de mots (Dice), de 0 à 1. */
function biDice(array $x, array $y): float
{
    if (!$x || !$y) { return 0.0; }
    $commun = 0;
    foreach ($x as $a) { foreach ($y as $b) { if (biMotProche($a, $b)) { $commun++; break; } } }
    return 2 * $commun / (count($x) + count($y));
}

/**
 * Le catalogue d'un magasin pour le rapprochement : {pid, nom, cat, catId, prix, poids, d, parts, lot, mots, motsCat}.
 * La taille vient du nom (« 400 g », « Ø 28 », « 4/6 personnes ») ou de la catégorie (« Tartes – Ø 28 cm »), le poids
 * du panel (single_weight) faute de mieux.
 */
function biCatalogue(int $shop): array
{
    static $memo = [];
    if (isset($memo[$shop])) { return $memo[$shop]; }
    $out = [];
    foreach (PanelApi::produitsDisponibles($shop) as $p) {
        if (!is_array($p) || !(int) ($p['id'] ?? 0) || (isset($p['is_active']) && !(int) $p['is_active'])) { continue; }
        $out[] = biProduit($p);
    }
    return $memo[$shop] = $out;
}

/** Une ligne du catalogue du panel → notre produit, ses tailles et ses mots. */
function biProduit(array $p): array
{
    $nom = trim((string) ($p['name'] ?? ($p['base_name'] ?? '')));
    $cat = trim((string) ($p['category_name'] ?? ($p['category']['name'] ?? '')));
    $t = $nom . ' ' . $cat;
    $poids = null;
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|g)\b\.?/iu', $nom, $m)) { $poids = (float) str_replace(',', '.', $m[1]) * (strtolower($m[2]) === 'kg' ? 1000 : 1); }
    elseif ((float) ($p['single_weight'] ?? 0) >= 20) { $poids = (float) $p['single_weight']; }
    $d = null;
    if (preg_match('/(\d{2,3})\s*Ø|Ø\s*(\d{2,3})/u', $t, $m)) { $v = (int) ($m[1] !== '' ? $m[1] : $m[2]); $d = $v >= 100 ? $v / 10.0 : (float) $v; }
    $parts = null;
    if (preg_match('/(\d{1,2})\s*(?:\/|à|-)\s*(\d{1,2})\s*(?:personnes|pers)/iu', $nom, $m)) { $parts = ((int) $m[1] + (int) $m[2]) / 2; }
    elseif (preg_match('/(\d{1,2})\s*(?:personnes|pers\b|parts)/iu', $nom, $m)) { $parts = (float) $m[1]; }
    $lot = null;
    if (preg_match('/\(x\s*(\d{1,2})\)|(\d{1,2})\s*(?:pièces|pcs|pc\b)|boîte de\s*(\d{1,2})/iu', $nom, $m)) { $lot = (int) max((int) ($m[1] ?? 0), (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)) ?: null; }
    $prix = (float) ($p['portion_price_gross'] ?? ($p['portion_price'] ?? 0));
    return ['pid' => (int) $p['id'], 'nom' => $nom, 'cat' => $cat, 'catId' => (int) ($p['id_category'] ?? 0) ?: null, 'prix' => $prix > 0 ? round($prix, 2) : 0.0,
        'poids' => $poids, 'd' => $d, 'parts' => $parts, 'lot' => $lot, 'mots' => biMots($nom), 'motsCat' => biMots($cat)];
}

/** Le score d'un de nos produits pour une ligne, de 0 à 99 : les mots du nom, puis du nom avec sa catégorie, la rubrique. */
function biScore(array $ligne, array $prod): int
{
    $mots = biMots($ligne['libelle']);
    if (!$mots) { return 0; }
    $s = max(biDice($mots, $prod['mots']), 0.95 * biDice($mots, array_values(array_unique(array_merge($prod['mots'], $prod['motsCat'])))));
    // La rubrique du PDF (« Viennoiseries », « Tartes ») qui retrouve la catégorie : un petit plus.
    if ($ligne['rubrique'] !== '' && biDice(biMots($ligne['rubrique']), $prod['motsCat']) > 0) { $s += 0.05; }
    // Un format que nous n'avons pas (diamètre face à un produit sans Ø) : un peu moins sûr.
    if ($ligne['d'] !== null && $prod['d'] === null) { $s -= 0.05; }
    if ($prod['prix'] <= 0) { $s -= 0.03; }
    return (int) max(0, min(99, round($s * 100)));
}

/** La mémoire des correspondances confirmées : [concurrent|libelle => pid], le concurrent d'abord, le réseau ensuite. */
function biMemoire(string $concurrent): array
{
    $cle = benchmarkNomCle($concurrent);
    $out = [];
    try {
        foreach (Db::rows('SELECT concurrent, libelle, product_id FROM ceo_bench_correspondance WHERE concurrent IN (?, ?)', [$cle, '*']) as $r) {
            $k = (string) $r['libelle'];
            if ((string) $r['concurrent'] !== '*' || !isset($out[$k])) { $out[$k] = (int) $r['product_id']; }
        }
    } catch (Throwable $e) { /* table neuve : rien en mémoire */ }
    return $out;
}

/** La clé d'un libellé dans la mémoire : ses mots triés. */
function biCleLibelle(string $lib): string
{
    $m = biMots($lib); sort($m);
    return mb_substr(implode(' ', $m), 0, 190);
}

/**
 * La proposition pour une ligne : {pid, score, statut, candidats, appris}.
 * statut : sur (90 et plus, cochée d'office) | conf (60 à 89) | sans (rien d'assez proche).
 */
function biProposer(array $ligne, array $catalogue, array $memoire): array
{
    $parPid = [];
    foreach ($catalogue as $p) { $parPid[$p['pid']] = $p; }
    $cands = [];
    $mots = biMots($ligne['libelle']);
    $complets = [];
    foreach ($catalogue as $p) {
        $sc = biScore($ligne, $p);
        // Tous les mots de la ligne dans le nom du produit : « Pistolets » dans « Pistolet à l'Ancienne ».
        $restent = $p['mots'];
        $complet = $mots !== [];
        foreach ($mots as $m) {
            $trouve = false;
            foreach ($restent as $j => $x) { if (biMotProche($m, $x)) { unset($restent[$j]); $trouve = true; break; } }
            if (!$trouve) { $complet = false; break; }
        }
        if ($complet) { $complets[$p['pid']] = array_values($restent); }
        if ($sc >= 35) { $cands[] = ['pid' => $p['pid'], 'score' => $sc]; }
    }
    // Un seul de nos produits contient tous les mots de la ligne, avec un mot de plus au plus et qui n'en change pas le sens
    // (pas « demi », « mini », « nappé ») : c'est lui. Deux mots de plus : probable, à confirmer.
    if (count($complets) === 1) {
        $pid = (int) array_key_first($complets);
        $extra = $complets[$pid];
        $sens = (bool) array_intersect($extra, ['demi', 'mini', 'maxi', 'double', 'nappe', 'petit', 'grand', 'garni', 'glace']);
        $plancher = !$sens && count($extra) <= 1 ? 90 : (count($extra) <= 2 ? 75 : 0);
        foreach ($cands as &$c) { if ($c['pid'] === $pid) { $c['score'] = max($c['score'], $plancher); } }
        unset($c);
    }
    usort($cands, static fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['pid'] <=> $b['pid']);
    $cands = array_slice($cands, 0, 5);
    $mem = $memoire[biCleLibelle($ligne['libelle'])] ?? null;
    if ($mem !== null && isset($parPid[$mem])) {
        $cands = array_values(array_filter($cands, fn ($c) => $c['pid'] !== $mem));
        array_unshift($cands, ['pid' => $mem, 'score' => 99]);
        return ['pid' => $mem, 'score' => 99, 'statut' => 'sur', 'candidats' => array_slice($cands, 0, 5), 'appris' => true];
    }
    $top = $cands[0] ?? null;
    if ($top === null || $top['score'] < 60) { return ['pid' => null, 'score' => $top['score'] ?? 0, 'statut' => 'sans', 'candidats' => $cands, 'appris' => false]; }
    // Deux candidats au coude à coude : à confirmer, même haut.
    $serre = isset($cands[1]) && $cands[1]['score'] >= $top['score'] - 3;
    return ['pid' => $top['pid'], 'score' => $top['score'], 'statut' => $top['score'] >= 90 && !$serre ? 'sur' : 'conf', 'candidats' => $cands, 'appris' => false];
}

/**
 * Les deux prix sur une même base : {base, eux, nous, unite, ecart} (ecart = nous / eux − 1), ou {manque: true} quand
 * notre prix est à 0 au panel, ou null sans prix lisible.
 */
function biComparer(array $l, array $p): ?array
{
    $prixL = $l['prix'] ?? null;
    if ($prixL === null && ($l['promo'] ?? null) === null) { return null; }
    if ($p['prix'] <= 0) { return ['manque' => true]; }
    $nousPiece = $p['lot'] ? $p['prix'] / $p['lot'] : $p['prix'];
    $promo = $l['promo'] ?? null;
    $eux = $promo !== null && $prixL === null ? $promo : $prixL;
    $base = 'à la pièce'; $unite = ''; $nous = $nousPiece;
    if ($l['pieces']) {
        $eux = $eux / $l['pieces']; $base = 'les ' . $l['pieces'] . ', ramené à la pièce';
    } elseif ($l['g'] && $p['poids']) {
        $eux = $eux / ($l['g'] / 1000); $nous = $p['prix'] / ($p['poids'] / 1000); $unite = '/kg';
        $base = 'au kg · ' . biNf($l['g'], 0) . ' g / ' . biNf($p['poids'], 0) . ' g';
    } elseif ($l['d'] && $p['d']) {
        $eux = $eux * ($p['d'] / $l['d']) ** 2;
        $base = 'Ø ' . biNf($l['d'], 0) . ' → Ø ' . biNf($p['d'], 0) . ', même surface';
    } elseif ($l['parts'] && $p['parts']) {
        $eux = $eux / $l['parts']; $nous = $p['prix'] / $p['parts']; $unite = '/part';
        $base = 'à la part · ' . $l['parts'] . ' p. / ' . biNf($p['parts'], 0) . ' p.';
    }
    if ($eux <= 0) { return null; }
    return ['base' => $base, 'eux' => round($eux, 3), 'nous' => round($nous, 3), 'unite' => $unite, 'ecart' => round($nous / $eux - 1, 4),
        'promo' => $promo !== null && $prixL !== null];
}

function biNf(float $v, int $d): string { return number_format($v, $d, ',', ' '); }

/** La médiane, sans arrondi (un écart de 0,134 reste 13,4 %). */
function biMediane(array $v): ?float
{
    $v = array_values(array_filter($v, 'is_numeric')); sort($v);
    $n = count($v);
    return $n === 0 ? null : ($n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
}

/* =================================================================================
   4. UN RELEVÉ : LECTURE, VUE, CHOIX
   ================================================================================= */

function biLire(int $id): ?array
{
    biTables();
    $r = Db::row('SELECT * FROM ceo_bench_import WHERE id = ?', [$id]);
    if (!$r) { return null; }
    foreach (['concurrent', 'magasins', 'lignes', 'choix', 'sources', 'encodage'] as $k) { $r[$k] = json_decode((string) ($r[$k] ?? ''), true) ?: []; }
    return $r;
}

/** Enregistre un relevé lu (PDF ou web) et rend son identifiant. */
function biCreer(array $champs): int
{
    biTables();
    $now = date('Y-m-d H:i:s');
    Db::exec('INSERT INTO ceo_bench_import (cree_le, maj_le, source, statut, titre, fichier, nom_origine, octets, empreinte, demande, concurrent, magasins, date_releve, lignes, choix, sources, remarques, modele, jetons)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $now, $now, $champs['source'], 'lu', mb_substr((string) $champs['titre'], 0, 190), $champs['fichier'] ?? null, $champs['nom_origine'] ?? null,
        (int) ($champs['octets'] ?? 0), $champs['empreinte'] ?? null, $champs['demande'] ?? null,
        json_encode($champs['concurrent'], JSON_UNESCAPED_UNICODE), json_encode(array_values($champs['magasins']), JSON_UNESCAPED_UNICODE),
        $champs['date_releve'] ?? null, json_encode($champs['lignes'], JSON_UNESCAPED_UNICODE), '{}', json_encode($champs['sources'] ?? [], JSON_UNESCAPED_UNICODE),
        mb_substr((string) ($champs['remarques'] ?? ''), 0, 2000), $champs['modele'] ?? null, (int) ($champs['jetons'] ?? 0)]);
    $id = method_exists('Db', 'pdo') ? (int) Db::pdo()->lastInsertId() : 0;
    if ($id > 0) { return $id; }
    $r = Db::row('SELECT id FROM ceo_bench_import WHERE cree_le = ? AND titre = ? ORDER BY id DESC LIMIT 1', [$now, mb_substr((string) $champs['titre'], 0, 190)]);
    return (int) ($r['id'] ?? 0);
}

/** Le magasin dont le catalogue sert au rapprochement : le premier retenu, sinon le premier du réseau. */
function biMagasinRef(array $imp, array $mags): int
{
    foreach ((array) $imp['magasins'] as $id) { if (isset($mags[(int) $id])) { return (int) $id; } }
    return (int) (array_key_first($mags) ?? 0);
}

/**
 * La décision d'une ligne : le choix saisi l'emporte sur la proposition.
 * Rend {pid|null, etat} : etat = produit | sans | ignore | illi.
 */
function biDecision(array $ligne, array $prop, array $choix): array
{
    $k = (string) $ligne['i'];
    if ($ligne['etat'] === 'illi' && !array_key_exists($k, $choix)) { return ['pid' => null, 'etat' => 'illi']; }
    if (array_key_exists($k, $choix)) {
        $c = (int) $choix[$k];
        return $c > 0 ? ['pid' => $c, 'etat' => 'produit'] : ($c === 0 ? ['pid' => null, 'etat' => 'sans'] : ['pid' => null, 'etat' => 'ignore']);
    }
    // Sans choix : une proposition sûre est retenue ; à confirmer, elle attend ; sans équivalent, la ligne entre sans produit.
    if ($prop['statut'] === 'sur') { return ['pid' => $prop['pid'], 'etat' => 'produit']; }
    if ($prop['statut'] === 'sans') { return ['pid' => null, 'etat' => 'sans']; }
    return ['pid' => null, 'etat' => 'attente'];
}

/** La vue d'un relevé pour l'écran : le concurrent, son classement, chaque ligne avec sa proposition et sa comparaison. */
function biVue(array $imp, bool $avecCatalogue = true): array
{
    $mags = biMagasins();
    $plages = biZones($mags);
    $c = $imp['concurrent'];
    $cl = biClasser(($c['cp'] ?? '') ?: null, $plages);
    $ref = biMagasinRef($imp, $mags);
    $cat = $avecCatalogue && $ref ? biCatalogue($ref) : [];
    $parPid = [];
    foreach ($cat as $p) { $parPid[$p['pid']] = $p; }
    $mem = biMemoire((string) ($c['nom'] ?? ''));
    $lignes = []; $n = ['produit' => 0, 'attente' => 0, 'sans' => 0, 'ignore' => 0, 'illi' => 0];
    foreach ((array) $imp['lignes'] as $l) {
        $prop = $l['etat'] === 'illi' ? ['pid' => null, 'score' => 0, 'statut' => 'sans', 'candidats' => [], 'appris' => false] : biProposer($l, $cat, $mem);
        $dec = biDecision($l, $prop, (array) $imp['choix']);
        $n[$dec['etat']]++;
        $p = $dec['pid'] ? ($parPid[$dec['pid']] ?? null) : ($prop['pid'] ? ($parPid[$prop['pid']] ?? null) : null);
        $lignes[] = $l + ['proposition' => $prop, 'decision' => $dec,
            'produit' => $p ? ['pid' => $p['pid'], 'nom' => $p['nom'], 'cat' => $p['cat'], 'prix' => $p['prix']] : ($dec['pid'] ? ['pid' => $dec['pid'], 'nom' => 'produit ' . $dec['pid'], 'cat' => '', 'prix' => 0.0] : null),
            'comparaison' => $p ? biComparer($l, $p) : null,
            'candidats' => array_map(fn ($x) => ['pid' => $x['pid'], 'score' => $x['score'], 'nom' => $parPid[$x['pid']]['nom'] ?? '', 'cat' => $parPid[$x['pid']]['cat'] ?? '',
                'prix' => $parPid[$x['pid']]['prix'] ?? 0.0, 'comparaison' => isset($parPid[$x['pid']]) ? biComparer($l, $parPid[$x['pid']]) : null], $prop['candidats'])];
    }
    // Ce que l'encodage comparera : les lignes rattachées, hors promotions et prix manquants.
    $ecarts = [];
    foreach ($lignes as $l) { if ($l['decision']['etat'] === 'produit' && $l['comparaison'] && empty($l['comparaison']['manque']) && $l['promo'] === null) { $ecarts[] = $l['comparaison']['ecart']; } }
    return [
        'id' => (int) $imp['id'], 'source' => (string) $imp['source'], 'statut' => (string) $imp['statut'], 'titre' => (string) $imp['titre'],
        'creeLe' => substr((string) $imp['cree_le'], 0, 16), 'nomOrigine' => $imp['nom_origine'], 'octets' => (int) $imp['octets'], 'demande' => $imp['demande'],
        'concurrent' => $c, 'classement' => $cl + ['nomsMagasins' => array_map(fn ($id) => $mags[$id]['nom'] ?? (string) $id, $cl['magasins'])],
        'magasins' => array_values(array_map('intval', (array) $imp['magasins'])), 'magasinRef' => $ref,
        'dateReleve' => $imp['date_releve'], 'sources' => (array) $imp['sources'], 'remarques' => (string) ($imp['remarques'] ?? ''),
        'modele' => $imp['modele'], 'jetons' => (int) $imp['jetons'], 'lignes' => $lignes, 'compte' => $n,
        'resume' => ['compares' => count($ecarts), 'plus' => count(array_filter($ecarts, fn ($e) => $e > 0.02)), 'moins' => count(array_filter($ecarts, fn ($e) => $e < -0.02)),
            'mediane' => biMediane($ecarts)],
        'catalogue' => array_map(fn ($p) => [$p['pid'], $p['nom'], $p['cat'], $p['prix']], $cat),
        'encodage' => $imp['encodage'] ?: null, 'catalogueLu' => $cat !== [],
    ];
}

/* =================================================================================
   5. ENCODER DANS LE BENCHMARK DE TFBUDDY
   ================================================================================= */

const BI_TYPES = ['artisan' => 'Artisan', 'chaine' => 'Chaîne', 'supermarche' => 'GMS', 'autre' => 'Autre'];

/**
 * Le plan (apercu) ou l'écriture d'un relevé dans chaque magasin retenu : le concurrent (retrouvé par son nom, créé
 * sinon), une observation datée portant l'étiquette [cockpit:import:{id}], ses items. Rejouable : une observation déjà
 * pleine n'est pas refaite. La mémoire retient chaque ligne rattachée.
 */
function biEncoder(array $imp, bool $apercu): array
{
    $vue = biVue($imp);
    $c = $vue['concurrent'];
    $mags = biMagasins();
    $magasins = array_values(array_filter($vue['magasins'], fn ($id) => isset($mags[$id])));
    if (trim((string) ($c['nom'] ?? '')) === '') { return ['ok' => false, 'error' => 'le concurrent n’a pas de nom']; }
    if ($magasins === []) { return ['ok' => false, 'error' => 'aucun magasin retenu : le code postal n’entre dans aucune zone, cochez un magasin']; }
    $attente = $vue['compte']['attente'];
    if (!$apercu && $attente > 0) { return ['ok' => false, 'error' => $attente . ' ligne(s) à confirmer : choisissez notre produit, « sans équivalent » ou « ignorer »']; }
    if (!$apercu && !BenchmarkApi::disponible()) { return ['ok' => false, 'error' => 'compte consultant du panel non configuré']; }
    $tag = '[cockpit:import:' . (int) $imp['id'] . ']';
    $catIds = [];
    foreach (biCatalogue($vue['magasinRef']) as $p) { $catIds[$p['pid']] = $p['catId']; }
    $items = [];
    foreach ($vue['lignes'] as $l) {
        $d = $l['decision'];
        if (!in_array($d['etat'], ['produit', 'sans'], true)) { continue; }
        $prix = $l['prix'] ?? $l['promo'];
        $corps = ['name_raw' => mb_substr($l['libelle'] . ($l['pieces'] ? ' (les ' . $l['pieces'] . ')' : '') . ($l['parts'] ? ' (' . $l['parts'] . ' p.)' : ''), 0, 150), 'price' => (float) $prix];
        if ($l['g']) { $corps += ['unit_type' => 'g', 'unit_size' => (float) $l['g']]; }
        elseif ($l['d']) { $corps += ['unit_type' => 'cm', 'unit_size' => (float) $l['d']]; }
        else { $corps += ['unit_type' => 'piece', 'unit_size' => (float) ($l['pieces'] ?: 1)]; }
        if ($l['promo'] !== null && $l['prix'] !== null) { $corps += ['is_promo' => true, 'promo_price' => (float) $l['promo']]; }
        if ($d['etat'] === 'produit' && $d['pid']) { $corps['my_product_id'] = (int) $d['pid']; if (!empty($catIds[$d['pid']])) { $corps['my_category_id'] = (int) $catIds[$d['pid']]; } }
        $corps['notes'] = mb_substr(trim(($l['rubrique'] !== '' ? $l['rubrique'] . ' · ' : '') . ($l['page'] ? 'PDF p. ' . $l['page'] : '') . ($l['promoTexte'] !== '' ? ' · ' . $l['promoTexte'] : '') . ($l['url'] !== '' ? ' ' . $l['url'] : '')), 0, 250);
        $items[] = ['ligne' => $l['i'], 'corps' => $corps, 'produit' => $d['etat'] === 'produit'];
    }
    $cp = (string) ($c['cp'] ?? '');
    $fiche = array_filter(['name' => mb_substr((string) $c['nom'], 0, 120), 'type' => BI_TYPES[$c['type'] ?? 'artisan'] ?? 'Artisan',
        'city' => mb_substr((string) ($c['ville'] ?? ''), 0, 80),
        'notes' => mb_substr(trim(implode(' · ', array_filter([(string) ($c['adresse'] ?? ''), $cp !== '' ? 'CP ' . $cp : '', (string) ($c['site'] ?? '')])) . ($cp !== '' ? ' [cp:' . $cp . ']' : '')), 0, 250),
        'is_active' => true], fn ($v) => $v !== '' && $v !== null);
    $libObs = ($imp['source'] === 'pdf' ? 'Relevé PDF « ' . ($imp['nom_origine'] ?? '') . ' »' : 'Recherche internet (IA)') . ' ' . $tag;
    $date = BenchmarkApi::date((string) ($imp['date_releve'] ?? '')) ?? date('Y-m-d');
    $res = []; $toutBon = true;
    foreach ($magasins as $shop) {
        $r = ['shop' => $shop, 'magasin' => $mags[$shop]['nom'], 'franchise' => $mags[$shop]['franchise'], 'concurrent' => null, 'nouveau' => false,
            'observation' => null, 'items' => count($items), 'avecProduit' => count(array_filter($items, fn ($i) => $i['produit'])), 'relus' => null, 'etat' => 'plan', 'message' => ''];
        $liste = BenchmarkApi::disponible() ? BenchmarkApi::concurrents($shop) : null;
        $cid = 0;
        foreach ((array) $liste as $x) { if (benchmarkNomCle((string) ($x['name'] ?? '')) === benchmarkNomCle((string) $c['nom'])) { $cid = (int) $x['id']; } }
        $r['concurrent'] = $cid ?: null; $r['nouveau'] = $cid === 0;
        if ($apercu) { $res[] = $r; continue; }
        if ($cid === 0) {
            $id = BenchmarkApi::creerConcurrent($shop, $fiche);
            if ($id !== null && $id <= 1) { foreach ((array) BenchmarkApi::concurrents($shop) as $x) { if (benchmarkNomCle((string) ($x['name'] ?? '')) === benchmarkNomCle((string) $c['nom'])) { $id = (int) $x['id']; } } }
            if (!$id || $id <= 1) { $r['etat'] = 'erreur'; $r['message'] = 'concurrent refusé : ' . (BenchmarkApi::$lastError ?? '?'); $res[] = $r; $toutBon = false; continue; }
            $cid = (int) $id; $r['concurrent'] = $cid;
        }
        $oid = 0;
        foreach ((array) BenchmarkApi::observations($shop, $cid) as $o) { if (str_contains((string) ($o['notes'] ?? ''), $tag)) { $oid = (int) $o['id']; } }
        if ($oid > 0 && (array) BenchmarkApi::items($shop, $oid) !== []) {
            $r['observation'] = $oid; $r['etat'] = 'deja'; $r['relus'] = count((array) BenchmarkApi::items($shop, $oid)); $r['message'] = 'déjà encodé'; $res[] = $r; continue;
        }
        if ($oid === 0) {
            $oid = (int) BenchmarkApi::creerObservation($shop, $cid, ['observed_at' => $date, 'notes' => $libObs]);
            if ($oid <= 1) { foreach ((array) BenchmarkApi::observations($shop, $cid) as $o) { if (str_contains((string) ($o['notes'] ?? ''), $tag)) { $oid = (int) $o['id']; } } }
            if ($oid <= 1) { $r['etat'] = 'erreur'; $r['message'] = 'observation refusée : ' . (BenchmarkApi::$lastError ?? '?'); $res[] = $r; $toutBon = false; continue; }
        }
        $r['observation'] = $oid;
        foreach ($items as $i) { BenchmarkApi::creerItem($shop, $cid, $oid, benchmarkItemPourApi($i['corps'])); }
        // TFBuddy répond 201 même quand la ligne n'entre pas : on compte ce qui est relu.
        $r['relus'] = count((array) BenchmarkApi::items($shop, $oid));
        $r['etat'] = $r['relus'] >= count($items) ? 'ok' : 'partiel';
        if ($r['etat'] !== 'ok') { $toutBon = false; $r['message'] = ($r['relus'] ?? 0) . ' ligne(s) relue(s) sur ' . count($items) . ($items ? ' : ' . (BenchmarkApi::$lastError ?? 'lignes refusées') : ''); }
        $res[] = $r;
    }
    if ($apercu) { return ['ok' => true, 'apercu' => true, 'magasins' => $res, 'items' => count($items), 'fiche' => $fiche, 'observation' => ['observed_at' => $date, 'notes' => $libObs]]; }
    // La mémoire : chaque ligne rattachée est une correspondance confirmée, pour ce concurrent et pour le réseau.
    biRetenir((string) $c['nom'], array_values(array_filter(array_map(fn ($l) => $l['decision']['etat'] === 'produit' && $l['decision']['pid'] ? [$l['libelle'], (int) $l['decision']['pid']] : null, $vue['lignes']))));
    Db::exec('UPDATE ceo_bench_import SET statut = ?, encodage = ?, maj_le = ? WHERE id = ?',
        [$toutBon ? 'encode' : 'lu', json_encode(['le' => date('Y-m-d H:i'), 'magasins' => $res], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), (int) $imp['id']]);
    if (function_exists('journalAdd')) {
        journalAdd('Cockpit', 'benchmark', 'Benchmark › Import', 'Relevé « ' . $c['nom'] . ' » (' . ($imp['source'] === 'pdf' ? 'PDF' : 'internet') . ') encodé dans ' . count($res) . ' magasin(s) : '
            . implode(', ', array_map(fn ($r) => $r['magasin'] . ' ' . ($r['relus'] ?? 0) . '/' . $r['items'], $res)) . '.');
    }
    return ['ok' => $toutBon, 'apercu' => false, 'magasins' => $res, 'items' => count($items)];
}

/** Retient des correspondances [libellé, pid] pour un concurrent et pour le réseau. */
function biRetenir(string $concurrent, array $paires): void
{
    if ($paires === []) { return; }
    biTables();
    $cle = mb_substr(benchmarkNomCle($concurrent), 0, 120);
    $now = date('Y-m-d H:i:s');
    foreach ($paires as [$lib, $pid]) {
        $k = biCleLibelle((string) $lib);
        if ($k === '' || $pid <= 0) { continue; }
        foreach ([$cle, '*'] as $qui) {
            Db::exec('INSERT INTO ceo_bench_correspondance (concurrent, libelle, product_id, n, maj_le) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE n = IF(product_id = VALUES(product_id), n + 1, 1), product_id = VALUES(product_id), maj_le = VALUES(maj_le)',
                [$qui, $k, (int) $pid, $now]);
        }
    }
}

/* =================================================================================
   6. LE CLASSEMENT : QUEL CONCURRENT, SUR QUEL FRANCHISÉ
   ================================================================================= */

/**
 * Les concurrents de chaque magasin, avec leur code postal et leur classement : TFBuddy (ce que le benchmark tient),
 * les relevés web du cockpit qui n'y sont pas encore, et les relevés importés qui attendent l'encodage.
 */
function biClassement(): array
{
    $mags = biMagasins();
    $plages = biZones($mags);
    $out = []; $vus = []; $tous = [];
    $lire = BenchmarkApi::disponible();
    foreach ($mags as $id => $m) {
        $L = [];
        $liste = $lire ? BenchmarkApi::concurrents($id, true) : null;
        foreach ((array) $liste as $c) {
            $obs = (array) ($c['observations'] ?? []);
            $dates = array_filter(array_map(fn ($o) => substr((string) ($o['observed_at'] ?? ''), 0, 10), $obs));
            $L[benchmarkNomCle((string) ($c['name'] ?? ''))] = ['nom' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? ''), 'ville' => (string) ($c['city'] ?? ''),
                'cp' => biCpConcurrent($c), 'national' => biNational($c), 'source' => 'tfbuddy', 'observations' => count($obs), 'dernier' => $dates ? max($dates) : null, 'id' => (int) ($c['id'] ?? 0)];
        }
        foreach (array_merge(BENCHMARK_FAMILLES, BENCHMARK_RELEVES) as $f) {
            try { $d = benchmarkDonnees($f); } catch (Throwable $e) { continue; }
            foreach ($d['concurrents'] as $c) {
                if (!in_array($id, array_map('intval', (array) ($c['magasins'] ?? [])), true)) { continue; }
                $k = benchmarkNomCle((string) $c['nom']);
                if (isset($L[$k])) { continue; }
                $ville = (string) (($c['ville'] ?? [])[(string) $id] ?? (($c['ville'] ?? [])['*'] ?? ''));
                $L[$k] = ['nom' => (string) $c['nom'], 'type' => (string) ($c['type'] ?? ''), 'ville' => $ville, 'cp' => (string) ($c['cp'] ?? '') ?: biCpConcurrent(['city' => $ville]),
                    'national' => biNational(['city' => $ville]), 'source' => 'referentiel', 'observations' => 0, 'dernier' => (string) ($d['releve'] ?? ''), 'id' => 0];
            }
        }
        $out[$id] = $L;
    }
    // Les relevés importés qui attendent l'encodage.
    $attente = [];
    try {
        biTables();
        foreach (Db::rows("SELECT id, concurrent, magasins, cree_le FROM ceo_bench_import WHERE statut = 'lu' ORDER BY id DESC LIMIT 60") as $r) {
            $c = json_decode((string) $r['concurrent'], true) ?: [];
            $m = json_decode((string) $r['magasins'], true) ?: [];
            $ligne = ['nom' => (string) ($c['nom'] ?? ''), 'type' => BI_TYPES[$c['type'] ?? ''] ?? '', 'ville' => (string) ($c['ville'] ?? ''), 'cp' => ($c['cp'] ?? '') ?: null,
                'national' => false, 'source' => 'import', 'observations' => 0, 'dernier' => substr((string) $r['cree_le'], 0, 10), 'id' => (int) $r['id']];
            foreach ($m as $sid) { $k = benchmarkNomCle($ligne['nom']); if (isset($out[(int) $sid]) && !isset($out[(int) $sid][$k])) { $out[(int) $sid][$k] = $ligne; } }
            if (!$m) { $attente[] = $ligne; }
        }
    } catch (Throwable $e) { /* pas encore de relevé importé */ }
    $res = [];
    $horsZone = [];
    foreach ($out as $id => $L) {
        $cs = [];
        foreach ($L as $k => $c) {
            $cl = $c['national'] ? ['cp' => null, 'magasins' => [], 'statut' => 'national', 'commune' => ''] : biClasser($c['cp'] ?: null, $plages);
            $c['classement'] = $cl['statut'] === 'zone' ? (in_array($id, $cl['magasins'], true) ? 'zone' : 'ailleurs') : $cl['statut'];
            $c['commune'] = $cl['commune'];
            $c['aussi'] = array_values(array_map(fn ($x) => $mags[$x]['nom'] ?? (string) $x, array_filter($cl['magasins'], fn ($x) => $x !== $id)));
            $cs[] = $c;
            if (in_array($c['classement'], ['hors', 'ailleurs'], true)) { $horsZone[$k . '|' . $id] = $c + ['chez' => $mags[$id]['nom']]; }
        }
        $ordre = ['zone' => 0, 'ailleurs' => 1, 'hors' => 2, 'inconnu' => 3, 'national' => 4];
        usort($cs, static fn ($a, $b) => [$ordre[$a['classement']] ?? 9, $a['cp'] ?? '9999', $a['nom']] <=> [$ordre[$b['classement']] ?? 9, $b['cp'] ?? '9999', $b['nom']]);
        $z = array_values(array_filter($plages, fn ($p) => (int) $p['shopId'] === $id));
        $res[] = $mags[$id] + ['zone' => ['n' => count($z), 'codes' => array_map(fn ($p) => $p['de'] === $p['a'] ? (string) $p['de'] : $p['de'] . '–' . $p['a'], $z)], 'concurrents' => $cs];
    }
    return ['magasins' => $res, 'horsZone' => array_values($horsZone), 'sansMagasin' => $attente, 'lu' => date('Y-m-d H:i'), 'tfbuddy' => $lire];
}

/* =================================================================================
   7. LES ROUTES
   ================================================================================= */

/** GET /benchmarks/import — les relevés importés, les magasins, leurs zones, l'état de l'IA et du panel. */
function ep_bench_import(): array
{
    biTables();
    $mags = biMagasins();
    $plages = biZones($mags);
    $liste = [];
    foreach (Db::rows('SELECT id, cree_le, source, statut, titre, concurrent, magasins, lignes FROM ceo_bench_import ORDER BY id DESC LIMIT 80') as $r) {
        $c = json_decode((string) $r['concurrent'], true) ?: [];
        $liste[] = ['id' => (int) $r['id'], 'creeLe' => substr((string) $r['cree_le'], 0, 16), 'source' => (string) $r['source'], 'statut' => (string) $r['statut'],
            'titre' => (string) $r['titre'], 'concurrent' => (string) ($c['nom'] ?? ''), 'cp' => (string) ($c['cp'] ?? ''),
            'magasins' => array_map(fn ($id) => $mags[(int) $id]['nom'] ?? (string) $id, json_decode((string) $r['magasins'], true) ?: []),
            'lignes' => count(json_decode((string) $r['lignes'], true) ?: [])];
    }
    $s = setting('benchmark', []);
    return ['imports' => $liste, 'magasins' => array_values($mags), 'plages' => $plages, 'zonesParDefaut' => !(is_array($s) && !empty($s['zones'])),
        'ia' => ['configure' => Anthropic::configured(), 'modele' => Anthropic::modeleBenchmark()], 'panel' => BenchmarkApi::disponible(), 'maxOctets' => biMaxOctets()];
}

/** GET /benchmarks/import/{id} — un relevé, rapproché avec le catalogue du magasin. */
function ep_bench_import_fiche(int $id): array
{
    $imp = biLire($id);
    if (!$imp) { http_response_code(404); return ['error' => 'relevé introuvable']; }
    return biVue($imp);
}

/** Les magasins d'un relevé : ceux que le code postal désigne, sinon ceux cochés. */
function biMagasinsPour(array $c, array $saisis): array
{
    $mags = biMagasins();
    $ids = array_values(array_filter(array_map('intval', $saisis), fn ($i) => isset($mags[$i])));
    if ($ids) { return $ids; }
    return biClasser(($c['cp'] ?? '') ?: null, biZones($mags))['magasins'];
}

/** POST /benchmarks/import/pdf {fichier: dataURL, nom, concurrent?: {nom, cp, ville, type}, magasins?: int[], date?} */
function wr_bench_import_pdf(): array
{
    @set_time_limit(300);
    $b = body();
    $data = (string) ($b['fichier'] ?? '');
    if (!preg_match('#^data:application/(?:pdf|x-pdf|octet-stream);base64,#', $data)) { http_response_code(400); return ['ok' => false, 'error' => 'un fichier PDF est attendu']; }
    $oct = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($oct === false || !str_starts_with($oct, '%PDF')) { http_response_code(400); return ['ok' => false, 'error' => 'ce fichier n’est pas un PDF lisible']; }
    if (strlen($oct) > biMaxOctets()) { http_response_code(413); return ['ok' => false, 'error' => 'PDF trop lourd (' . round(strlen($oct) / 1048576, 1) . ' Mo, ' . round(biMaxOctets() / 1048576, 1) . ' Mo au plus)']; }
    if (!Anthropic::configured()) { http_response_code(503); return ['ok' => false, 'error' => 'clé Anthropic absente : Paramètres › IA']; }
    biTables();
    $emp = hash('sha256', $oct);
    $deja = Db::row("SELECT id FROM ceo_bench_import WHERE empreinte = ? AND source = 'pdf' ORDER BY id DESC LIMIT 1", [$emp]);
    if ($deja) { return ['ok' => true, 'id' => (int) $deja['id'], 'deja' => true]; }
    $nom = mb_substr(basename(str_replace('\\', '/', (string) ($b['nom'] ?? 'document.pdf'))), 0, 190);
    $saisi = is_array($b['concurrent'] ?? null) ? $b['concurrent'] : [];
    $ctx = trim(implode(' · ', array_filter([($saisi['nom'] ?? '') !== '' ? 'concurrent annoncé : ' . $saisi['nom'] : '', ($saisi['cp'] ?? '') !== '' ? 'code postal annoncé : ' . $saisi['cp'] : ''])));
    $r = Anthropic::releveDepuisPdf($oct, $ctx);
    if ($r['releve'] === null) { http_response_code(502); return ['ok' => false, 'error' => 'lecture du PDF : ' . $r['erreur']]; }
    $fichier = bin2hex(random_bytes(12)) . '.pdf';
    file_put_contents(biDossier() . '/' . $fichier, $oct);
    @chmod(biDossier() . '/' . $fichier, 0600);
    $c = biConcurrentDepuisIa($r['releve'], $saisi);
    $date = BenchmarkApi::date((string) ($b['date'] ?? '')) ?? BenchmarkApi::date((string) ($r['releve']['date_document'] ?? '')) ?? date('Y-m-d');
    $id = biCreer(['source' => 'pdf', 'titre' => ($c['nom'] !== '' ? $c['nom'] : $nom), 'fichier' => $fichier, 'nom_origine' => $nom, 'octets' => strlen($oct), 'empreinte' => $emp,
        'concurrent' => $c, 'magasins' => biMagasinsPour($c, (array) ($b['magasins'] ?? [])), 'date_releve' => $date, 'lignes' => biLignesDepuisIa($r['releve']),
        'remarques' => (string) ($r['releve']['remarques'] ?? ''), 'modele' => $r['modele'], 'jetons' => (int) (($r['usage']['input_tokens'] ?? 0) + ($r['usage']['output_tokens'] ?? 0))]);
    if (function_exists('journalAdd')) { journalAdd('Cockpit', 'benchmark', 'Benchmark › Import', 'PDF « ' . $nom . ' » lu : ' . ($c['nom'] ?: 'concurrent inconnu') . ($c['cp'] ? ' (' . $c['cp'] . ')' : '') . '.'); }
    return ['ok' => true, 'id' => $id];
}

/** POST /benchmarks/import/web {concurrent: nom, lieu: commune ou code postal, site?, consignes?, magasins?: int[]} */
function wr_bench_import_web(): array
{
    @set_time_limit(300);
    $b = body();
    $nom = trim((string) ($b['concurrent'] ?? ''));
    $lieu = trim((string) ($b['lieu'] ?? ''));
    if ($nom === '' || $lieu === '') { http_response_code(400); return ['ok' => false, 'error' => 'le nom du concurrent et sa commune (ou son code postal) sont requis']; }
    if (!Anthropic::configured()) { http_response_code(503); return ['ok' => false, 'error' => 'clé Anthropic absente : Paramètres › IA']; }
    $site = trim((string) ($b['site'] ?? ''));
    $consignes = trim((string) ($b['consignes'] ?? ''));
    $demande = 'Concurrent : ' . mb_substr($nom, 0, 120) . ' — ' . mb_substr($lieu, 0, 80) . ' (Belgique).' . ($site !== '' ? "\nSite connu : " . mb_substr($site, 0, 200) : '')
        . ($consignes !== '' ? "\nÀ chercher en priorité : " . mb_substr($consignes, 0, 300) : '');
    $r = Anthropic::releveDepuisWeb($demande, biCp($lieu) === null ? $lieu : '');
    if ($r['releve'] === null) { http_response_code(502); return ['ok' => false, 'error' => 'recherche internet : ' . $r['erreur']]; }
    $c = biConcurrentDepuisIa($r['releve'], ['nom' => $nom] + (biCp($lieu) !== null ? ['cp' => (string) biCp($lieu)] : []) + ($site !== '' ? ['site' => $site] : []));
    if ($c['ville'] === '' && biCp($lieu) === null) { $c['ville'] = mb_substr($lieu, 0, 80); }
    $sources = array_values(array_unique(array_filter(array_map(fn ($u) => mb_substr(trim((string) $u), 0, 300), (array) ($r['releve']['sources'] ?? [])), fn ($u) => preg_match('#^https?://#i', $u))));
    $id = biCreer(['source' => 'web', 'titre' => $c['nom'] ?: $nom, 'demande' => mb_substr($demande, 0, 500), 'concurrent' => $c,
        'magasins' => biMagasinsPour($c, (array) ($b['magasins'] ?? [])), 'date_releve' => date('Y-m-d'), 'lignes' => biLignesDepuisIa($r['releve']), 'sources' => array_slice($sources, 0, 30),
        'remarques' => (string) ($r['releve']['remarques'] ?? ''), 'modele' => $r['modele'], 'jetons' => (int) (($r['usage']['input_tokens'] ?? 0) + ($r['usage']['output_tokens'] ?? 0))]);
    if (function_exists('journalAdd')) { journalAdd('Cockpit', 'benchmark', 'Benchmark › Import', 'Recherche internet « ' . $nom . ' » (' . $lieu . ') : ' . count((array) ($r['releve']['lignes'] ?? [])) . ' prix trouvé(s).'); }
    return ['ok' => true, 'id' => $id];
}

/** PUT /benchmarks/import {id, concurrent?: {nom, type, adresse, cp, ville, site}, magasins?: int[], date?, choix?: {i: pid | 0 sans équivalent | -1 ignorer | null}} */
function wr_bench_import_maj(): array
{
    $b = body();
    $imp = biLire((int) ($b['id'] ?? 0));
    if (!$imp) { http_response_code(404); return ['ok' => false, 'error' => 'relevé introuvable']; }
    $set = []; $val = [];
    if (is_array($b['concurrent'] ?? null)) {
        $c = $imp['concurrent'];
        foreach (['nom' => 120, 'adresse' => 160, 'ville' => 80, 'site' => 200] as $k => $max) { if (array_key_exists($k, $b['concurrent'])) { $c[$k] = mb_substr(trim((string) $b['concurrent'][$k]), 0, $max); } }
        if (array_key_exists('cp', $b['concurrent'])) { $c['cp'] = (string) (biCp((string) $b['concurrent']['cp']) ?? ''); }
        if (in_array($b['concurrent']['type'] ?? '', array_keys(BI_TYPES), true)) { $c['type'] = $b['concurrent']['type']; }
        $set[] = 'concurrent = ?'; $val[] = json_encode($c, JSON_UNESCAPED_UNICODE);
        $set[] = 'titre = ?'; $val[] = mb_substr($c['nom'] !== '' ? $c['nom'] : (string) $imp['titre'], 0, 190);
        // Un code postal changé reclasse le relevé, sauf si les magasins sont donnés dans la même demande.
        if (($c['cp'] ?? '') !== ($imp['concurrent']['cp'] ?? '') && !isset($b['magasins'])) { $b['magasins'] = biMagasinsPour($c, []); }
    }
    if (isset($b['magasins']) && is_array($b['magasins'])) {
        $mags = biMagasins();
        $set[] = 'magasins = ?'; $val[] = json_encode(array_values(array_unique(array_filter(array_map('intval', $b['magasins']), fn ($i) => isset($mags[$i])))));
    }
    if (array_key_exists('date', $b)) { $set[] = 'date_releve = ?'; $val[] = BenchmarkApi::date((string) $b['date']); }
    if (is_array($b['choix'] ?? null)) {
        $choix = (array) $imp['choix']; $retenir = [];
        $lib = [];
        foreach ((array) $imp['lignes'] as $l) { $lib[(string) $l['i']] = $l['libelle']; }
        foreach ($b['choix'] as $i => $v) {
            if (!isset($lib[(string) $i])) { continue; }
            if ($v === null || $v === '') { unset($choix[(string) $i]); continue; }
            $choix[(string) $i] = (int) $v;
            if ((int) $v > 0) { $retenir[] = [$lib[(string) $i], (int) $v]; }
        }
        $set[] = 'choix = ?'; $val[] = json_encode((object) $choix, JSON_UNESCAPED_UNICODE);
        biRetenir((string) ($imp['concurrent']['nom'] ?? ''), $retenir);
    }
    if ($set) {
        $set[] = 'maj_le = ?'; $val[] = date('Y-m-d H:i:s'); $val[] = (int) $imp['id'];
        Db::exec('UPDATE ceo_bench_import SET ' . implode(', ', $set) . ' WHERE id = ?', $val);
    }
    return ['ok' => true] + biVue(biLire((int) $imp['id']));
}

/** POST /benchmarks/import/encoder {id, apercu?: bool} */
function wr_bench_import_encoder(): array
{
    @set_time_limit(300);
    $b = body();
    $imp = biLire((int) ($b['id'] ?? 0));
    if (!$imp) { http_response_code(404); return ['ok' => false, 'error' => 'relevé introuvable']; }
    $r = biEncoder($imp, !empty($b['apercu']));
    if (!$r['ok'] && isset($r['error'])) { http_response_code(400); }
    return $r;
}

/** DELETE /benchmarks/import/{id} — le relevé et son PDF ; ce qui est déjà encodé chez TFBuddy y reste. */
function wr_bench_import_suppr(int $id): array
{
    $imp = biLire($id);
    if (!$imp) { http_response_code(404); return ['ok' => false, 'error' => 'relevé introuvable']; }
    if (!empty($imp['fichier']) && preg_match('/^[a-f0-9]{24}\.pdf$/', (string) $imp['fichier'])) { try { @unlink(biDossier() . '/' . $imp['fichier']); } catch (Throwable $e) { /* dossier absent */ } }
    Db::exec('DELETE FROM ceo_bench_import WHERE id = ?', [(int) $imp['id']]);
    return ['ok' => true];
}

/** GET /benchmarks/classement — les concurrents de chaque magasin, classés par code postal. */
function ep_bench_classement(): array
{
    return biClassement();
}

/** PUT /benchmarks/zones {plages: [{de, a, shopId, libelle}]} ou {defaut: true} */
function wr_bench_zones(): array
{
    $b = body();
    $mags = biMagasins();
    $s = setting('benchmark', []);
    if (!is_array($s)) { $s = []; }
    if (!empty($b['defaut'])) { unset($s['zones']); }
    else {
        $p = biPlagesNettoyer($b['plages'] ?? [], $mags);
        if ($p === []) { http_response_code(400); return ['ok' => false, 'error' => 'aucune plage valable (code postal de 1000 à 9999, magasin connu)']; }
        $s['zones'] = $p;
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['benchmark', json_encode($s, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'plages' => biZones($mags), 'zonesParDefaut' => empty($s['zones'])];
}
