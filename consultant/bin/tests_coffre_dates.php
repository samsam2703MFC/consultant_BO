#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des grandes dates du coffre-fort et de leur assistant (src/coffre_dates.php, 22/11/2026) — base SQLite en
 * mémoire, l'API Anthropic remplacée par une réponse écrite ici (Anthropic::$transport).
 *
 *   php consultant/bin/tests_coffre_dates.php
 *
 * Le calcul du bail (triennats, dernier jour du congé, fenêtre du renouvellement, fin ; les fins de mois), la liste
 * (dates rangées et échéances des documents, états), le rangement (doublons, document d'une autre boutique, droits du
 * magasin et du siège), l'assistant (la requête, le calcul qui remplace l'arithmétique du modèle, « déjà rangée »,
 * l'échéance et le titre du document), les fichiers qu'il ne lit pas.
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
final class Db {
  static ?PDO $p = null;
  static function config(): array { return []; }
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string {
    $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
    $q = (string) preg_replace('/,UNIQUE KEY \w+ (\([^)]*\))/', ',UNIQUE $1', $q);
    $q = (string) preg_replace('/,KEY \w+ \([^)]*\)/', '', $q); return (string) preg_replace('/\) ENGINE=.*$/', ')', $q);
  }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
$GLOBALS['journal'] = [];
function journalAdd(...$a) { $GLOBALS['journal'][] = $a; }
function setting(string $k, $d = null) { return $k === 'anthropic' ? ['cle' => 'sk-essai', 'modele' => 'claude-opus-5-5'] : $d; }
function body(): array { return $GLOBALS['corps'] ?? []; }
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;
function accesMode(): string { return $GLOBALS['mode']; }
function accesIdentite(): ?array { return $GLOBALS['identite']; }
require __DIR__ . '/../src/ca_mail.php';
require __DIR__ . '/../src/cq_mail.php';
require __DIR__ . '/../src/documents.php';
require __DIR__ . '/../src/coffre.php';
require __DIR__ . '/../src/coffre_dates.php';
require __DIR__ . '/../../shared/src/anthropic.php';

echo "Le calcul du bail\n";
$B = cofBailDates('2022-03-01', 9, 6);
$par = []; foreach ($B as $d) { $par[$d['sorte']][] = $d; }
verifie('deux triennats avant la fin d’un bail de 9 ans', array_column($par['bail_triennat'], 'le') === ['2025-02-28', '2028-02-29'], json_encode(array_column($par['bail_triennat'], 'le')));
verifie('le dernier jour du congé : 6 mois avant la fin du triennat', array_column($par['bail_preavis'], 'le') === ['2024-08-31', '2027-08-31'], json_encode(array_column($par['bail_preavis'], 'le')));
verifie('la fenêtre du renouvellement : du 18e au 15e mois avant la fin', $par['bail_renouvellement'][0]['le'] === '2029-09-01' && $par['bail_renouvellement'][0]['fin'] === '2029-12-01');
verifie('la fin du bail et sa prise de cours', $par['bail_fin'][0]['le'] === '2031-02-28' && $par['bail_debut'][0]['le'] === '2022-03-01');
verifie('par date, et le rang des triennats', array_column($B, 'le') === array_values(array_unique(array_column($B, 'le'))) && str_contains($par['bail_triennat'][0]['titre'], '1er') && str_contains($par['bail_triennat'][1]['titre'], '2e'));
$B2 = cofBailDates('2021-08-31', 9, 6);
$p2 = array_values(array_filter($B2, fn ($d) => $d['sorte'] === 'bail_preavis'));
verifie('un 31 août : pas de débordement de mois (fin février, au plus tôt)', $p2[0]['le'] === '2024-02-28' && cofMois('2025-08-31', -6) === '2025-02-28', $p2[0]['le']);
verifie('un bail de 12 ans : trois triennats', count(array_filter(cofBailDates('2020-01-01', 12, 6), fn ($d) => $d['sorte'] === 'bail_triennat')) === 3);
verifie('un préavis de 3 mois écrit dans le bail', array_values(array_filter(cofBailDates('2022-03-01', 9, 3), fn ($d) => $d['sorte'] === 'bail_preavis'))[0]['le'] === '2024-11-30');
verifie('la note rappelle la règle générale', str_contains($par['bail_triennat'][0]['note'], 'règle générale des baux commerciaux'));
verifie('dates invalides refusées', cofDateOk('2026-02-30') === null && cofDateOk('31/01/2027') === null && cofDateOk('2027-01-31') === '2027-01-31');

echo "La liste et le rangement\n";
$shop = 990201; $autre = 990202;
$tmp = sys_get_temp_dir() . '/coffre-dates-' . getmypid();
@mkdir($tmp);
foreach (['bail', 'assurance', 'autre', 'photo', 'tableur'] as $f) { file_put_contents($tmp . '/' . $f . '.bin', '%PDF-1.4 ' . $f); }
Db::pdo()->exec('CREATE TABLE shops (id INT, name TEXT, active INT)');
Db::exec('INSERT INTO shops VALUES (?, ?, 1)', [$shop, 'Atelier by - Gosselies']);
docTable();
$auj = date('Y-m-d');
$dans = static fn (int $j) => date('Y-m-d', strtotime($auj . ' ' . ($j >= 0 ? '+' : '') . $j . ' days'));
$siege = ['visible' => true, 'par' => 'Nadia K.', 'role' => 'siege'];
$bail = docGarder($shop, 'legal', 'scan-bail-003', null, null, 'scan-bail-003.pdf', $tmp . '/bail.bin', 12, false, $siege);
$ass = docGarder($shop, 'legal', 'Assurance RC', '2026-01-01', null, 'assurance.pdf', $tmp . '/assurance.bin', 12, false, $siege + ['echeance' => $dans(39)]);
$aut = docGarder($autre, 'legal', 'Bail d’un autre magasin', null, null, 'autre.pdf', $tmp . '/autre.bin', 12, false, $siege);
$tab = docGarder($shop, 'finances', 'Budget', null, null, 'budget.xlsx', $tmp . '/tableur.bin', 12, false, $siege);
$cache = docGarder($shop, 'legal', 'Hors du coffre', null, null, 'cache.pdf', $tmp . '/photo.bin', 12, false);

$_GET = ['shop' => (string) $shop]; $L = ep_coffre_dates();
verifie('d’office : l’échéance d’un document du coffre', count($L['dates']) === 1 && $L['dates'][0]['source'] === 'document' && $L['dates'][0]['le'] === $dans(39) && $L['dates'][0]['etat']['cle'] === 'bientot' && $L['dates'][0]['modifiable'] === false);
$GLOBALS['corps'] = ['shop' => $shop, 'dates' => [
    ['sorte' => 'bail_preavis', 'titre' => 'Dernier jour pour donner congé', 'le' => $dans(200), 'docId' => (int) $bail['id'], 'source' => 'calcul'],
    ['sorte' => 'bail_renouvellement', 'titre' => 'Renouvellement', 'le' => $dans(-10), 'fin' => $dans(80), 'docId' => (int) $bail['id'], 'source' => 'calcul'],
    ['sorte' => 'autre', 'titre' => 'Passée', 'le' => $dans(-400)],
    ['sorte' => 'n_importe', 'titre' => 'Sorte inconnue', 'le' => $dans(500), 'docId' => (int) $aut['id']],
    ['sorte' => 'autre', 'titre' => 'Mauvaise date', 'le' => '31/12/2027'],
    ['sorte' => 'autre', 'titre' => '', 'le' => $dans(10)],
]];
$R = wr_coffre_dates();
verifie('quatre rangées, deux refusées (date, titre)', $R['ajoutees'] === 4 && count($R['refusees']) === 2, json_encode($R['refusees'], JSON_UNESCAPED_UNICODE));
$parTitre = array_column($R['dates'], null, 'titre');
verifie('une sorte inconnue devient « autre » ; le document d’une autre boutique n’est pas relié', $parTitre['Sorte inconnue']['sorte'] === 'autre' && $parTitre['Sorte inconnue']['docId'] === null);
verifie('les états : passée, en cours (fenêtre), à venir', $parTitre['Passée']['etat']['cle'] === 'passee' && $parTitre['Renouvellement']['etat']['cle'] === 'encours' && $parTitre['Renouvellement']['etat']['jours'] === 80 && $parTitre['Sorte inconnue']['etat']['cle'] === 'avenir');
verifie('le document d’où vient la date', $parTitre['Dernier jour pour donner congé']['docTitre'] === 'scan-bail-003' && $parTitre['Dernier jour pour donner congé']['docCoffre'] === true);
verifie('par date', array_column($R['dates'], 'le') === (function ($l) { sort($l); return $l; })(array_column($R['dates'], 'le')));
$R2 = wr_coffre_dates();
verifie('la même date ne se range pas deux fois', $R2['ajoutees'] === 0 && count($R2['dates']) === count($R['dates']));
$GLOBALS['mode'] = 'console'; $GLOBALS['identite'] = ['role' => 'siege', 'shops' => null, 'nom' => 'Nadia K.'];
$GLOBALS['corps'] = ['shop' => $shop, 'dates' => [['sorte' => 'assurance', 'titre' => 'Échéance RC', 'le' => $dans(39), 'docId' => (int) $ass['id']]]];
$R3 = wr_coffre_dates();
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;
verifie('une date rangée qui reprend l’échéance du document la remplace dans la liste', count(array_filter($R3['dates'], fn ($d) => $d['le'] === $dans(39))) === 1 && count(array_filter($R3['dates'], fn ($d) => $d['source'] === 'document')) === 0);
$res = cofDatesResume($shop);
verifie('le résumé : la prochaine date (la fenêtre en cours), combien bientôt', $res['prochaine']['titre'] === 'Renouvellement' && $res['bientot'] === 2 && $res['n'] === 4, json_encode($res, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => (string) $shop]; $C = ep_coffre_magasin();
verifie('les repères du coffre portent le résumé des grandes dates', ($C['reperes']['dates']['bientot'] ?? null) === 2);

$GLOBALS['corps'] = ['shop' => $shop, 'depuis' => 'cockpit', 'dates' => [['sorte' => 'autre', 'titre' => 'Rangée au cockpit', 'le' => $dans(700)]]];
$x = wr_coffre_dates();
verifie('sans session, le cockpit range au nom du siège', (array_values(array_filter($x['dates'], fn ($d) => $d['titre'] === 'Rangée au cockpit'))[0]['role'] ?? '') === 'siege');
echo "Les droits\n";
$GLOBALS['mode'] = 'console';
$GLOBALS['identite'] = ['role' => 'franchise', 'shops' => [(string) $shop], 'nom' => 'Gosselies'];
$GLOBALS['corps'] = ['shop' => $shop, 'depuis' => 'cockpit', 'dates' => [['sorte' => 'autorisation', 'titre' => 'Contrôle AFSCA', 'le' => $dans(60), 'docId' => (int) $cache['id']]]];
$R = wr_coffre_dates();
$afsca = array_values(array_filter($R['dates'], fn ($d) => $d['titre'] === 'Contrôle AFSCA'))[0];
verifie('le magasin connecté range à son nom (le « cockpit » du corps ne compte pas) ; un document hors de son coffre n’est pas relié', $R['ajoutees'] === 1 && $afsca['docId'] === null && $afsca['role'] === 'franchise' && $afsca['modifiable']);
$siegeDate = array_values(array_filter($R['dates'], fn ($d) => $d['titre'] === 'Échéance RC'))[0];
verifie('une date du siège : pas modifiable par le magasin', $siegeDate['modifiable'] === false);
$_GET = ['shop' => (string) $shop, 'id' => (string) $siegeDate['id']]; $x = @wr_coffre_date_suppr();
verifie('le magasin ne retire pas une date du siège', str_contains((string) ($x['error'] ?? ''), 'siège'));
$_GET = ['shop' => (string) $shop, 'id' => (string) $afsca['id']]; $x = wr_coffre_date_suppr();
verifie('il retire la sienne', ($x['ok'] ?? false) && !in_array('Contrôle AFSCA', array_column($x['dates'], 'titre'), true));
$GLOBALS['corps'] = ['shop' => $autre, 'dates' => [['sorte' => 'autre', 'titre' => 'Intrus', 'le' => $dans(5)]]]; $x = @wr_coffre_dates();
verifie('le coffre d’une autre boutique : refusé', ($x['error'] ?? '') === 'ce magasin n’est pas sur votre session');
$GLOBALS['corps'] = ['shop' => $shop, 'id' => (int) $cache['id']]; $x = @wr_coffre_assistant();
verifie('l’assistant ne lit pas un document hors du coffre pour le magasin', ($x['error'] ?? '') === 'document introuvable dans ce coffre');
$GLOBALS['identite'] = ['role' => 'siege', 'shops' => null, 'nom' => 'Nadia K.'];
$_GET = ['shop' => (string) $shop, 'id' => (string) $siegeDate['id']]; $x = wr_coffre_date_suppr();
verifie('le siège retire toute date', ($x['ok'] ?? false));
verifie('le siège lit tout document de la boutique, pas d’une autre', cofDocLisible($shop, (int) $cache['id']) !== null && cofDocLisible($shop, (int) $aut['id']) === null);
$GLOBALS['mode'] = 'ouvert'; $GLOBALS['identite'] = null;

echo "L'assistant\n";
$envois = []; $reponse = null;
Anthropic::$transport = function (array $corps, array $entetes) use (&$envois, &$reponse) {
    $envois[] = $corps;
    return [200, ['model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 3000, 'output_tokens' => 400],
        'content' => [['type' => 'text', 'text' => json_encode($reponse, JSON_UNESCAPED_UNICODE)]]]];
};
$reponse = ['genre' => 'bail_commercial', 'piece' => 'bail', 'titre' => 'Bail commercial — rue de la Station 12, Gosselies',
    'resume' => 'Bail commercial de 9 ans pour la boutique, loyer indexé chaque année.',
    'bail' => ['prise_de_cours' => '2022-03-01', 'duree_ans' => 9, 'preavis_mois' => null, 'extrait' => 'Le bail est consenti pour neuf années prenant cours le 1er mars 2022.'],
    'echeance' => '2031-02-28',
    'dates' => [
        ['sorte' => 'bail_triennat', 'titre' => 'Fin du triennat (erreur du modèle)', 'le' => '2025-03-01', 'fin' => '', 'extrait' => '…', 'page' => 2],
        ['sorte' => 'bail_indexation', 'titre' => 'Indexation annuelle du loyer', 'le' => '2027-03-01', 'fin' => '', 'extrait' => 'Le loyer est indexé à chaque date anniversaire.', 'page' => 3],
        ['sorte' => 'assurance', 'titre' => 'Date illisible', 'le' => '2027-13-01', 'fin' => '', 'extrait' => '', 'page' => null],
    ],
    'remarques' => 'Le préavis du preneur n’est pas précisé.'];
$GLOBALS['corps'] = ['shop' => $shop, 'id' => (int) $bail['id']];
$A = wr_coffre_assistant();
$c = $envois[0] ?? [];
verifie('le PDF part en document, avant la consigne ; sortie structurée', ($c['messages'][0]['content'][0]['type'] ?? '') === 'document' && ($c['messages'][0]['content'][0]['source']['media_type'] ?? '') === 'application/pdf' && ($c['messages'][0]['content'][1]['type'] ?? '') === 'text' && ($c['output_config']['format']['type'] ?? '') === 'json_schema');
verifie('le modèle du réglage ; ni température ni réflexion forcée', ($c['model'] ?? '') === 'claude-opus-5-5' && !isset($c['temperature']) && !isset($c['thinking']) && !isset($c['tool_choice']));
verifie('la consigne dit le contexte (document, date du jour)', str_contains($c['messages'][0]['content'][1]['text'] ?? '', 'scan-bail-003') && str_contains($c['messages'][0]['content'][1]['text'] ?? '', 'Nous sommes le'));
$P = array_column($A['propositions'] ?? [], null, 'titre');
verifie('le bail est CALCULÉ : la date du modèle pour le triennat est écartée', !isset($P['Fin du triennat (erreur du modèle)']) && ($P['Fin du 1er triennat']['le'] ?? '') === '2025-02-28' && ($P['Fin du 1er triennat']['source'] ?? '') === 'calcul');
verifie('le préavis non lu : 6 mois par défaut, dit dans la note', str_contains($P['Fin du bail']['note'] ?? '', 'Préavis non lu'));
verifie('l’indexation du modèle reste, avec son extrait ; une date illisible tombe', ($P['Indexation annuelle du loyer']['source'] ?? '') === 'assistant' && str_contains($P['Indexation annuelle du loyer']['extrait'], 'indexé') && !isset($P['Date illisible']));
verifie('les dates passées sont proposées décochées, les futures cochées', ($P['Prise de cours du bail']['coche'] ?? true) === false && ($P['Fin du bail']['coche'] ?? false) === true);
verifie('chaque proposition dit son état (passée pour la prise de cours)', ($P['Prise de cours du bail']['etat']['cle'] ?? '') === 'passee' && in_array($P['Fin du bail']['etat']['cle'] ?? '', ['avenir', 'bientot'], true));
verifie('rien n’est encore rangé pour ce document : aucune « déjà »', count(array_filter($A['propositions'], fn ($p) => $p['deja'])) === 0);
verifie('l’échéance et le titre du document sont proposés (il porte le nom du fichier)', ($A['echeanceDoc']['proposee'] ?? '') === '2031-02-28' && ($A['titreDoc']['propose'] ?? '') === 'Bail commercial — rue de la Station 12, Gosselies' && ($A['titreDoc']['actuel'] ?? '') === 'scan-bail-003');
verifie('la pièce attendue reconnue : le bail commercial', ($A['pieceSuggeree']['cle'] ?? '') === 'bail' && ($A['pieceSuggeree']['onglet'] ?? '') === 'legal');
verifie('le genre, le résumé et les remarques', $A['genreLib'] === 'Bail commercial' && str_contains($A['resume'], '9 ans') && str_contains($A['remarques'], 'préavis'));
// Garder : les dates cochées, l'échéance et le titre du document.
$gardees = array_values(array_filter($A['propositions'], fn ($p) => $p['coche']));
$GLOBALS['corps'] = ['shop' => $shop, 'dates' => array_map(fn ($p) => ['sorte' => $p['sorte'], 'titre' => $p['titre'], 'le' => $p['le'], 'fin' => $p['fin'], 'note' => $p['note'], 'docId' => (int) $bail['id'], 'source' => $p['source']], $gardees),
    'echeanceDoc' => ['docId' => (int) $bail['id'], 'le' => '2031-02-28'], 'titreDoc' => ['docId' => (int) $bail['id'], 'titre' => $A['titreDoc']['propose']],
    'pieceDoc' => ['docId' => (int) $bail['id'], 'piece' => 'bail']];
$G = wr_coffre_dates();
$doc = Db::row('SELECT titre, echeance FROM con_doc WHERE id = ?', [(int) $bail['id']]);
verifie('gardées : les dates, l’échéance, la pièce et le titre du document', $G['ajoutees'] === count($gardees) && $G['document'] === ['échéance', 'pièce', 'titre'] && $doc['echeance'] === '2031-02-28' && str_starts_with($doc['titre'], 'Bail commercial')
    && Db::row('SELECT piece FROM con_doc WHERE id = ?', [(int) $bail['id']])['piece'] === 'bail', json_encode($G['document'], JSON_UNESCAPED_UNICODE));
$A2 = wr_coffre_assistant_relire($shop, (int) $bail['id']);
verifie('relu : les dates déjà rangées sont marquées « déjà » et décochées', count(array_filter($A2['propositions'], fn ($p) => $p['deja'] && !$p['coche'])) === count($gardees) && $A2['titreDoc'] === null && $A2['echeanceDoc'] === null);
verifie('la fin du bail rangée ne fait pas doublon avec l’échéance du document', count(array_filter(cofDatesListe($shop), fn ($d) => $d['le'] === '2031-02-28')) === 1);
$verifSchema = function (array $s) use (&$verifSchema): bool {
    foreach ((array) ($s['anyOf'] ?? []) as $x) { if (!$verifSchema($x)) { return false; } }
    if (($s['type'] ?? '') === 'object') {
        if (($s['additionalProperties'] ?? null) !== false || array_diff(array_keys($s['properties']), $s['required'])) { return false; }
        foreach ($s['properties'] as $p) { if (!$verifSchema($p)) { return false; } }
    }
    if (($s['type'] ?? '') === 'array') { return $verifSchema($s['items']); }
    return true;
};
verifie('schéma strict : chaque objet fermé, chaque champ requis', $verifSchema(cofAssistantSchema()));
$GLOBALS['corps'] = ['shop' => $shop, 'id' => (int) $tab['id']]; $x = @wr_coffre_assistant();
verifie('un tableur : l’assistant dit ce qu’il lit', str_contains((string) ($x['error'] ?? ''), 'PDF') && count($envois) === 2);
Anthropic::$transport = fn () => [200, ['stop_reason' => 'refusal', 'stop_details' => ['explanation' => 'catégorie'], 'content' => []]];
$GLOBALS['corps'] = ['shop' => $shop, 'id' => (int) $bail['id']]; $x = @wr_coffre_assistant();
verifie('un refus du modèle : une erreur lisible, rien de rangé', str_contains((string) ($x['error'] ?? ''), 'refusé'));
verifie('les routes sont sous /coffre/magasin (ACCES_FRANCHISE)', str_contains((string) file_get_contents(__DIR__ . '/../../shared/public/api/index.php'), "'/coffre/magasin/assistant'"));

// Le banc s'efface.
foreach ([$shop, $autre] as $s) { array_map('unlink', glob(docDossier($s) . '/*') ?: []); @rmdir(docDossier($s)); }
array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
echo "\n$ok ok, $ko KO\n";
exit($ko ? 1 : 0);

/** Relit le document avec l'assistant (même réponse du modèle) : ce qu'il propose une fois les dates rangées. */
function wr_coffre_assistant_relire(int $shop, int $id): array
{
    $GLOBALS['corps'] = ['shop' => $shop, 'id' => $id];
    return wr_coffre_assistant();
}
