<?php
declare(strict_types=1);

/*
 * LA RECHERCHE DE LA FORMATION (22/11/2026, Sam : « dans Formations et suivi, tomber sur l'accueil de la formation, sans
 * le rail, et une méga search bar qui reprend tout le catalogue et son contenu : un badge rectangle aux coins arrondis
 * au nom et à la couleur du catalogue, ce qui est trouvé, le lien vers où il mène, la photo si nécessaire »).
 * Maquettes : formation/docs/maquettes/formations-et-suivi-v2/.
 *
 * GET /formation/recherche → l'index de tout ce qui s'apprend, lu une fois par la page et filtré dans le navigateur :
 *   - docs : les documents du Manuel (articles, SOP, mémos, fiches, checklists) et les formations continues publiées —
 *     code, titre, objectif, le texte de leurs étapes (pour chercher dedans), leurs premières étapes, leur première photo ;
 *   - formations : les formations FO-xx (titre, famille, postes) ;
 *   - domaines : les domaines du manuel du réseau et leurs sous-catégories ;
 *   - catalogues : les six catalogues, leur couleur (celle de la couverture, formation/public/formation/catalogue/).
 * Les brouillons n'y sont pas ; une formation continue n'y entre que publiée (comme dans le Manuel). Lecture seule : le
 * contenu d'un document s'ouvre ensuite par la route publique de la tablette (/formation/sop/tablette/sop/{id}).
 */

/** Les catalogues : clé, nom, couleur et encre (les couvertures du catalogue des manuels), ce qu'ils rassemblent. */
const FR_CATALOGUES = [
    ['cle' => 'fo', 'nom' => 'Formations', 'couleur' => '#FFFFFF', 'encre' => '#8D1D2C', 'contour' => true, 'manuel' => null],
    ['cle' => 'man', 'nom' => 'Manuel opératoire', 'couleur' => '#8D1D2C', 'encre' => '#F5EFE4', 'contour' => false, 'manuel' => 'operatoire'],
    ['cle' => 'std', 'nom' => 'Standards (SOP)', 'couleur' => '#222222', 'encre' => '#F5EFE4', 'contour' => false, 'manuel' => 'standards'],
    ['cle' => 'chk', 'nom' => 'Checklists', 'couleur' => '#78554B', 'encre' => '#F5EFE4', 'contour' => false, 'manuel' => 'checklists'],
    ['cle' => 'res', 'nom' => 'Manuel du réseau', 'couleur' => '#F2C9A0', 'encre' => '#222222', 'contour' => false, 'manuel' => 'reseau'],
    ['cle' => 'fc', 'nom' => 'Formation continue', 'couleur' => '#FFF8F1', 'encre' => '#7A3E12', 'contour' => true, 'manuel' => null],
];
/** Le catalogue de chaque type de document (catalogue.js : MANUELS[].types). */
const FR_TYPE_CATALOGUE = ['Article' => 'man', 'SOP' => 'std', 'Mémo' => 'std', 'Fiche' => 'std', 'Checklist' => 'chk', 'FC' => 'fc'];
const FR_TEXTE_MAX = 6000;   // le texte gardé par document, pour chercher dedans

/** Un texte sur une ligne, sans balise. */
function frPlat(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($s, ENT_QUOTES, 'UTF-8'))));
}

