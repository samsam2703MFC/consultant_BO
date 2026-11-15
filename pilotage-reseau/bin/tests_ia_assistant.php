#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'assistant de notation (src/ia_assistant.php) — base SQLite en mémoire, la proposition de l'IA
 * (ep_ia_note) en doublure.
 *
 *   php bin/tests_ia_assistant.php
 *
 * Une proposition se calcule une fois puis se relit ; un échec se retente, une clé absente ne s'enregistre pas ;
 * une photo déjà notée ne se repropose pas ; la note du consultant rejoint la proposition (validée telle quelle ou
 * corrigée, avec sa raison) ; les corrections deviennent des leçons, par rayon, les plus récentes d'abord, et
 * s'écartent d'un clic ; le taux d'accord compte les notes identiques et le sens des écarts.
 */
// La sortie en tampon : sans quoi le premier echo envoie les en-têtes et http_response_code() ne change plus rien.
ob_start();
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

final class Db {
  static ?PDO $p = null;
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string { return (string) preg_replace('/\)\s*ENGINE=InnoDB[^;]*$/', ')', $q); }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) {
    return $k === 'signalement' ? ['seuil' => 4, 'niveaux' => [['n' => 5, 'nom' => 'Exemplaire'], ['n' => 4, 'nom' => 'Conforme'],
        ['n' => 3, 'nom' => 'Non conforme — mineur'], ['n' => 2, 'nom' => 'Non conforme — majeur'], ['n' => 1, 'nom' => 'Non conforme — critique']]] : $d;
}
function body(): array { return $GLOBALS['BODY'] ?? []; }
function journalAdd(...$a): void { $GLOBALS['JOURNAL'][] = $a; }
/** La doublure de la proposition du cockpit : rend ce que le test prépare, compte les appels. */
function ep_ia_note(): array
{
    $GLOBALS['APPELS'][] = [$_GET['shop'] ?? null, $_GET['task'] ?? null, $_GET['date'] ?? null];
    return $GLOBALS['IA'];
}
/** La doublure du détail d'une tâche (photo signée, rayon) : ce que le test prépare. */
function ep_pwa_task_detail(): array { return $GLOBALS['DETAIL']; }
/** La doublure de l'écriture d'image (src/plano_std.php) : garde le chemin demandé, n'écrit rien. */
function psImage(string $data, string $rel): array
{
    $GLOBALS['IMAGES'][] = [$rel, substr($data, 0, 23)];
    return str_starts_with($data, 'data:image/') ? ['chemin' => $rel . '.jpg'] : ['code' => 415, 'error' => 'format non accepté'];
}
function psAuteur(): string { return 'Sam'; }
require __DIR__ . '/../src/rayons_photos.php';
require __DIR__ . '/../src/ia_assistant.php';

$IA_OK =static fn (int $n, string $conf = 'haute', string $rayon = 'tartes', string $constat = 'Comptoir garni') => ['etat' => 'ok', 'niveau' => $n,
    'nom' => 'x', 'confiance' => $conf, 'constats' => [$constat], 'commentaire' => 'Commentaire ' . $n, 'motif' => null, 'modele' => 'modele-essai',
    'tache' => 'Photo du comptoir - Tartes', 'rayon' => $rayon, 'leconsVues' => 2];
$proposer = static function (array $b) { $GLOBALS['BODY'] = $b; http_response_code(200); return wr_ia_proposer(); };
$J = date('Y-m-d');

