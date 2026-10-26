#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du Suivi des franchisés — sans base, sans réseau.
 *
 *   php bin/tests_suivi.php
 *
 * Ce qui doit rester vrai quoi qu'on règle dans « Qui fait quoi » : le rendu des
 * courriels (paragraphes, listes, variable vide, lien), les modèles livrés dans les
 * quatre langues, la date longue de chaque langue, la note du client mystère ramenée
 * sur 20, la langue d'un magasin d'après sa région, le jeton de la page du franchisé.
 *
 * Le code lit la base par `Db` et les réglages par `setting()` : des doublures
 * minimales les remplacent le temps du test.
 */

// --- doublures : pas de base, pas de réglage, pas de journal ---------------------
final class Db
{
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return null; }
    public static function exec(string $sql, array $a = []): void {}
}
$REGLAGES = ['visitesJeton' => 'jeton-de-test'];
function setting(string $k, mixed $d = null): mixed { global $REGLAGES; return $REGLAGES[$k] ?? $d; }
function journalAdd(string $actor, string $kind, ?string $project, string $message): void {}
function body(): array { return []; }
function viMagasins(): array
{
    return ['2' => ['id' => '2', 'court' => 'Corbais', 'nom' => "L'Atelier by - Corbais", 'ville' => 'Corbais'],
        '4' => ['id' => '4', 'court' => 'Halle', 'nom' => "L'Atelier by - Halle", 'ville' => 'Halle'],
        '9' => ['id' => '9', 'court' => 'Eupen', 'nom' => "L'Atelier by - Eupen", 'ville' => 'Eupen'],
        '10' => ['id' => '10', 'court' => 'Gent', 'nom' => "L'Atelier by - Gent", 'ville' => 'Gent'],
        '11' => ['id' => '11', 'court' => 'Halleux', 'nom' => "L'Atelier by - Halleux", 'ville' => 'Halleux']];
}
// Les types de visite viennent du cadre (visites_cadre.php) : suTypesParCode les lit par vcTypes().
function vcTypes(): array { return [['code' => 'reguliere', 'nom' => 'Régulière'], ['code' => 'flash', 'nom' => 'Contrôle flash']]; }

require __DIR__ . '/../src/suivi.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- 1. le rendu d'un courriel -------------------------------------------------------
echo "Rendu des courriels\n";
$m = ['sujet' => "Visite du {date}\n— {magasin}", 'corps' => "Bonjour,\n\n{consultant} passera le {date}.\nType : {type}.\n\n{sujets}\n\n{piece}\n\nVotre page : {lien}\n\n{inconnue} reste tel quel."];
$r = suRendre($m, ['date' => 'lundi 26 octobre', 'magasin' => 'Halle', 'consultant' => 'Sam <V>', 'type' => 'Régulière', 'sujets' => ['La vitrine', 'Le comptoir'], 'piece' => '', 'lien' => 'https://x.test/suivi/?shop=4&jeton=a'], 'fr');
verifie('le sujet tient sur une ligne', $r['sujet'] === 'Visite du lundi 26 octobre — Halle', $r['sujet']);
verifie('une liste seule sur son paragraphe devient des puces', str_contains($r['html'], '<li style="margin:0 0 4px">La vitrine</li>') && str_contains($r['texte'], "- La vitrine\n- Le comptoir"));
verifie('une variable vide seule sur son paragraphe disparaît', !str_contains($r['texte'], '{piece}') && substr_count($r['html'], '<p ') === 4, (string) substr_count($r['html'], '<p '));
verifie('le lien devient un lien, échappé', str_contains($r['html'], '<a href="https://x.test/suivi/?shop=4&amp;jeton=a"'));
verifie('les valeurs sont échappées dans le HTML', str_contains($r['html'], 'Sam &lt;V&gt;') && str_contains($r['texte'], 'Sam <V>'));
verifie('le retour à la ligne d’un paragraphe garde sa ligne', str_contains($r['html'], 'octobre.<br>'));
verifie('une variable inconnue reste telle quelle', str_contains($r['texte'], '{inconnue} reste tel quel.'));
$r = suRendre(['sujet' => 'x', 'corps' => "Points :\n\n{points}"], ['points' => []], 'nl');
verifie('une liste vide dit pourquoi, dans la langue', str_contains($r['texte'], 'Geen werkpunten.'), $r['texte']);
$r = suRendre(['sujet' => 'x', 'corps' => 'Sujets : {sujets}.'], ['sujets' => ['A', 'B']], 'fr');
verifie('au fil d’une phrase, une liste se lit avec des virgules', $r['texte'] === 'Sujets : A, B.', $r['texte']);

