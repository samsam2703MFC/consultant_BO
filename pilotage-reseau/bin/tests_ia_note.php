#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la proposition de note par l'IA (Anthropic::noterPhoto, critères des rayons de src/rayons_photos.php) —
 * l'API Anthropic en doublure (Anthropic::$transport), les photos servies en local.
 *
 *   php bin/tests_ia_note.php
 *
 * La requête porte les règles (le comptoir, pas la photo ; les prix seulement s'ils sont demandés), les critères du
 * rayon et sa photo type ; la réponse est contrainte par un schéma au barème ; une réponse coupée, un refus, un
 * niveau hors barème ou un JSON illisible rendent une erreur, jamais une note ; la consigne du back office prime
 * sur les critères par défaut.
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
// Les réglages : la clé d'essai, et ceux qu'un test pose dans $GLOBALS['REGLAGES'] (la consigne générale).
function setting(string $k, $d = null) { return $k === 'anthropic' ? ['cle' => 'sk-ant-essai', 'modele' => 'modele-essai'] : ($GLOBALS['REGLAGES'][$k] ?? $d); }

$racine = dirname(__DIR__);
require $racine . '/src/anthropic.php';
require $racine . '/src/rayons_photos.php';

// Les photos : servies en local, téléchargées par noterPhoto comme une URL signée du panel.
$port = 18000 + random_int(0, 999);
$serveur = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $racine], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubes);
usleep(400000);
$photo = 'http://127.0.0.1:' . $port . '/docs/maquettes/controle-taches-photos/photos/3-1209.jpg';
$typeRayon = $racine . '/docs/maquettes/controle-taches-photos/photos/4-1207.jpg';
$niveaux = [
    ['n' => 5, 'nom' => 'Exemplaire', 'aide' => "au-dessus de l'attendu"], ['n' => 4, 'nom' => 'Conforme', 'aide' => 'livrable accepté'],
    ['n' => 3, 'nom' => 'Non conforme — mineur'], ['n' => 2, 'nom' => 'Non conforme — majeur'], ['n' => 1, 'nom' => 'Non conforme — critique'],
];
$envois = [];
$reponse = null;
Anthropic::$transport = function (array $corps, array $entetes) use (&$envois, &$reponse) { $envois[] = $corps; return $reponse; };
$texte = static fn (array $corps): string => implode("\n", array_map(fn ($b) => $b['type'] === 'text' ? $b['text'] : '[image]', $corps['messages'][0]['content']));
$json = static fn (array $j): array => [200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => json_encode($j, JSON_UNESCAPED_UNICODE)]]]];

echo "Critères des rayons\n";
$quiches = ['cle' => 'quiches', 'nom' => 'Quiches', 'photo' => null, 'consigne' => null];
verifie('pas de rayon : pas de critères', rvCriteresIa(null) === '');
verifie('quiches : vendues au quart, entamée n’est pas un écart', str_contains(rvCriteresIa($quiches), 'au quart') && str_contains(rvCriteresIa($quiches), 'pas un écart'));
verifie('règles communes pour tout rayon', str_contains(rvCriteresIa($quiches), 'sans objet étranger'));
$propre = rvCriteresIa(['cle' => 'quiches', 'nom' => 'Quiches', 'consigne' => 'Six quiches entières minimum.']);
verifie('la consigne du back office prime', str_contains($propre, 'Six quiches entières minimum.') && !str_contains($propre, 'au quart'));
verifie('un rayon ajouté sans consigne : la consigne générale seule', rvCriteresIa(['cle' => 'glaces', 'nom' => 'Glaces', 'consigne' => null])
    === "Consigne générale des contrôles qualité :\n- " . implode("\n- ", CQ_CONSIGNE_GENERALE_DEFAUT));
verifie('consigne générale par défaut : photo de face comparée au planogramme, quantité et sens des produits',
    str_contains(rvCriteresIa($quiches), 'prise de face') && str_contains(rvCriteresIa($quiches), 'planogramme')
    && str_contains(rvCriteresIa($quiches), 'quantité de produits et leur sens'));
