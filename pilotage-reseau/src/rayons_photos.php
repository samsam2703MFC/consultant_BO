<?php
declare(strict_types=1);

/**
 * LES PHOTOS TYPES DES RAYONS — le comptoir tel qu'il doit être.
 *
 * Chaque jour l'équipe rend une tâche « Photo du comptoir - <rayon> » par
 * rayon (Viennoiseries, Traiteur, Épicerie…), et le consultant la note. Or
 * noter, c'est comparer : rien ne montrait le comptoir attendu, chacun jugeait
 * d'après l'idée qu'il s'en faisait. Le back office pose donc, pour chaque
 * rayon, UNE photo type et une consigne courte ; le volet de notation du
 * cockpit et le contrôle guidé des visites la mettent en face de la photo
 * rendue.
 *
 * Un rayon est désigné par son NOM, celui que le panel écrit après « Photo du
 * comptoir - ». La clé est ce nom réduit (minuscules, sans accent, mots joints
 * par des tirets) : « Pains & Boulangerie » → pains-boulangerie. Le
 * rapprochement d'un libellé avec un rayon tolère le pluriel et un nom
 * raccourci (« Comptoir viennoiserie » → Viennoiseries, « Comptoir pains » →
 * Pains & Boulangerie), jamais un bout de moins de quatre lettres.
 *
 * Table `con_rayon_photo` ; fichiers sous uploads/rayons/.
 */

/** Les rayons que le panel photographie chaque jour (tâches « Photo du comptoir - … »), dans l'ordre du comptoir. */
const RV_DEFAUT = ['Pains & Boulangerie', 'Viennoiseries', 'Pâtisseries', 'Tartes', 'Quiches', 'Traiteur', 'Biscuiterie', 'Épicerie', 'Boissons'];

/**
 * CE QU'EST UN COMPTOIR CONFORME, pour la proposition de note de l'IA (Anthropic::noterPhoto). Les règles
 * communes valent pour tous les rayons ; pour chaque rayon, la consigne posée au back office PRIME, et ces
 * critères par défaut ne servent que tant qu'elle est vide. Ils viennent des photos rendues (quiches et tartes
 * vendues au quart, d'après l'affiche de Halle ; épicerie sur étagère ; boissons en frigo) : à valider par le
 * réseau, et à remplacer par la consigne du rayon dès qu'elle est écrite.
 */
const RV_CRITERES_COMMUNS = "Un comptoir conforme est garni (pas de plateau ni d'emplacement vide), rangé, propre, et sans objet"
    . " étranger à la vente (outil, ventilateur, carton, sac, chiffon, téléphone).";
const RV_CRITERES_IA = [
    'pains-boulangerie' => 'Pains et baguettes présentés en nombre, paniers et étagères remplis.',
    'viennoiseries'     => 'Plaques remplies, viennoiseries entières et rangées par sorte.',
    'patisseries'       => 'Plaques remplies, pâtisseries entières, sans produit abîmé ni affaissé.',
    'tartes'            => "Comptoir garni de tartes sur toute sa longueur. Les tartes se vendent aussi au quart : une tarte"
        . " entamée n'est pas un écart.",
    'quiches'           => "Comptoir garni de quiches. Les quiches se vendent aussi au quart : une quiche entamée n'est pas un"
        . " écart. Le papier de cuisson sous les quiches fait partie de la présentation.",
    'traiteur'          => 'Vitrine réfrigérée remplie, bacs et barquettes alignés.',
    'biscuiterie'       => 'Boîtes, sachets et biscuits rangés, présentoir rempli.',
    'epicerie'          => "L'épicerie est une étagère, pas un comptoir : étagères remplies, produits alignés face visible.",
    'boissons'          => 'Le rayon boissons est un frigo : étagères remplies, bouteilles et canettes alignées, vitre propre.',
];

/**
 * LA CONSIGNE GÉNÉRALE DES CONTRÔLES QUALITÉ (18/11/2026, demande du CEO : « un prompt général pour les contrôles
 * qualité : toujours prendre une photo de face aux produits pour comparer avec le planogramme, vérifier si la
 * quantité et le sens des produits sont comme dans le planogramme ; ce champ doit être éditable, avec des points
 * pour chaque nouveau point à prendre en compte »).
 *
 * Une liste de points, envoyée à l'IA avec CHAQUE photo de contrôle (« aussi pour les autres photos de contrôle »,
 * 18/11) : pour une photo de comptoir, AVANT la consigne du rayon (qui prime pour ce qui touche au rayon) ; pour une
 * autre tâche, seule, avec une phrase qui dit qu'elle n'a pas de planogramme. Elle se modifie dans Suivi › Entraînement de l'IA (PUT /ia/consigne-generale) et se garde
 * dans les réglages (`cqConsigneGenerale`) ; jamais modifiée, c'est la liste par défaut ci-dessous qui vaut. La
 * règle commune d'avant (RV_CRITERES_COMMUNS) en est le premier point.
 */