echo "Proposer\n";
$GLOBALS['APPELS'] = []; $GLOBALS['IA'] = $IA_OK(3);
$r = $proposer(['shopId' => 4, 'taskId' => 1209, 'date' => $J]);
$p = $r['proposition'] ?? [];
verifie('une proposition calculée et rendue', ($r['ok'] ?? false) && $p['niveau'] === 3 && $p['nom'] === 'Non conforme — mineur' && $p['etat'] === 'propose' && $r['cache'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));
verifie('la photo demandée au cockpit', $GLOBALS['APPELS'] === [['4', '1209', $J]]);
verifie('constats, commentaire, rayon, leçons vues gardés', $p['constats'] === ['Comptoir garni'] && $p['commentaire'] === 'Commentaire 3' && $p['rayon'] === 'tartes' && $p['leconsVues'] === 2);
$r = $proposer(['shopId' => 4, 'taskId' => 1209, 'date' => $J]);
verifie('déjà proposée : relue, pas repayée', $r['cache'] === true && count($GLOBALS['APPELS']) === 1);
$GLOBALS['IA'] = $IA_OK(4);
$r = $proposer(['shopId' => 4, 'taskId' => 1209, 'date' => $J, 'refaire' => true]);
verifie('« refaire » relance le modèle', count($GLOBALS['APPELS']) === 2 && $r['proposition']['niveau'] === 4);

$GLOBALS['IA'] = ['etat' => 'attente', 'niveau' => null, 'motif' => 'réponse coupée par la limite de jetons', 'modele' => 'modele-essai', 'tache' => 'Photo du comptoir - Quiches', 'rayon' => 'quiches'];
$r = $proposer(['shopId' => 4, 'taskId' => 1211, 'date' => $J]);
verifie('un échec s’enregistre avec son motif', $r['proposition']['etat'] === 'echec' && str_contains((string) $r['proposition']['motif'], 'coupée'));
$GLOBALS['IA'] = $IA_OK(4, 'haute', 'quiches');
$r = $proposer(['shopId' => 4, 'taskId' => 1211, 'date' => $J]);
verifie('un échec se retente au prochain appel', $r['proposition']['etat'] === 'propose' && $r['proposition']['niveau'] === 4);

$GLOBALS['IA'] = ['etat' => 'attente', 'niveau' => null, 'motif' => 'manque API — aucune clé Anthropic enregistrée'];
$r = $proposer(['shopId' => 4, 'taskId' => 1212, 'date' => $J]);
verifie('sans clé : 503, rien d’enregistré', http_response_code() === 503 && Db::row('SELECT 1 AS x FROM pil_cq_ia WHERE task_id = 1212') === null);
$r = $proposer(['shopId' => 4, 'taskId' => 0, 'date' => $J]);
verifie('requête incomplète : 400', http_response_code() === 400 && isset($r['error']));

echo "La note du consultant\n";
iaNoteHumaine(4, 1211, $J, 4, '', null, 'Sam');
$l = iaPropositionLigne(Db::row('SELECT * FROM pil_cq_ia WHERE task_id = 1211'));
verifie('notée au niveau proposé : validée', $l['etat'] === 'valide' && $l['noteFinale'] === 4 && $l['validePar'] === 'Sam');
iaNoteHumaine(4, 1209, $J, 3, 'Ventilateur à retirer', 'Un objet étranger seul reste mineur', 'Sam');
$l = iaPropositionLigne(Db::row('SELECT * FROM pil_cq_ia WHERE task_id = 1209'));
verifie('notée autrement : corrigée, raison et commentaire gardés', $l['etat'] === 'corrige' && $l['nomFinal'] === 'Non conforme — mineur' && $l['raison'] === 'Un objet étranger seul reste mineur' && $l['commentaireFinal'] === 'Ventilateur à retirer');
$GLOBALS['APPELS'] = [];
$r = $proposer(['shopId' => 4, 'taskId' => 1209, 'date' => $J, 'refaire' => true]);
verifie('déjà notée : jamais reproposée, même « refaire »', $GLOBALS['APPELS'] === [] && $r['proposition']['etat'] === 'corrige');
iaNoteHumaine(9, 9999, $J, 4, '', null, 'Sam');
verifie('une note sans proposition ne crée rien', Db::row('SELECT 1 AS x FROM pil_cq_ia WHERE task_id = 9999') === null);

