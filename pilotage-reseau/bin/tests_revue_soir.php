#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la revue du soir (src/revue_soir.php) — base SQLite en mémoire, routes du cockpit en doublure.
 *
 *   php bin/tests_revue_soir.php
 *
 * Le point « caisse » ne parle plus d'écart (le monnayeur compte l'argent, 19/11/2026) : par défaut, et dans un
 * réglage enregistré avant (version 1) qui en parlait encore ; un point « caisse » déjà réécrit sans écart, ou
 * réenregistré en version 2, reste tel quel. Chaque checklist pas finie dit ses tâches pas faites, à l'heure prévue,
 * avec ce qui manque (pas rendue, ou cochée sans la photo demandée).
 */
ob_start();   // les routes du cockpit posent un code HTTP : rien ne doit être déjà écrit
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
  static function fx(string $q): string { return str_replace('ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ON CONFLICT(key) DO UPDATE SET value = excluded.value', $q); }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }
function journalAdd(...$a) { $GLOBALS['JOURNAL'][] = $a; }
function currentUserName(): string { return 'test'; }

// Les tâches du jour du magasin 4, telles que /pwa/tasks les rend.
$GLOBALS['TACHES'] = [
  ['tache' => 'Nettoyer la vitrine', 'statut' => 'nonRendue', 'obligatoire' => true, 'checklist' => '1.1.01 · Ouverture', 'heure' => '09:30'],
  ['tache' => 'Photo du comptoir', 'statut' => 'sansPhoto', 'photoRequise' => true, 'obligatoire' => true, 'checklist' => '1.1.01 · Ouverture', 'heure' => '07:00'],
  ['tache' => 'Allumer les fours', 'statut' => 'aControler', 'obligatoire' => true, 'checklist' => '1.1.01 · Ouverture', 'heure' => '06:00'],
  ['tache' => 'Sortir les poubelles', 'statut' => 'nonRendue', 'obligatoire' => true, 'checklist' => '1.1.01 · Ouverture'],
  ['tache' => 'Cochée sans photo, aucune demandée', 'statut' => 'sansPhoto', 'photoRequise' => false, 'obligatoire' => true, 'checklist' => '1.1.01 · Ouverture', 'heure' => '08:00'],
  ['tache' => 'Facultative pas faite', 'statut' => 'nonRendue', 'obligatoire' => false, 'checklist' => '1.1.01 · Ouverture', 'heure' => '08:00'],
  ['tache' => 'Laver le sol', 'statut' => 'notee', 'obligatoire' => true, 'checklist' => '4.1.01 · Fermeture', 'heure' => '18:30'],
];
function route(string $m, string $path): array
{
  if ($path === '/pwa/tasks') {
    return ['shops' => [['shopId' => '4', 'taches' => $GLOBALS['TACHES'],
      'checklists' => [['nom' => '1.1.01 · Ouverture', 'heure' => '06:00'], ['nom' => '4.1.01 · Fermeture', 'heure' => '18:00']]]]];
  }
  http_response_code(404);
  return ['error' => 'route inconnue'];
}
require __DIR__ . '/../src/revue_soir.php';

Db::pdo()->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
$caisseDe = function (array $c): ?array { foreach ($c['points'] as $p) { if ($p['id'] === 'caisse') { return $p; } } return null; };
$parleEcart = fn (array $p) => (bool) preg_match('/[ée]cart/iu', json_encode([$p['titre'], $p['question'], $p['reponses']], JSON_UNESCAPED_UNICODE));

echo "Le point caisse, sans écart\n";
$d = $caisseDe(rsDefaut());
verifie('par défaut, la caisse ne parle pas d’écart', $d && !$parleEcart($d), json_encode($d, JSON_UNESCAPED_UNICODE));
verifie('par défaut, « clôturée » compte en ordre, « pas encore » non', $d && $d['reponses'] === [['t' => 'Oui, clôturée', 'ok' => true], ['t' => 'Pas encore', 'ok' => false]]);
verifie('sans réglage enregistré, la revue lit le défaut', !$parleEcart($caisseDe(rsConfig())));

