#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la relecture du personnel (src/ventes.php, venteEmployesConnus, 14/11/2026) — base SQLite en mémoire,
 * panel en doublure.
 *
 *   php bin/tests_employes_relus.php
 *
 * Un vendeur de ticket que le cockpit ne nomme pas (« Employé 145 ») se relit : la liste du personnel des magasins,
 * sans attendre la fin de l'heure, puis la fiche de la personne ; ce qui est lu est gardé ; une fiche que le panel
 * ne rend pas ne se redemande pas à chaque lecture ; rien n'est relu quand tout le monde a un nom.
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
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string { return str_replace(['ceo_app_setting VALUES', 'ON DUPLICATE KEY UPDATE value = VALUES(value)'], ['ceo_app_setting (key, value) VALUES', 'ON CONFLICT(key) DO UPDATE SET value = excluded.value'], $q); }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
  static function config(): array { return []; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }
function analyseListe($x) { return is_array($x) ? $x : []; }
/** Le panel : les listes du personnel par magasin, les fiches une à une ; chaque appel est noté. */
final class PanelApi {
  static array $listes = [];
  static array $fiches = [];
  static array $appels = [];
  static function configured(): bool { return true; }
  static function getParallele(array $ch, int $f = 4): array
  {
      $o = [];
      foreach ($ch as $k => $p) { self::$appels[] = $p; $o[$k] = self::$listes[$p] ?? null; }
      return $o;
  }
  static function getParalleleDetail(array $ch, int $f = 4, int $t = 25): array
  {
      $o = [];
      foreach ($ch as $k => $p) {
          self::$appels[] = $p;
          $o[$k] = isset(self::$fiches[$p]) ? ['code' => 200, 'data' => self::$fiches[$p]] : ['code' => 404, 'data' => null, 'erreur' => 'HTTP 404'];
      }
      return $o;
  }
}
require __DIR__ . '/../src/ventes.php';

$P = Db::pdo();
$P->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
$P->exec('CREATE TABLE shops (id INTEGER PRIMARY KEY, active INT)');
$P->exec('CREATE TABLE franchisee_employee (id INTEGER PRIMARY KEY, id_shop INT, display_name TEXT, name TEXT, surname TEXT)');
$P->exec("INSERT INTO shops VALUES (4, 1)");
// La table locale : Sara (136) nommée ; le 12 sans aucun nom.
$P->exec("INSERT INTO franchisee_employee VALUES (136, 4, 'Sara D.', 'Sara', 'Dupont'), (12, 4, '', '', '')");
// La liste du panel gravée il y a dix minutes (encore valable une heure) : elle ne connaît que Sara.
Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
    ['pvEmployes', json_encode(['v' => 2, 'quand' => time() - 600, 'e' => [136 => ['nom' => 'Sara D.', 'shop' => '4']]])]);
// Le panel aujourd'hui : Halle compte aussi Hugo (12) et Madina, embauchée ce matin (150). Le 145, un compte supprimé,
// n'est plus dans la liste de son magasin mais garde sa fiche. Le 146 n'a pas de fiche lisible.
PanelApi::$listes['/shops/4/employees'] = [
    ['id' => 136, 'id_shop' => 4, 'display_name' => 'Sara D.'],
    ['id' => 12, 'id_shop' => 4, 'display_name' => 'Hugo P.', 'franchise_role' => 'franchisee'],
    ['id' => 150, 'id_shop' => 4, 'display_name' => '', 'name' => 'Madina', 'surname' => 'T.']];
PanelApi::$fiches['/employees/145'] = ['id' => 145, 'id_shop' => 4, 'display_name' => 'Yasmine K.', 'pin' => '1234', 'password' => 'secret'];

// Une liste relue il y a moins de cinq minutes ne se relit pas (un compte qui reste inconnu ne la fait pas relire à
// chaque écran) : seule la fiche est demandée. Dans un processus à part — la relecture se fait une fois par requête.
if (($argv[1] ?? '') === 'liste-recente') {
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['pvEmployes', json_encode(['v' => 2, 'quand' => time() - 60, 'e' => [136 => ['nom' => 'Sara D.', 'shop' => '4']]])]);
    $E = venteEmployesConnus([136, 145]);
    exit(PanelApi::$appels === ['/employees/145'] && ($E[145]['nom'] ?? '') === 'Yasmine K.' ? 0 : 1);
}

$E = venteEmployes();
verifie('avant : le 12 n’a que son numéro, le 145 et le 150 sont inconnus', ($E[12]['nom'] ?? '') === 'Employé 12' && !isset($E[145]) && !isset($E[150]), json_encode($E));
verifie('avant : la liste gravée sert, sans appel au panel', PanelApi::$appels === []);

$E = venteEmployesConnus([136, 136, 0, 12, 145, 146, 150]);
verifie('la liste du magasin est relue sans attendre la fin de l’heure', in_array('/shops/4/employees', PanelApi::$appels, true), json_encode(PanelApi::$appels));
verifie('une embauche du jour prend son nom', ($E[150]['nom'] ?? '') === 'Madina T.');
verifie('une fiche locale sans nom prend celui du panel, et garde le statut de franchisé', ($E[12]['nom'] ?? '') === 'Hugo P.' && !empty($E[12]['franchise']), json_encode($E[12] ?? null));
verifie('le compte hors liste prend le nom de sa fiche', ($E[145]['nom'] ?? '') === 'Yasmine K.' && ($E[145]['shop'] ?? '') === '4');
verifie('une fiche n’est lue que pour ceux qui manquent encore après la liste', array_values(array_filter(PanelApi::$appels, fn ($p) => str_starts_with($p, '/employees/'))) === ['/employees/145', '/employees/146'], json_encode(PanelApi::$appels));
verifie('sans fiche lisible : le numéro reste, rien n’est inventé', !isset($E[146]) || ($E[146]['nom'] ?? '') === 'Employé 146');

$F = setting('pvEmployesFiches');
verifie('la fiche lue est gardée : le nom et le magasin, aucun secret', ($F['e'][145] ?? null) === ['nom' => 'Yasmine K.', 'shop' => '4'] && !str_contains(json_encode($F), '1234') && !str_contains(json_encode($F), 'secret'), json_encode($F));
verifie('la fiche introuvable est notée, pour ne pas la redemander à chaque lecture', (int) ($F['rate'][146] ?? 0) >= time() - 5);
$G = setting('pvEmployes');
verifie('la liste relue est regravée maintenant, avec l’embauche du jour', (int) ($G['quand'] ?? 0) >= time() - 5 && isset($G['e'][150]));

$E = venteEmployes();
verifie('ensuite, sans relecture : le 145 et le 12 sont nommés partout', ($E[145]['nom'] ?? '') === 'Yasmine K.' && ($E[12]['nom'] ?? '') === 'Hugo P.' && ($E[150]['nom'] ?? '') === 'Madina T.', json_encode($E));

$n = count(PanelApi::$appels);
venteEmployesConnus([136, 12, 145, 150]);
verifie('tout le monde a un nom : aucun appel au panel', count(PanelApi::$appels) === $n);
venteEmployesConnus([146, 999]);
verifie('une seule relecture par requête, même pour un autre inconnu', count(PanelApi::$appels) === $n);

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' liste-recente', $sortie, $code);
verifie('une liste relue il y a moins de cinq minutes ne se relit pas : seule la fiche est demandée', $code === 0, implode(' ', $sortie));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
