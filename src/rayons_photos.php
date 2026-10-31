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
    $reste = (string) preg_replace('/^(photo du )?comptoirs?\s*/', '', rvNorm($libelle));
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