// --- 2. les modèles livrés -------------------------------------------------------------
echo "Modèles livrés\n";
$def = suModelesDefaut();
verifie('les six modèles sont livrés', array_keys($def) === SU_MODELES, implode(',', array_keys($def)));
$trous = []; $etrangeres = [];
foreach ($def as $code => $mo) {
    foreach (array_keys(SU_LANGUES) as $l) {
        $t = $mo['textes'][$l] ?? null;
        if (!$t || trim($t['sujet']) === '' || trim($t['corps']) === '') { $trous[] = "$code/$l"; continue; }
        preg_match_all('/\{([a-z_]+)\}/', $t['sujet'] . ' ' . $t['corps'], $mm);
        foreach (array_unique($mm[1]) as $v) { if (!in_array($v, $mo['vars'], true) && $v !== 'piece') { $etrangeres[] = "$code/$l:{$v}"; } }
    }
}
verifie('chaque modèle a un objet et un texte dans les quatre langues', $trous === [], implode(' ', $trous));
verifie('un modèle n’emploie que ses variables', $etrangeres === [], implode(' ', $etrangeres));
verifie('l’annonce cite les sujets et le lien', str_contains($def['annonce']['textes']['de']['corps'], '{sujets}') && str_contains($def['annonce']['textes']['en']['corps'], '{lien}'));
$REGLAGES['suiviModeles'] = ['annonce' => ['nl' => ['sujet' => 'Eigen onderwerp', 'corps' => 'Eigen tekst {magasin}'], 'en' => ['sujet' => '', 'corps' => 'vide']]];
$mx = suModeles();
verifie('un modèle réglé remplace le texte livré', $mx['annonce']['textes']['nl']['sujet'] === 'Eigen onderwerp' && $mx['annonce']['modifie']['nl'] === true);
verifie('un modèle réglé à moitié garde le texte livré', $mx['annonce']['textes']['en']['sujet'] === $def['annonce']['textes']['en']['sujet'] && $mx['annonce']['modifie']['en'] === false);
verifie('une langue inconnue retombe sur le français', suModele('annonce', 'es')['sujet'] === $def['annonce']['textes']['fr']['sujet']);
unset($REGLAGES['suiviModeles']);

// --- 3. la date longue et les noms ----------------------------------------------------------
echo "Dates et noms\n";
verifie('fr : lundi 26 octobre', suDateLongue('2026-10-26', 'fr') === 'lundi 26 octobre', suDateLongue('2026-10-26', 'fr'));
verifie('nl : maandag 26 oktober', suDateLongue('2026-10-26', 'nl') === 'maandag 26 oktober', suDateLongue('2026-10-26', 'nl'));
verifie('en : Monday 26 October', suDateLongue('2026-10-26', 'en') === 'Monday 26 October', suDateLongue('2026-10-26', 'en'));
verifie('de : Montag, 26. Oktober', suDateLongue('2026-10-26', 'de') === 'Montag, 26. Oktober', suDateLongue('2026-10-26', 'de'));
verifie('une date avec heure se lit', suDateLongue('2026-10-26 09:30:00', 'fr') === 'lundi 26 octobre');
verifie('un type livré se traduit', suTypeNom('bilan', 'nl') === 'Kwartaalbespreking' && suTypeNom('msp', 'de') === 'Testkauf');
verifie('un type créé ici garde son nom', suTypeNom('flash', 'nl') === 'Contrôle flash');
verifie('une cause se traduit', suL('c_hygiene', 'en') === 'Hygiene, cleanliness' && suL('c_hygiene', 'xx') === 'Hygiène, propreté');

