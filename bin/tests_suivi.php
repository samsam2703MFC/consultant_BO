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

echo "\n$ok réussi(s), $ko en échec\n";
exit($ko === 0 ? 0 : 1);