echo "Les leçons\n";
// Des corrections plus anciennes sur les tartes, et une sur un autre rayon.
$ins = static function (int $task, string $jour, int $niveau, int $finale, ?string $raison, string $rayon = 'tartes', string $tache = 'Photo du comptoir - Tartes', string $le = '') {
    Db::exec('INSERT INTO pil_cq_ia (shop_id, task_id, jour, tache, rayon, niveau, confiance, constats, propose_le, note_finale, raison, valide_par, valide_le, lecon)'
        . ' VALUES (3, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)', [$task, $jour, $tache, $rayon, $niveau, 'moyenne', json_encode(['Tarte entamée au premier plan']),
        $jour . ' 06:00:00', $finale, $raison, 'Sam', $le ?: $jour . ' 09:00:00']);
};
$ins(100, '2026-11-01', 2, 4, 'Vendues au quart');
$ins(101, '2026-11-02', 3, 3, null);                                       // même note, sans raison : pas une leçon
$ins(102, '2026-11-03', 4, 4, 'Bien vu le comptoir plein');                // même note, avec raison : une leçon
$ins(103, '2026-11-04', 3, 4, null, 'quiches', 'Photo du comptoir - Quiches');
$L = iaLeconsPour('tartes', 'Photo du comptoir - Tartes');
verifie('les corrections du rayon, les plus récentes d’abord', count($L) === 3 && str_contains($L[0], 'Un objet étranger seul reste mineur') && str_contains($L[1], 'Bien vu') && str_contains($L[2], 'Vendues au quart'), json_encode($L, JSON_UNESCAPED_UNICODE));
verifie('une leçon dit la proposition, le constat et la note', str_contains($L[2], '« Non conforme — majeur »') && str_contains($L[2], 'Tarte entamée') && str_contains($L[2], 'a noté « Conforme »'));
verifie('pas les leçons d’un autre rayon', !array_filter($L, fn ($x) => str_contains($x, 'quiches')) && count(iaLeconsPour('quiches', '')) === 1);
verifie('sans rayon : par le nom de la tâche', count(iaLeconsPour(null, 'Photo du comptoir - Quiches')) === 1);
for ($i = 0; $i < 8; $i++) { $ins(200 + $i, '2026-10-' . sprintf('%02d', 10 + $i), 2, 3, 'raison ' . $i); }
verifie('six leçons au plus', count(iaLeconsPour('tartes', '')) === IA_LECONS_MAX);

$GLOBALS['BODY'] = ['shopId' => 3, 'taskId' => 100, 'date' => '2026-11-01', 'retenir' => false];
$r = wr_ia_lecons();
verifie('une leçon écartée ne sert plus', ($r['ok'] ?? false) && !array_filter(iaLeconsPour('tartes', ''), fn ($x) => str_contains($x, 'Vendues au quart')) && ($JOURNAL[0][1] ?? '') === 'Assistant de notation');
$liste = ep_ia_lecons();
$ecartee = array_values(array_filter($liste['lecons'], fn ($x) => $x['taskId'] === '100'))[0] ?? [];
verifie('elle reste listée, marquée écartée', $ecartee !== [] && $ecartee['lecon'] === false);
$GLOBALS['BODY'] = ['shopId' => 3, 'taskId' => 100, 'date' => '2026-11-01', 'retenir' => true];
wr_ia_lecons();
verifie('et se reprend', (bool) array_filter(iaLeconsPour('tartes', '', 20), fn ($x) => str_contains($x, 'Vendues au quart')));
$GLOBALS['BODY'] = ['shopId' => 3, 'taskId' => 100, 'date' => '2026-11-01', 'retenir' => true];
$r = wr_ia_lecons();
verifie('reprendre une leçon déjà retenue n’est pas une erreur', ($r['ok'] ?? false) === true);
$GLOBALS['BODY'] = ['shopId' => 3, 'taskId' => 777, 'date' => '2026-11-01', 'retenir' => true];
$r = wr_ia_lecons();
verifie('correction inconnue : 404', http_response_code() === 404 && isset($r['error']));

