#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests du CA net d'un mois par e-mail (src/ca_net_mois.php) — base SQLite en mémoire, panel et SMTP en doublure.
 *
 *   php bin/tests_ca_net.php
 *
 * Net = TTC après remise − TVA, ticket par ticket ; une journée close gardée ; un mois lu par lots ; l'e-mail ne part
 * qu'entièrement lu, avec la TVA de chaque ticket, à une adresse connue du cockpit.
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
  static function fx(string $q): string { return str_replace('ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ON CONFLICT(key) DO UPDATE SET value = excluded.value', $q); }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }
function journalAdd(...$a) {}
function analyseListe($x) { return $x; }
function body(): array { return $GLOBALS['corps'] ?? []; }
function rapLogoDataUri(): string { return ''; }
function dsrAdressesConnues(): array { return ['direction@x.be']; }
final class Smtp { static $lastError = null; static $env = [];
  static function configured(): bool { return true; }
  static function envoyer(string $a, string $s, string $h, array $p = []): bool { self::$env[] = [$a, $s, $h]; return true; } }
final class PanelApi {
  static int $appels = 0; static bool $sansTva = false; static bool $muet = false;
  static function configured() { return true; }
  static function getParallele(array $ch, int $f = 4): array {
    $o = [];
    foreach ($ch as $k => $p) {
      self::$appels++;
      if (self::$muet) { $o[$k] = null; continue; }
      [$sid] = explode('|', (string) $k);
      // Le magasin 1 : deux tickets par jour (12,72 € TTC dont 0,72 € de TVA, et 21,20 € dont 1,20 €) ; le 3 : fermé.
      $t = (int) $sid === 1 ? [['total_gross_amount_after_discount' => 12.72, 'tax_amount_after_discount' => 0.72], ['total_gross_amount_after_discount' => 21.20, 'tax_amount_after_discount' => 1.20]]
        : ((int) $sid === 2 ? [['total_gross_amount_after_discount' => 10.60] + (self::$sansTva ? [] : ['tax_amount_after_discount' => 0.60])] : []);
      $o[$k] = $t;
    }
    return $o;
  }
}
require __DIR__ . '/../src/ca_mail.php';
require __DIR__ . '/../src/cq_mail.php';
require __DIR__ . '/../src/ca_net_mois.php';
$P = Db::pdo();
$P->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
$P->exec('CREATE TABLE shops (id INT, name TEXT, active INT)');
foreach ([[1, 'Atelier by - Florival'], [2, 'Atelier by - Bierges'], [3, 'Atelier by - Limal']] as $s) { Db::exec('INSERT INTO shops VALUES (?,?,1)', $s); }
$m = date('Y-m', strtotime('first day of last month')); $nj = (int) date('t', strtotime($m . '-01'));

verifie('la somme d’une liste : TTC, TVA, tickets', cnSomme([['total_gross_amount_after_discount' => 12.72, 'tax_amount_after_discount' => 0.72], ['total_gross_amount_after_discount' => 5]]) === [17.72, 0.72, 2, 1]);
$d = cnMois($m, 0);
verifie('sans temps : rien lu, tout reste à lire', $d['complet'] === false && $d['aLire'] === 3 * $nj, json_encode([$d['aLire'], $nj]));
PanelApi::$muet = true;
$GLOBALS['corps'] = ['mois' => $m, 'a' => 'direction@x.be']; $r = @wr_rapports_ca_net_envoyer();
PanelApi::$muet = false;
verifie('un panel muet, le mois pas lu : rien ne part', str_contains((string) ($r['error'] ?? ''), 'pas encore entièrement lu') && Smtp::$env === [], json_encode($r, JSON_UNESCAPED_UNICODE));
$d = cnMois($m);
$f = array_column($d['magasins'], null, 'nom')['Atelier by - Florival'] ?? [];
verifie('le net = TTC − TVA, ticket par ticket, sur le mois', $d['complet'] && abs($f['brut'] - 33.92 * $nj) < 0.01 && abs($f['tva'] - 1.92 * $nj) < 0.01 && abs($f['net'] - 32.0 * $nj) < 0.01, json_encode($f));
verifie('un magasin sans ticket du mois ne s’affiche pas', !in_array('Atelier by - Limal', array_column($d['magasins'], 'nom'), true) && count($d['magasins']) === 2);
verifie('le réseau', abs($d['reseau']['net'] - (32.0 + 10.0) * $nj) < 0.01 && $d['reseau']['tickets'] === 3 * $nj, json_encode($d['reseau']));
$n = PanelApi::$appels; cnMois($m);
verifie('les journées closes sont gardées : relire le mois ne coûte rien', PanelApi::$appels === $n);
$GLOBALS['corps'] = ['mois' => $m, 'a' => 'quelquun@ailleurs.com']; $r = @wr_rapports_ca_net_envoyer();
verifie('une adresse que le cockpit ne connaît pas : refusée', str_contains((string) ($r['error'] ?? ''), 'adresse inconnue') && Smtp::$env === [], json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps'] = ['mois' => $m, 'a' => 'Direction@x.be']; $r = wr_rapports_ca_net_envoyer();
verifie('envoyé, le mois entier lu, à l’adresse connue', ($r['ok'] ?? false) === true && (Smtp::$env[0][0] ?? '') === 'direction@x.be', json_encode($r, JSON_UNESCAPED_UNICODE));
$h = Smtp::$env[0][2] ?? '';
verifie('le sujet et le tableau : net, TVA, TTC par magasin', str_starts_with(Smtp::$env[0][1] ?? '', 'Chiffre d’affaires net de ') && str_contains($h, 'Florival') && str_contains($h, 'Bierges') && str_contains($h, 'CA net') && str_contains($h, number_format(32.0 * $nj, 2, ',', ' ') . ' €'));
// Un ticket sans TVA lue : le net ne se calcule pas, rien ne part.
foreach (Db::rows("SELECT key FROM ceo_app_setting WHERE key LIKE 'caNet2:%'") as $x) { Db::exec('DELETE FROM ceo_app_setting WHERE key = ?', [$x['key']]); }
PanelApi::$sansTva = true; $n = count(Smtp::$env);
$GLOBALS['corps'] = ['mois' => $m, 'a' => 'direction@x.be']; $r = @wr_rapports_ca_net_envoyer();
verifie('un ticket sans TVA au panel : dit, rien ne part', str_contains((string) ($r['error'] ?? ''), 'sans TVA') && count(Smtp::$env) === $n, json_encode($r, JSON_UNESCAPED_UNICODE));
$_GET = ['mois' => '2099-01']; $r = @ep_rapports_ca_net(); $_GET = [];
verifie('un mois à venir : refusé', ($r['error'] ?? '') !== '');
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