/** GET /formation/recherche */
function ep_formation_recherche(): array
{
    $docs = [];
    $rows = Db::rows("SELECT * FROM for_sop WHERE statut <> 'brouillon' AND (type <> 'FC' OR statut = 'publiee') ORDER BY dossier, ordre, id");
    foreach ($rows as $r) {
        $type = (string) $r['type'];
        $cat = FR_TYPE_CATALOGUE[$type] ?? 'std';
        try { $c = foContenuLangue(foContenuVersion((string) $r['id'], (string) $r['version']), 'fr', false); } catch (Throwable $e) { $c = null; }
        $etapes = []; $texte = []; $photo = null; $n = 0;
        foreach ((array) ($c['sections'] ?? []) as $sec) {
            if (($sec['titre'] ?? '') !== '') { $texte[] = $sec['titre']; }
            foreach ((array) ($sec['etapes'] ?? []) as $e) {
                $n++;
                $t = frPlat((string) ($e['titre'] ?? ''));
                $texte[] = $t . ' ' . frPlat((string) ($e['texte'] ?? ''));
                if (count($etapes) < 4) { $etapes[] = ['titre' => $t !== '' ? $t : mb_substr(frPlat((string) ($e['texte'] ?? '')), 0, 120), 'critique' => !empty($e['critique'])]; }
                if ($photo === null && !empty($e['photo'])) { $photo = (string) $e['photo']; }
            }
        }
        foreach ((array) ($c['points_critiques'] ?? []) as $p) { $texte[] = frPlat((string) $p); }
        $titre = frPlat((string) ($c['titre'] ?? '')) ?: ((string) ($r['titre_fr'] ?? '') ?: (string) $r['id']);
        $docs[] = ['id' => (string) $r['id'], 'cat' => $cat, 'type' => $type, 'code' => (string) $r['code'], 'titre' => $titre,
            'objectif' => frPlat((string) ($c['objectif'] ?? '')), 'pour' => frPlat((string) ($c['pour'] ?? '')),
            'texte' => mb_substr(implode(' · ', array_filter($texte)), 0, FR_TEXTE_MAX), 'etapes' => $etapes, 'nbEtapes' => $n,
            'photo' => $photo, 'poste' => (string) ($r['poste'] ?? ''), 'moment' => (string) ($r['moment'] ?? ''),
            'acces' => (string) ($r['acces'] ?? 'tous'), 'statut' => (string) $r['statut'], 'categorie' => isset($r['categorie']) ? (string) $r['categorie'] : null];
    }
    // Les formations FO-xx et les domaines du manuel du réseau : le référentiel (src/referentiel.php).
    $formations = []; $domaines = [];
    try {
        $ref = function_exists('ep_rf_ref') ? ep_rf_ref() : [];
        foreach ((array) ($ref['formations'] ?? []) as $f) {
            $formations[] = ['code' => (string) $f['code'], 'titre' => (string) (is_array($f['fr'] ?? null) ? ($f['fr']['titre'] ?? '') : ($f['fr'] ?? '')),
                'famille' => (string) ($f['famille'] ?? ''), 'postes' => array_values(array_map('strval', (array) ($f['postes'] ?? []))),
                'phases' => array_values(array_map(static fn ($p) => is_array($p) ? (string) ($p['code'] ?? $p['cle'] ?? '') : (string) $p, (array) ($f['phases'] ?? []))),
                'competences' => count((array) ($f['competences'] ?? []))];
        }
        foreach ((array) ($ref['domaines'] ?? []) as $d) {
            $domaines[] = ['code' => (string) $d['code'], 'titre' => (string) $d['fr'],
                'souscats' => array_values(array_map(static fn ($s) => (string) ($s['fr'] ?? $s['nom'] ?? $s['cle'] ?? ''), (array) ($d['souscats'] ?? [])))];
        }
    } catch (Throwable $e) { /* le référentiel n'est pas là : la recherche garde les documents */ }
    $n = array_count_values(array_column($docs, 'cat'));
    $cats = array_map(static fn ($c) => $c + ['n' => match ($c['cle']) { 'fo' => count($formations), 'res' => count($domaines), default => $n[$c['cle']] ?? 0 }], FR_CATALOGUES);
    $types = array_count_values(array_column($docs, 'type'));
    return ['genere' => date('Y-m-d H:i'), 'catalogues' => $cats, 'types' => $types, 'docs' => $docs, 'formations' => $formations, 'domaines' => $domaines];
}