verifie('la consigne générale passe avant celle du rayon', strpos(rvCriteresIa($quiches), 'Consigne générale') < strpos(rvCriteresIa($quiches), 'Rayon « Quiches »'));
$GLOBALS['REGLAGES']['cqConsigneGenerale'] = ['Prix visible sur chaque produit.', '  ', 'Étiquettes allergènes en place.'];
verifie('la consigne générale modifiée : ses points, un point vide écarté, la règle par défaut plus envoyée',
    str_contains(rvCriteresIa($quiches), "- Prix visible sur chaque produit.\n- Étiquettes allergènes en place.") && !str_contains(rvCriteresIa($quiches), 'prise de face'));
$GLOBALS['REGLAGES']['cqConsigneGenerale'] = [];
verifie('tous les points retirés : la consigne du rayon seule', rvCriteresIa($quiches) === 'Rayon « Quiches » : ' . RV_CRITERES_IA['quiches']);
unset($GLOBALS['REGLAGES']['cqConsigneGenerale']);
verifie('un point saisi avec sa puce ou son numéro : nettoyé', cqPoint('• Photo  de face') === 'Photo de face' && cqPoint('2) Sens des produits') === 'Sens des produits'
    && cqPoint('- Quantité') === 'Quantité');
verifie('chaque rayon du panel a ses critères', count(array_filter(RV_DEFAUT, fn ($n) => isset(RV_CRITERES_IA[rvCle($n)]))) === count(RV_DEFAUT));
verifie('le rayon d’une tâche « Photo du comptoir - Quiches »', (rvRayonDe('Photo du comptoir - Quiches', [$quiches])['cle'] ?? '') === 'quiches');
verifie('« Photo du comptoir après-midi - Quiches » : le même rayon', (rvRayonDe('Photo du comptoir après-midi - Quiches', [$quiches])['cle'] ?? '') === 'quiches');

echo "La requête\n";
$reponse = $json(['niveau' => 4, 'constats' => ['Comptoir garni'], 'commentaire' => 'Rien à corriger.', 'confiance' => 'haute']);
$r = Anthropic::noterPhoto('Photo du comptoir - Quiches', $photo, null, $niveaux, 'checklist « CQ-02 »', ['criteres' => rvCriteresIa($quiches), 'refLocale' => $typeRayon]);
$c = $envois[0] ?? [];
$t = $c ? $texte($c) : '';
verifie('une proposition rendue', $r['niveau'] === 4 && $r['nom'] === 'Conforme' && $r['confiance'] === 'haute' && $r['erreur'] === null, json_encode($r, JSON_UNESCAPED_UNICODE));
verifie('le modèle du réglage', ($c['model'] ?? '') === 'modele-essai');
verifie('la limite laisse la place à la réflexion', ($c['max_tokens'] ?? 0) >= 4000, (string) ($c['max_tokens'] ?? ''));
$sch = $c['output_config']['format']['schema'] ?? [];
verifie('sortie contrainte par un schéma', ($c['output_config']['format']['type'] ?? '') === 'json_schema' && ($sch['additionalProperties'] ?? null) === false);
verifie('le niveau du schéma est pris dans le barème', ($sch['properties']['niveau']['enum'] ?? []) === [5, 4, 3, 2, 1]);
verifie('la confiance du schéma : haute, moyenne, faible', ($sch['properties']['confiance']['enum'] ?? []) === ['haute', 'moyenne', 'faible']);
verifie('règle : le comptoir, pas la photo', str_contains($t, 'pas la photo') && str_contains($t, 'ne font jamais baisser le niveau'));
verifie('règle : les prix seulement s’ils sont demandés', str_contains($t, 'Les étiquettes et les prix ne comptent que si'));
verifie('les critères du rayon sont envoyés', str_contains($t, 'Ce que le réseau attend') && str_contains($t, 'au quart'));
verifie('la photo type du rayon en référence, avant la photo du magasin', substr_count($t, '[image]') === 2 && strpos($t, 'Photo TYPE du rayon') < strpos($t, 'PRISE EN BOUTIQUE'));
verifie('le barème tel quel', str_contains($t, '- 4 = Conforme (livrable accepté)'));

