<?php
declare(strict_types=1);

/**
 * L'import rétroactif du fil du franchisé (19/11/2026, Sam : « option B » — remplir les onze derniers mois du fil
 * avec le courrier). Les entrées sont préparées hors du cockpit (lues dans Gmail, Drive, l'agenda ou une discussion
 * WhatsApp, puis relues par Sam), dans un fichier JSON par magasin :
 *
 *   {"shop": "3", "magasin": "Gosselies", "auteur": "Import courrier",
 *    "fils": {"royalties-2025": {"titre": "Régularisation 2025 et royalties"}},
 *    "entrees": [{"le": "2026-06-18 15:33", "type": "note", "texte": "…", "tags": ["gestion", "finances"],
 *                 "fil": "royalties-2025", "ferme": false, "source": {"type": "gmail", "titre": "…"}}]}
 *
 * Chaque entrée passe par filEntreeCreer, comme celles posées à l'écran. Une clé de fil (« fil ») relie les entrées
 * d'une même affaire : la première ouvre le fil (elle porte « gestion », seul un problème de gestion en ouvre un), les
 * suivantes le prolongent, celle qui porte « ferme » le résout. La source (type et titre, jamais l'adresse) termine le
 * texte : on sait d'où vient l'entrée. Rejouer un fichier n'écrit rien deux fois (même magasin, même heure, même
 * texte) ; « source », « confiance » et les autres champs de relecture ne sont pas enregistrés.
 *
 * Le fichier ne passe pas par Git (c'est du courrier) : il est déposé sur le serveur, puis lu par bin/fil_import.php.
 */

const FIL_IMPORT_TYPES = ['note', 'appel'];
const FIL_IMPORT_SOURCES = ['gmail' => 'Gmail', 'drive' => 'Drive', 'agenda' => 'Agenda', 'whatsapp' => 'WhatsApp', 'appel' => 'Appel'];

/** Le texte enregistré : celui de l'entrée, puis sa source (type et titre). Fonction pure. */
function filImportTexte(string $texte, mixed $source): string
{
    $t = trim($texte);
    if (!is_array($source)) { return $t; }
    $type = FIL_IMPORT_SOURCES[strtolower((string) ($source['type'] ?? ''))] ?? '';
    $titre = trim((string) preg_replace('/\s+/u', ' ', (string) ($source['titre'] ?? '')));
    if ($type === '' && $titre === '') { return $t; }
    return $t . "\n\nSource : " . ($type !== '' ? $type : 'document') . ($titre !== '' ? ' — « ' . mb_substr($titre, 0, 160) . ' »' : '');
}

/**
 * Lit et vérifie un fichier d'import. Rend [document normalisé, erreurs] ; une seule erreur et rien ne s'écrit.
 * Fonction pure (le magasin est vérifié à l'écriture).
 */