const CQ_CONSIGNE_GENERALE_DEFAUT = [
    RV_CRITERES_COMMUNS,
    "La photo doit être prise de face, face aux produits, pour être comparée au planogramme : une photo de biais, de"
        . " trop loin ou qui ne montre qu'une partie du comptoir ne permet pas la comparaison — le dire dans les constats.",
    'Vérifier que la quantité de produits et leur sens (orientation, face visible) sont ceux du planogramme.',
];
const CQ_POINTS_MAX = 20;          // points au plus
const CQ_POINT_LONGUEUR = 400;     // caractères au plus par point

/** Les points de la consigne générale : ceux enregistrés, sinon la liste par défaut. */
function cqConsigneGenerale(): array
{
    $v = function_exists('setting') ? setting('cqConsigneGenerale', null) : null;
    if (!is_array($v)) { return CQ_CONSIGNE_GENERALE_DEFAUT; }
    return array_values(array_filter(array_map(static fn ($p) => trim((string) $p), $v), static fn (string $p) => $p !== ''));
}

/** Un point saisi, nettoyé : sans puce ni numéro en tête, espaces resserrés. */
function cqPoint(mixed $p): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', (string) $p));
    return trim((string) preg_replace('/^(?:[•\-*–·]+|\d+[.)])\s*/u', '', $t));
}

/** Pour une tâche qui n'est pas une photo de comptoir : elle n'a pas de planogramme. */
const CQ_SANS_PLANOGRAMME = "Cette tâche n'est pas une photo de comptoir : elle n'a pas de planogramme, les points qui s'y comparent ne"
    . " s'appliquent pas.";

/**
 * Les critères que l'IA reçoit pour une photo de contrôle : la consigne générale, puis, pour une photo de comptoir
 * ($rv), la consigne du rayon ; '' quand la consigne générale est vide et que la tâche n'a pas de rayon.
 */
function rvCriteresIa(?array $rv): string
{
    if ($rv === null) {
        $points = cqConsigneGenerale();
        return $points !== [] ? "Consigne générale des contrôles qualité :\n- " . implode("\n- ", $points) . "\n" . CQ_SANS_PLANOGRAMME : '';
    }
    $propre = trim((string) ($rv['consigne'] ?? ''));
    $rayon = $propre !== '' ? $propre : (RV_CRITERES_IA[(string) ($rv['cle'] ?? '')] ?? '');
    $points = cqConsigneGenerale();
    $general = $points !== [] ? "Consigne générale des contrôles qualité :\n- " . implode("\n- ", $points) : '';
    $rayonTxt = $rayon !== '' ? 'Rayon « ' . (string) ($rv['nom'] ?? '') . ' » : ' . $rayon : '';
    return implode("\n", array_filter([$general, $rayonTxt], static fn (string $t) => $t !== ''));
}