// --- 4. le client mystère sur 20 -------------------------------------------------------------
echo "Client mystère\n";
verifie('17 / 20 reste 17', suNote20('17 / 20') === 17.0);
verifie('8,5/10 devient 17', suNote20('8,5/10') === 17.0);
verifie('un nombre reste tel quel', suNote20(14) === 14.0 && suNote20('12,5') === 12.5);
verifie('un maximum nul ou un texte ne donnent rien', suNote20('3/0') === null && suNote20('bien') === null && suNote20('') === null);

// --- 5. la langue d'un magasin, le jeton de sa page -----------------------------------------
echo "Langue et jeton\n";
verifie('Halle parle néerlandais', suLangueDefaut('4') === 'nl');
verifie('Gent parle néerlandais', suLangueDefaut('10') === 'nl');
verifie('Eupen parle allemand', suLangueDefaut('9') === 'de');
verifie('Corbais et Halleux parlent français', suLangueDefaut('2') === 'fr' && suLangueDefaut('11') === 'fr');
verifie('un magasin inconnu parle français', suLangueDefaut('99') === 'fr');
$j = suJeton('4');
verifie('le jeton fait 24 caractères', strlen($j) === 24 && ctype_xdigit($j));
verifie('le jeton ouvre son magasin, pas un autre', suJetonOk('4', $j) && !suJetonOk('2', $j) && !suJetonOk('4', '') && !suJetonOk('4', strtoupper($j) . 'x'));
$REGLAGES['visitesJeton'] = 'autre-secret';
verifie('changer le secret invalide les anciens liens', !suJetonOk('4', $j));

// --- Sources et API (26/10/2026) : la lecture des chemins du panel et la spécification des API manquantes ------
echo "\nSources et API\n";
require_once __DIR__ . '/../src/marketing_sources.php';
require_once __DIR__ . '/../src/con_tables.php';
require_once __DIR__ . '/../src/suivi_sources.php';
verifie('chemin : variable et urlencode', ssChemin("'/consultant/shops/' . \$shopId . '/tasks?date=' . urlencode(\$date)") === '/consultant/shops/{shop}/tasks?date={date}', (string) ssChemin("'/consultant/shops/' . \$shopId . '/tasks?date=' . urlencode(\$date)"));
verifie('chemin : la requête fabriquée', ssChemin("'/shops/' . \$sid . '/statistics/sales/kpis?' . http_build_query(['date_from' => \$d])") === '/shops/{shop}/statistics/sales/kpis?{query}');
verifie('chemin : chaîne entre guillemets', ssChemin('"/transactions/{$id}?include=products"') === '/transactions/{id}?include=products');
verifie('chemin : ce qui n’est pas un chemin', ssChemin('$x') === null && ssChemin("'abc'") === null);
$v = ssVariablesChemin("\$base = '/consultant/shops/' . \$shopId . '/pnl';\n\$ch[] = \$base . '/daily?' . http_build_query(['a' => 1]);\n\$ch[] = \$base . '?' . self::q(\$p, \$d);");
verifie('variable de chemin : la base reprise', ($v['ch'] ?? []) === ['/consultant/shops/{shop}/pnl/daily?{query}', '/consultant/shops/{shop}/pnl?{query}'], json_encode($v));
$v = ssVariablesChemin("\$paths = ['cats' => '/consultant/shops/category-sales?' . http_build_query([])];\n\$paths['k' . \$id] = '/shops/' . \$id . '/schedule?date=' . \$date;");
verifie('tableau de chemins à clés', ($v['paths'] ?? []) === ['/consultant/shops/category-sales?{query}', '/shops/{shop}/schedule?date={date}'], json_encode($v));
$a = ssAppelsApi("\$r = PanelApi::envoi('PUT', '/consultant/shops/' . \$s . '/targets', \$b); \$t = PanelApi::get('/consultant/tasks'); PanelApi::configured();", 'PanelApi::');
verifie('appels : verbe d’envoi, lecture, socle ignoré', $a === [['m' => 'PUT', 'chemin' => '/consultant/shops/{shop}/targets'], ['m' => 'GET', 'chemin' => '/consultant/tasks']], json_encode($a));
$a = ssAppelsApi("PanelApi::shopTasks(\$s, \$d);", 'PanelApi::', ['shopTasks' => [['m' => 'GET', 'chemin' => '/consultant/shops/{shop}/tasks?date={date}']]]);
verifie('appels : une méthode de PanelApi donne ses chemins', $a === [['m' => 'GET', 'chemin' => '/consultant/shops/{shop}/tasks?date={date}']]);
$col = static fn ($n, $t, $ct, $nul = 'YES', $def = null, $ex = '', $k = '') => ['COLUMN_NAME' => $n, 'DATA_TYPE' => $t, 'COLUMN_TYPE' => $ct, 'IS_NULLABLE' => $nul, 'COLUMN_DEFAULT' => $def, 'EXTRA' => $ex, 'COLUMN_KEY' => $k];
$ch = [ssChamp($col('id', 'int', 'int(11)', 'NO', null, 'auto_increment', 'PRI'), null), ssChamp($col('visite_id', 'int', 'int(11)', 'NO'), null), ssChamp($col('etat', 'varchar', 'varchar(4)', 'NO', "'ok'"), null),
    ssChamp($col('rubriques', 'text', 'text', 'YES', 'NULL'), 'objet'), ssChamp($col('maj_le', 'datetime', 'datetime', 'NO'), null)];