// Un réglage enregistré avant le 19/11/2026 : la caisse d'origine, et un autre point retouché.
$v1 = rsDefaut(); $v1['version'] = 1;
foreach ($v1['points'] as &$p) {
  if ($p['id'] === 'caisse') { $p['titre'] = 'Caisse comptée et clôturée'; $p['question'] = 'Fond de caisse compté, écart noté, journée fermée dans la caisse ?';
    $p['reponses'] = [['t' => 'Oui, sans écart', 'ok' => true], ['t' => 'Oui, avec écart', 'ok' => false], ['t' => 'Pas encore', 'ok' => false]]; $p['actif'] = false; }
  if ($p['id'] === 'feedback') { $p['question'] = 'Retours donnés ?'; }
}
unset($p);
Db::exec('INSERT INTO ceo_app_setting VALUES (?,?)', ['revueSoir', json_encode($v1, JSON_UNESCAPED_UNICODE)]);
$c = rsConfig(); $ca = $caisseDe($c);
verifie('réglage v1 : la caisse perd l’écart', $ca && !$parleEcart($ca), json_encode($ca, JSON_UNESCAPED_UNICODE));
verifie('réglage v1 : la caisse garde son id, son icône et son état actif', $ca && $ca['id'] === 'caisse' && $ca['icone'] === '💶' && $ca['actif'] === false);
verifie('réglage v1 : les autres points ne bougent pas', (function () use ($c) { foreach ($c['points'] as $p) { if ($p['id'] === 'feedback') { return $p['question'] === 'Retours donnés ?'; } } return false; })());
verifie('réglage v1 : lu en version 2', ($c['version'] ?? null) === 2);
verifie('réglage v1 : la revue d’un soir pose la caisse sans écart', (function () { foreach (rsConfig()['points'] as $p) { if ($p['id'] === 'caisse') { return $p['question'] === 'La journée est-elle clôturée dans la caisse ?'; } } return false; })());

// Une caisse déjà réécrite sans écart au cockpit (version 1) : gardée telle quelle.
$v1b = $v1;
foreach ($v1b['points'] as &$p) { if ($p['id'] === 'caisse') { $p['question'] = 'Z imprimé ?'; $p['reponses'] = [['t' => 'Oui', 'ok' => true], ['t' => 'Non', 'ok' => false]]; $p['titre'] = 'Caisse'; } }
unset($p);
Db::exec('UPDATE ceo_app_setting SET value = ? WHERE key = ?', [json_encode($v1b, JSON_UNESCAPED_UNICODE), 'revueSoir']);
$ca = $caisseDe(rsConfig());
verifie('réglage v1 sans écart : la caisse retouchée est gardée', $ca && $ca['question'] === 'Z imprimé ?' && $ca['titre'] === 'Caisse');

// Réenregistré au cockpit (version 2) : ce qui est saisi fait foi, même s'il reparle d'écart.
$c = rsConfig();
foreach ($c['points'] as &$p) { if ($p['id'] === 'caisse') { $p['question'] = 'Écart de caisse noté ?'; } }
unset($p);
$r = rsEnregistrer($c);
verifie('enregistré : version 2', ($r['config']['version'] ?? null) === 2);
$ca = $caisseDe(rsConfig());
verifie('réglage v2 : la caisse saisie au cockpit reste telle quelle', $ca && $ca['question'] === 'Écart de caisse noté ?');

echo "Les tâches pas faites\n";
$C = rsChecklistsDuJour(4, '2026-11-19');
verifie('lu, 6 obligatoires dont 3 faites', $C['lu'] === true && $C['taches'] === 6 && $C['faites'] === 3, json_encode([$C['taches'], $C['faites']]));
verifie('une seule checklist pas finie : Ouverture', count($C['liste']) === 1 && $C['liste'][0]['nom'] === '1.1.01 · Ouverture');
$L = $C['liste'][0]['pasFaites'] ?? [];
verifie('ses tâches pas faites, rangées à l’heure (sans heure à la fin)', array_column($L, 'tache') === ['Photo du comptoir', 'Nettoyer la vitrine', 'Sortir les poubelles'], json_encode(array_column($L, 'tache'), JSON_UNESCAPED_UNICODE));
verifie('l’heure prévue de chacune', array_column($L, 'heure') === ['07:00', '09:30', null]);
verifie('cochée sans la photo demandée → « photo », pas rendue → « pasFaite »', array_column($L, 'etat') === ['photo', 'pasFaite', 'pasFaite']);
verifie('ni la facultative ni la cochée sans photo non demandée', !in_array('Facultative pas faite', array_column($L, 'tache'), true) && !in_array('Cochée sans photo, aucune demandée', array_column($L, 'tache'), true));
verifie('manquent : les mêmes noms, pour un écran d’avant', $C['liste'][0]['manquent'] === array_column($L, 'tache'));
verifie('toutes : Ouverture 2/5, Fermeture 1/1', array_map(fn ($c) => [$c['nom'], $c['faites'], $c['total']], $C['toutes']) === [['1.1.01 · Ouverture', 2, 5], ['4.1.01 · Fermeture', 1, 1]]);

echo "\n$ok ok, $ko KO\n";
exit($ko ? 1 : 0);