function ensureRayonsPhotos(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS con_rayon_photo ('
        . 'cle VARCHAR(80) NOT NULL PRIMARY KEY,'
        . 'nom VARCHAR(120) NOT NULL,'
        . 'photo VARCHAR(255) NULL,'
        . 'consigne VARCHAR(600) NULL,'
        . 'par VARCHAR(120) NULL,'
        . 'le DATETIME NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Un libellé réduit pour la comparaison : minuscules, sans accent, mots séparés d'une espace. */
function rvNorm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** La clé d'un rayon : son nom réduit, mots joints par des tirets. */
function rvCle(string $nom): string
{
    return mb_substr(str_replace(' ', '-', rvNorm($nom)), 0, 80);
}

/** Les rayons du back office : ceux du panel d'abord, dans l'ordre du comptoir, puis ceux ajoutés. */
function rvListe(): array
{
    $rows = [];
    try {
        ensureRayonsPhotos();
        foreach (Db::rows('SELECT * FROM con_rayon_photo') as $r) { $rows[(string) $r['cle']] = $r; }
    } catch (PDOException $e) { /* pas de base : les rayons du panel, sans photo */ }
    $ligne = static function (string $cle, string $nom, ?array $r, bool $defaut): array {
        $photo = $r !== null && $r['photo'] !== null && $r['photo'] !== '' ? (string) $r['photo'] : null;
        $consigne = $r !== null && $r['consigne'] !== null && trim((string) $r['consigne']) !== '' ? (string) $r['consigne'] : null;
        return ['cle' => $cle, 'nom' => $r !== null && trim((string) $r['nom']) !== '' ? (string) $r['nom'] : $nom,
            'photo' => $photo, 'consigne' => $consigne,
            'par' => $r !== null && $r['par'] !== null ? (string) $r['par'] : null,
            'le' => $r !== null && $r['le'] !== null ? (string) $r['le'] : null, 'defaut' => $defaut];
    };
    $out = [];
    foreach (RV_DEFAUT as $nom) {
        $cle = rvCle($nom);
        $out[] = $ligne($cle, $nom, $rows[$cle] ?? null, true);
        unset($rows[$cle]);
    }
    uasort($rows, static fn ($a, $b) => strcmp(rvNorm((string) $a['nom']), rvNorm((string) $b['nom'])));
    foreach ($rows as $cle => $r) { $out[] = $ligne((string) $cle, (string) $r['nom'], $r, false); }
    return $out;
}

/**
 * Le rayon d'un libellé — une tâche « Photo du comptoir - Viennoiseries », un
 * point de checklist « Comptoir viennoiserie » — ou null.
 *
 * Le nom exact (au pluriel près) l'emporte ; sinon le plus long des rayons dont
 * le nom commence le libellé, ou que le libellé commence, à la frontière d'un
 * mot (« Tartes » ne prend pas « Tartelettes »).
 */
function rvRayonDe(string $libelle, ?array $rayons = null): ?array
{
    // « Photo du comptoir après-midi - Pâtisseries » : le moment de la journée n'est pas le rayon.
    $reste = (string) preg_replace('/^(photo du )?comptoirs?\s*(?:(?:apres midi|matin|midi|soir)\s+)?/', '', rvNorm($libelle));
    if ($reste === '') { return null; }
    $sing = static fn (string $x): string => (string) preg_replace('/s\b/', '', $x);
    $prefixe = null; $long = 0;
    foreach ($rayons ?? rvListe() as $r) {
        $nz = rvNorm((string) $r['nom']);
        if ($nz === '') { continue; }
        if ($reste === $nz || $sing($reste) === $sing($nz)) { return $r; }
        $a = $sing($reste); $b = $sing($nz);
        if (strlen($a) >= 4 && strlen($b) >= 4 && (str_starts_with($a, $b . ' ') || str_starts_with($b, $a . ' ')) && strlen($b) > $long) {
            $prefixe = $r; $long = strlen($b);
        }
    }
    return $prefixe;
}

/** Le rayon d'une tâche du panel, seulement si c'est une photo de comptoir. */
function rvRayonDeTache(string $tache): ?array
{
    return preg_match('/^photo du comptoir/', rvNorm($tache)) ? rvRayonDe($tache) : null;
}

/** GET /rayons/photos — chaque rayon, sa photo type et sa consigne. */
function ep_rayons_photos(): array
{
    $l = rvListe();
    return ['rayons' => $l, 'total' => count($l), 'poses' => count(array_filter($l, static fn ($r) => $r['photo'] !== null)),
        'source' => 'photos types posées dans le cockpit ; un rayon = le nom que le panel écrit après « Photo du comptoir - »'];
}

/**
 * PUT /rayons/photos — {nom, data? (data-URL image | '' pour retirer la photo), consigne?, retirer?}.
 *
 * Seuls les champs présents changent. `retirer` efface la ligne : un rayon du
 * panel revient sans photo, un rayon ajouté disparaît. Rend la liste entière.
 */
function wr_rayons_photos_put(): array
{
    ensureRayonsPhotos();
    $b = body();
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 120);
    $cle = rvCle($nom);
    if ($cle === '') { http_response_code(422); return ['error' => 'le nom du rayon est requis']; }
    $avant = Db::row('SELECT * FROM con_rayon_photo WHERE cle = ?', [$cle]);
    $efface = static function (?array $r): void {
        $f = $r !== null && !empty($r['photo']) ? __DIR__ . '/../public/' . $r['photo'] : null;
        if ($f !== null && is_file($f)) { @unlink($f); }
    };
    if (!empty($b['retirer'])) {
        $efface($avant);
        Db::exec('DELETE FROM con_rayon_photo WHERE cle = ?', [$cle]);
        journalAdd('CEO', 'Contrôle des tâches', 'Rayon ' . $nom, 'Photo type et consigne retirées');
        return ep_rayons_photos();
    }
    $photo = $avant['photo'] ?? null;
    $consigne = $avant['consigne'] ?? null;
    $faits = [];
    if (array_key_exists('data', $b)) {
        $data = (string) $b['data'];
        if (trim($data) === '') {
            $efface($avant); $photo = null; $faits[] = 'photo type retirée';
        } else {
            // Un nom de fichier neuf à chaque dépôt : l'ancienne image ne reste pas en cache sous la même adresse.
            $f = psImage($data, 'uploads/rayons/' . $cle . '-' . date('YmdHis'));
            if (isset($f['error'])) { http_response_code($f['code']); return ['error' => $f['error']]; }
            $efface($avant); $photo = $f['chemin']; $faits[] = 'photo type déposée';
        }
    }
    if (array_key_exists('consigne', $b)) {
        $c = mb_substr(trim((string) $b['consigne']), 0, 600);
        $consigne = $c !== '' ? $c : null;
        $faits[] = $c !== '' ? 'consigne mise à jour' : 'consigne retirée';
    }
    Db::exec('INSERT INTO con_rayon_photo (cle, nom, photo, consigne, par, le) VALUES (?,?,?,?,?,?)'
        . ' ON DUPLICATE KEY UPDATE nom = VALUES(nom), photo = VALUES(photo), consigne = VALUES(consigne), par = VALUES(par), le = VALUES(le)',
        [$cle, $nom, $photo, $consigne, psAuteur(), date('Y-m-d H:i:s')]);
    journalAdd('CEO', 'Contrôle des tâches', 'Rayon ' . $nom, $faits ? ucfirst(implode(', ', $faits)) : 'Rayon ajouté');
    return ep_rayons_photos();
}