$envois = [];
Anthropic::noterPhoto('Vérifier si le magasin est propre.', $photo, null, $niveaux, '', ['criteres' => '', 'refLocale' => '/nulle/part.jpg']);
$t = $texte($envois[0]);
verifie('sans critères ni photo type : une seule image, pas de bloc critères', substr_count($t, '[image]') === 1 && !str_contains($t, 'Ce que le réseau attend'));
$envois = [];
Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('les anciens appels (cinq arguments) marchent toujours', count($envois) === 1);
verifie('sans leçon : pas de bloc corrections', !str_contains($texte($envois[0]), 'Corrections récentes'));
$envois = [];
$exemple = $racine . '/docs/maquettes/controle-taches-photos/photos/4-1206.jpg';
Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux, '', ['refs' => [
    ['chemin' => $typeRayon, 'lib' => 'Photo du PLANOGRAMME du rayon « Tartes » :'],
    ['chemin' => $exemple, 'lib' => 'Photo d’EXEMPLE jugée parfaite par le consultant (ce magasin, 2026-11-14) :'],
    ['chemin' => '/nulle/part.jpg', 'lib' => 'absente'],
    ['chemin' => $exemple, 'lib' => 'Photo d’EXEMPLE (un autre magasin) :'],
    ['chemin' => $exemple, 'lib' => 'une de trop']]]);
$t = $texte($envois[0]);
verifie('le planogramme, puis les exemples, puis la photo du magasin', strpos($t, 'PLANOGRAMME') < strpos($t, 'EXEMPLE jugée parfaite') && strpos($t, 'EXEMPLE jugée parfaite') < strpos($t, 'PRISE EN BOUTIQUE'));
verifie('trois références au plus, une absente remplacée par la suivante', substr_count($t, '[image]') === 4 && !str_contains($t, 'absente') && str_contains($t, 'un autre magasin') && !str_contains($t, 'une de trop'));
$envois = [];
Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux, '', ['criteres' => rvCriteresIa(['cle' => 'tartes', 'nom' => 'Tartes']),
    'exemples' => ['Tu avais proposé « Non conforme — majeur » ; le consultant a noté « Non conforme — mineur » — sa raison : un objet seul reste mineur.', ' ']]);
$t = $texte($envois[0]);
verifie('les leçons du consultant partent avec la photo, après les critères', str_contains($t, 'Corrections récentes du consultant')
    && str_contains($t, 'un objet seul reste mineur') && strpos($t, 'Ce que le réseau attend') < strpos($t, 'Corrections récentes') && substr_count($t, "\n- Tu avais") === 1);

echo "Les réponses\n";
$reponse = [200, ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"niveau": 4, "const']]]];
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('réponse coupée : une erreur, pas de note', $r['niveau'] === null && str_contains((string) $r['erreur'], 'coupée'), (string) $r['erreur']);
$reponse = [200, ['stop_reason' => 'refusal', 'stop_details' => ['explanation' => 'catégorie'], 'content' => []]];
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('refus du modèle : une erreur, pas de note', $r['niveau'] === null && str_contains((string) $r['erreur'], 'refusé'), (string) $r['erreur']);
$reponse = [529, ['error' => ['message' => 'Overloaded']]];
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('erreur HTTP : rendue avec son code', $r['niveau'] === null && str_contains((string) $r['erreur'], 'HTTP 529'), (string) $r['erreur']);
$reponse = $json(['niveau' => 7, 'constats' => [], 'commentaire' => '', 'confiance' => 'haute']);
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('niveau hors barème : refusé, pas arrondi', $r['niveau'] === null && str_contains((string) $r['erreur'], 'hors barème'));
$reponse = [200, ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'pas du JSON']]]];
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('JSON illisible : une erreur', $r['niveau'] === null && $r['erreur'] === 'réponse non exploitable du modèle');
$reponse = $json(['niveau' => 3, 'constats' => ['Un ventilateur posé au milieu des tartes', ' '], 'commentaire' => ' Retirer le "ventilateur". ', 'confiance' => 'haute']);
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', $photo, null, $niveaux);
verifie('guillemets dans le commentaire, constats vides retirés', $r['niveau'] === 3 && $r['commentaire'] === 'Retirer le "ventilateur".' && $r['constats'] === ['Un ventilateur posé au milieu des tartes']);
$r = Anthropic::noterPhoto('Photo du comptoir - Tartes', 'http://127.0.0.1:' . $port . '/absente.jpg', null, $niveaux);
verifie('photo introuvable : une erreur avant tout appel', $r['niveau'] === null && str_contains((string) $r['erreur'], 'photo illisible'));

proc_terminate($serveur);
Anthropic::$transport = null;
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