echo "La ponctualité\n";
$p = iaPonctualite('07:00', '2026-11-15 06:38');
verifie('22 min avant l’heure standard : à l’heure', $p['etat'] === 'heure' && $p['ecart'] === -22 && $p['verdict'] === 'à l’heure (22 min avant)', json_encode($p, JSON_UNESCAPED_UNICODE));
verifie('la phrase que lit le modèle', $p['phrase'] === 'photo prise à 06:38, heure standard de la checklist 07:00 : à l’heure (22 min avant)');
verifie('dans la tolérance : à l’heure', iaPonctualite('07:00', '07:15')['etat'] === 'heure' && iaPonctualite('07:00', '07:15')['verdict'] === 'à l’heure (15 min après, toléré)');
$p = iaPonctualite('07:00:00', '2026-11-15 08:10:42');
verifie('au-delà : en retard, en heures et minutes', $p['etat'] === 'retard' && $p['verdict'] === 'en retard de 1 h 10');
verifie('plus d’une heure avant : très en avance', iaPonctualite('14:30', '12:45')['etat'] === 'avance');
verifie('sans heure standard ou sans photo : rien', iaPonctualite(null, '06:38') === null && iaPonctualite('07:00', null) === null && iaPonctualite('7h', '06:38') === null);
$GLOBALS['IA'] = $IA_OK(4) + ['ponctualite' => iaPonctualite('07:00', '2026-11-15 07:40')];
$r = $proposer(['shopId' => 5, 'taskId' => 1300, 'date' => $J]);
verifie('la ponctualité gardée avec la proposition', ($r['proposition']['ponctualite']['etat'] ?? '') === 'retard' && $r['proposition']['ponctualite']['prevue'] === '07:00');

echo "La journée et l'accord\n";
$_GET['date'] = $J;
$j = ep_ia_propositions();
verifie('les propositions de la journée', count($j['propositions']) === 3 && $j['date'] === $J);
$a = $j['accord'];
// Dans la fenêtre : 1211 (4→4), 1209 (4→3, proposée 4 après « refaire ») et les leçons d'octobre et novembre.
verifie('le taux d’accord compte les notes identiques', $a['notees'] >= 2 && $a['pareil'] >= 1 && $a['pct'] !== null, json_encode($a));
verifie('le sens des écarts', $a['tropIndulgente'] >= 1 && $a['tropSevere'] >= 1, json_encode($a));
verifie('le nombre de leçons retenues', $j['lecons'] === count(array_filter(ep_ia_lecons()['lecons'], fn ($x) => $x['lecon'])));
$_GET['date'] = 'hier';
$r = ep_ia_propositions();
verifie('date illisible : 400', http_response_code() === 400 && isset($r['error']));

echo "Le planogramme et les photos d'exemple\n";
$JPEG = (string) file_get_contents(__DIR__ . '/../docs/maquettes/controle-taches-photos/photos/4-1207.jpg');
$GLOBALS['IA_TELECHARGER'] = static function (string $url) use ($JPEG) { $GLOBALS['TELECHARGES'][] = $url; return str_contains($url, 'perdue') ? [null, 'photo illisible (HTTP 403)'] : [$JPEG, null]; };
$DET = static fn (array $plus = []) => array_merge(['photo' => 'https://r2.exemple/signee.jpg?X-Amz-Expires=1200', 'tache' => 'Photo du comptoir - Tartes',
    'rayon' => ['cle' => 'tartes', 'nom' => 'Tartes', 'photo' => null, 'consigne' => null]], $plus);