function filImportLire(mixed $doc): array
{
    $err = [];
    if (!is_array($doc) || !is_array($doc['entrees'] ?? null)) { return [null, ['fichier illisible : « entrees » attendu']]; }
    $shop = trim((string) ($doc['shop'] ?? ''));
    if ($shop === '') { $err[] = '« shop » manquant'; }
    $auteur = trim((string) ($doc['auteur'] ?? '')) ?: 'Import';
    $titres = [];
    foreach (is_array($doc['fils'] ?? null) ? $doc['fils'] : [] as $cle => $f) {
        $titres[(string) $cle] = trim((string) (is_array($f) ? ($f['titre'] ?? '') : $f));
    }
    $sens = array_keys(array_filter(FIL_TAGS, static fn ($t) => $t['sens'] !== ''));
    $E = [];
    foreach (array_values($doc['entrees']) as $i => $e) {
        $n = 'entrée ' . ($i + 1);
        if (!is_array($e)) { $err[] = "$n : illisible"; continue; }
        $le = filDateHeure($e['le'] ?? null);
        if ($le === null) { $err[] = "$n : date illisible"; }
        $type = (string) ($e['type'] ?? 'note');
        if (!in_array($type, FIL_IMPORT_TYPES, true)) { $err[] = "$n : type « $type » refusé (note ou appel)"; }
        $brut = is_array($e['tags'] ?? null) ? array_map('strval', $e['tags']) : [];
        $inconnues = array_diff($brut, array_keys(FIL_TAGS));
        if ($inconnues !== []) { $err[] = "$n : étiquette inconnue (" . implode(', ', $inconnues) . ')'; }
        $tags = filTags($brut);
        if (count(array_intersect($tags, $sens)) > 1) { $err[] = "$n : une seule étiquette de sens (" . implode(', ', array_intersect($tags, $sens)) . ')'; }
        $texte = filImportTexte((string) ($e['texte'] ?? ''), $e['source'] ?? null);
        if (trim((string) ($e['texte'] ?? '')) === '') { $err[] = "$n : texte vide"; }
        if (mb_strlen($texte) > FIL_TEXTE_MAX) { $err[] = "$n : texte trop long"; }
        $cle = isset($e['fil']) && $e['fil'] !== null && $e['fil'] !== '' ? (string) $e['fil'] : null;
        $E[] = ['i' => $i, 'le' => $le ?? '', 'type' => $type, 'texte' => $texte, 'tags' => $tags, 'fil' => $cle,
            'ferme' => !empty($e['ferme']), 'epingle' => !empty($e['epingle']), 'auteur' => trim((string) ($e['auteur'] ?? '')) ?: $auteur];
    }
    // L'ordre du fil : la date, puis l'ordre du fichier.
    usort($E, static fn ($a, $b) => strcmp($a['le'], $b['le']) ?: $a['i'] <=> $b['i']);
    $vus = []; $fermes = [];
    foreach ($E as $e) {
        $n = 'entrée ' . ($e['i'] + 1);
        if ($e['fil'] === null) {
            if ($e['ferme']) { $err[] = "$n : « ferme » sans fil"; }
            continue;
        }
        if (isset($fermes[$e['fil']])) { $err[] = "$n : le fil « {$e['fil']} » est déjà fermé"; }
        if (!isset($vus[$e['fil']])) {
            if (!in_array('gestion', $e['tags'], true)) { $err[] = "$n : la première entrée du fil « {$e['fil']} » doit porter « gestion »"; }
            if ($e['ferme']) { $err[] = "$n : le fil « {$e['fil']} » ne peut pas se fermer à son ouverture"; }
            $vus[$e['fil']] = true;
        }
        if ($e['ferme']) { $fermes[$e['fil']] = true; }
    }
    return [$err === [] ? ['shop' => $shop, 'magasin' => (string) ($doc['magasin'] ?? $shop), 'titres' => $titres, 'entrees' => $E] : null, $err];
}

/**
 * Applique un fichier lu par filImportLire : à blanc par défaut (ce qui serait écrit), pour de vrai avec $ecrire.
 * Rend le compte rendu : à écrire ou écrites, déjà présentes, fils ouverts et fermés, erreur qui a tout arrêté.
 */
function filImportAppliquer(array $doc, bool $ecrire): array
{
    filTables();
    $shop = $doc['shop'];
    $cr = ['shop' => $shop, 'magasin' => $doc['magasin'], 'nouvelles' => 0, 'deja' => 0, 'filsOuverts' => [], 'filsFermes' => [], 'erreur' => null];
    if (!filMagasinConnu($shop)) { $cr['erreur'] = 'magasin inconnu : ' . $shop; return $cr; }
    $fils = [];
    foreach ($doc['entrees'] as $e) {
        $deja = Db::row('SELECT id, fil_id FROM con_fil_entree WHERE shop_id = ? AND le = ? AND texte = ?', [$shop, $e['le'], $e['texte']]);
        if ($deja !== null) {
            $cr['deja']++;
            if ($e['fil'] !== null && !isset($fils[$e['fil']]) && $deja['fil_id'] !== null) { $fils[$e['fil']] = (int) $deja['fil_id']; }
            continue;
        }
        $cr['nouvelles']++;
        $corps = ['shop' => $shop, 'le' => $e['le'], 'type' => $e['type'], 'texte' => $e['texte'], 'tags' => $e['tags'],
            'epingle' => $e['epingle'], 'auteur' => $e['auteur']];
        if ($e['fil'] !== null) {
            if (isset($fils[$e['fil']])) { $corps['fil'] = $fils[$e['fil']] > 0 ? $fils[$e['fil']] : null; }
            else { $corps['titreFil'] = $doc['titres'][$e['fil']] ?? ''; }
            if ($e['ferme']) { $corps['ferme'] = true; $cr['filsFermes'][] = $e['fil']; }
        }
        if (!$ecrire) {
            if ($e['fil'] !== null && !isset($fils[$e['fil']])) { $fils[$e['fil']] = 0; $cr['filsOuverts'][] = $e['fil']; }
            continue;
        }
        $r = filEntreeCreer($corps);
        if (isset($r['error'])) { $cr['nouvelles']--; $cr['erreur'] = 'entrée du ' . $e['le'] . ' : ' . $r['error']; return $cr; }
        if ($e['fil'] !== null && !empty($r['filOuvert'])) { $fils[$e['fil']] = (int) $r['filOuvert']; $cr['filsOuverts'][] = $e['fil']; }
    }
    return $cr;
}