verifie('champ : obligatoire sans défaut, défaut MariaDB lu', $ch[1]['requis'] && !$ch[2]['requis'] && $ch[2]['defaut'] === 'ok' && $ch[3]['defaut'] === null && !$ch[0]['requis']);
verifie('champ : texte JSON reconnu', $ch[3]['type'] === 'object (JSON)' && $ch[2]['type'] === 'string ≤ 4');
$e = ssEndpoints('con_visite_point', CON_TABLES['con_visite_point']['api'], $ch);
verifie('API d’une table : les cinq verbes', array_column($e, 'm') === ['GET', 'GET', 'POST', 'PATCH', 'DELETE'], json_encode(array_column($e, 'm')));
verifie('API d’une table : le corps sans id, sans parent, sans date technique', array_keys($e[2]['corps']) === ['etat', 'rubriques'], json_encode(array_keys($e[2]['corps'])));
$e = ssEndpoints('con_tache_jour', CON_TABLES['con_tache_jour']['api'], $ch);
verifie('API d’une table à clé composée : lister, écrire, retirer', array_column($e, 'm') === ['GET', 'PUT', 'DELETE']);
$e = ssEndpoints('con_visite_photo', CON_TABLES['con_visite_photo']['api'], $ch);
verifie('API des photos : dépôt et lien signé', in_array('/consultant/visits/{visite_id}/photos/{id}/presigned-url', array_column($e, 'chemin'), true));
$routeur = (string) file_get_contents(__DIR__ . '/../public/api/index.php') . (string) file_get_contents(__DIR__ . '/../src/suivi.php');
$index = msIndex(dirname(__DIR__));
$absentes = array_values(array_filter(SS_ROUTES, static fn ($r) => !isset($index[$r[3]]) || !preg_match('/\b' . $r[3] . '\(/', $routeur)));
verifie('chaque route du rapport existe et est routée', $absentes === [], json_encode(array_column($absentes, 3)));
verifie('chaque écran du rapport est nommé', !array_filter(SS_ROUTES, static fn ($r) => !isset(SS_ECRANS[$r[0]])));

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