$modeles = static function (array $b) { $GLOBALS['BODY'] = $b; http_response_code(200); return wr_ia_modeles(); };
$nEx = static fn (string $rayon) => (int) Db::row('SELECT COUNT(*) AS n FROM pil_cq_exemple WHERE rayon = ?', [$rayon])['n'];
// Une consigne déjà posée sur les tartes : poser la photo du planogramme ne doit pas l'effacer.
ensureRayonsPhotos();
Db::exec('INSERT INTO con_rayon_photo (cle, nom, photo, consigne, par, le) VALUES (?,?,?,?,?,?)', ['tartes', 'Tartes', null, 'Huit tartes minimum', 'Sam', '2026-11-01 08:00:00']);
$GLOBALS['DETAIL'] = $DET(); $GLOBALS['IMAGES'] = []; $GLOBALS['TELECHARGES'] = [];
$r = $modeles(['shopId' => 4, 'taskId' => 1209, 'date' => '2026-11-14', 'portee' => 'exemple']);
$ex = Db::row('SELECT * FROM pil_cq_exemple WHERE rayon = ?', ['tartes']);
verifie('la photo devient une photo d’exemple du rayon', ($r['ok'] ?? false) && $ex !== null && (int) $ex['shop_id'] === 4 && (int) $ex['source_task'] === 1209 && $ex['source_jour'] === '2026-11-14' && $ex['par'] === 'Sam', json_encode($r['error'] ?? null));
verifie('copiée sur le serveur, pas l’adresse signée', str_starts_with((string) $ex['photo'], 'uploads/rayons/exemples/tartes-4-1209-20261114-') && $GLOBALS['TELECHARGES'] === ['https://r2.exemple/signee.jpg?X-Amz-Expires=1200'] && $GLOBALS['IMAGES'][0][1] === 'data:image/jpeg;base64,');
$tartes = array_values(array_filter($r['rayons'], fn ($x) => $x['cle'] === 'tartes'))[0] ?? [];
verifie('la page d’entraînement la montre au rayon', count($tartes['exemples'] ?? []) === 1 && $tartes['exemples'][0]['shopId'] === '4');
$modeles(['shopId' => 4, 'taskId' => 1210, 'date' => '2026-11-15', 'portee' => 'exemple']);
$modeles(['shopId' => 3, 'taskId' => 1209, 'date' => '2026-11-15', 'portee' => 'exemple']);
verifie('plusieurs exemples par rayon, de plusieurs magasins', $nEx('tartes') === 3);
$modeles(['shopId' => 4, 'taskId' => 1210, 'date' => '2026-11-15', 'portee' => 'exemple']);
verifie('la même photo reprise ne se double pas', $nEx('tartes') === 3);
$pour3 = iaExemplesPour('tartes', 3);
verifie('pour une photo, deux exemples : ceux du magasin d’abord', count($pour3) === IA_EXEMPLES_ENVOYES && (int) $pour3[0]['shop_id'] === 3 && (int) $pour3[1]['shop_id'] === 4);
verifie('un magasin sans exemple reçoit ceux des autres', count(iaExemplesPour('tartes', 5)) === IA_EXEMPLES_ENVOYES && iaExemplesPour('quiches', 4) === []);
$r = $modeles(['shopId' => 4, 'taskId' => 1209, 'date' => '2026-11-14', 'portee' => 'planogramme']);
$rv = Db::row('SELECT * FROM con_rayon_photo WHERE cle = ?', ['tartes']);
verifie('portée planogramme : la photo du planogramme du rayon, la consigne gardée', ($r['portee'] ?? '') === 'planogramme' && str_starts_with((string) $rv['photo'], 'uploads/rayons/tartes-') && $rv['consigne'] === 'Huit tartes minimum');
$tartes = array_values(array_filter($r['rayons'], fn ($x) => $x['cle'] === 'tartes'))[0] ?? [];
verifie('la page la montre comme planogramme', ($tartes['planogramme'] ?? '') === $rv['photo']);
$GLOBALS['DETAIL'] = $DET(['rayon' => ['cle' => 'quiches', 'nom' => 'Quiches', 'photo' => null, 'consigne' => null]]);
$modeles(['shopId' => 4, 'taskId' => 1211, 'date' => '2026-11-15', 'portee' => 'planogramme']);
verifie('planogramme d’un rayon sans ligne : la ligne se crée', (string) (Db::row('SELECT photo FROM con_rayon_photo WHERE cle = ?', ['quiches'])['photo'] ?? '') !== '');
$GLOBALS['DETAIL'] = $DET(['rayon' => null, 'tache' => 'Vérifier si le magasin est propre.']);
$r = $modeles(['shopId' => 4, 'taskId' => 1216, 'date' => '2026-11-15']);
verifie('pas une photo de comptoir : 422', http_response_code() === 422 && str_contains((string) ($r['error'] ?? ''), 'rayon'));
$GLOBALS['DETAIL'] = $DET(['photo' => null]);
$r = $modeles(['shopId' => 4, 'taskId' => 1209, 'date' => '2026-11-15']);
verifie('pas de photo : 422', http_response_code() === 422);
$GLOBALS['DETAIL'] = $DET(['photo' => 'https://r2.exemple/perdue.jpg']);
$r = $modeles(['shopId' => 4, 'taskId' => 1209, 'date' => '2026-11-15']);
verifie('photo expirée : 502 avec le motif', http_response_code() === 502 && str_contains((string) ($r['error'] ?? ''), '403'));
$r = $modeles(['shopId' => 0, 'taskId' => 1209, 'date' => '2026-11-15']);
verifie('requête incomplète : 400', http_response_code() === 400);
$r = $modeles(['rayon' => 'tartes', 'shopId' => 3, 'taskId' => 1209, 'date' => '2026-11-15', 'retirer' => true]);
verifie('retirer une photo d’exemple', ($r['ok'] ?? false) && $nEx('tartes') === 2 && (int) iaExemplesPour('tartes', 3)[0]['shop_id'] === 4);
$r = $modeles(['rayon' => 'tartes', 'shopId' => 3, 'taskId' => 1209, 'date' => '2026-11-15', 'retirer' => true]);
verifie('retirer un exemple absent : 404', http_response_code() === 404);

echo "La page d'entraînement\n";
// Une photo notée Exemplaire par le consultant : candidate à l'exemple.
Db::exec('INSERT INTO pil_cq_ia (shop_id, task_id, jour, tache, rayon, niveau, confiance, constats, propose_le, note_finale, valide_par, valide_le, lecon)'
    . ' VALUES (4, 1207, ?, ?, ?, 4, ?, ?, ?, 5, ?, ?, 1)', [$J, 'Photo du comptoir - Viennoiseries', 'viennoiseries', 'haute', '[]', $J . ' 07:00:00', 'Sam', $J . ' 09:00:00']);
$e = ep_ia_entrainement();
$cles = array_column($e['rayons'], 'cle');
verifie('tous les rayons du panel', count(array_intersect(array_map('rvCle', RV_DEFAUT), $cles)) === count(RV_DEFAUT));
$t = array_values(array_filter($e['rayons'], fn ($x) => $x['cle'] === 'tartes'))[0];
verifie('la consigne et les critères par défaut du rayon', $t['consigne'] === 'Huit tartes minimum' && str_contains((string) $t['criteresDefaut'], 'au quart') && str_contains($e['communs'], 'objet'));
verifie('l’accord du rayon', $t['accord']['notees'] >= 1 && $t['accord']['jours'] === IA_ACCORD_JOURS, json_encode($t['accord']));
verifie('les leçons du rayon', count($t['lecons']) >= 3 && $t['lecons'][0]['rayon'] === 'tartes');
$cand = array_values(array_filter($e['candidates'], fn ($c) => $c['taskId'] === '1207' && $c['shopId'] === '4'));
verifie('une photo notée Exemplaire est candidate', count($cand) === 1 && $e['nomNiveauModele'] === 'Exemplaire' && $e['exemplesEnvoyes'] === IA_EXEMPLES_ENVOYES);
$GLOBALS['DETAIL'] = $DET(['rayon' => ['cle' => 'viennoiseries', 'nom' => 'Viennoiseries', 'photo' => null, 'consigne' => null]]);
$modeles(['shopId' => 4, 'taskId' => 1207, 'date' => $J, 'portee' => 'exemple']);
verifie('prise en exemple, elle n’est plus candidate', !array_filter(ep_ia_entrainement()['candidates'], fn ($c) => $c['taskId'] === '1207' && $c['shopId'] === '4'));

echo "\n$ok ok, $ko KO\n";
ob_end_flush();
exit($ko > 0 ? 1 : 0);
