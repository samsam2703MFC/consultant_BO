#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'interrupteur général des rapports (src/rapports.php, rapSuspension, 14/11/2026) — sans base, sans réseau :
 * la base et les réglages sont des doublures en mémoire.
 *
 *   php bin/tests_rapports_suspension.php
 *
 * Livré suspendu (réglage jamais posé → suspendu à la première lecture) ; suspendus, l'horloge ne lit aucun rapport ;
 * repris, elle les lit de nouveau ; une fois repris, l'interrupteur ne se repose pas tout seul ; l'écran Rapports
 * montre l'état ; la route refuse un corps sans « suspendu ».
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// --- Doublures : les réglages en mémoire ; chaque requête notée ; un rapport quotidien dû à une autre heure.
$GLOBALS['REGLAGES'] = ['rapportsJeton' => 'jeton'];
$GLOBALS['SQL'] = [];
$GLOBALS['JOURNAL'] = [];
final class Db {
  static function exec(string $q, array $a = []): int
  {
      $GLOBALS['SQL'][] = $q;
      if (str_starts_with($q, 'INSERT INTO ceo_app_setting')) { $GLOBALS['REGLAGES'][$a[0]] = json_decode($a[1], true); }
      return 1;
  }
  static function rows(string $q, array $a = []): array
  {
      $GLOBALS['SQL'][] = $q;
      if (str_contains($q, 'FROM ceo_rapport WHERE actif = 1')) {
          return [['id' => 1, 'code' => 'essai', 'nom' => 'Essai', 'frequence' => 'quotidien', 'heure' => ((int) date('G') + 1) % 24, 'jour' => 1, 'jours' => null]];
      }
      return [];
  }
  static function row(string $q, array $a = []): ?array { $GLOBALS['SQL'][] = $q; return null; }
  static function config(): array { return []; }
}
function setting(string $k, $d = null) { return $GLOBALS['REGLAGES'][$k] ?? $d; }
function body(): array { return $GLOBALS['CORPS'] ?? []; }
function journalAdd(string $actor, string $kind, ?string $project, string $message, ?string $ts = null): void { $GLOBALS['JOURNAL'][] = $message; }
require __DIR__ . '/../src/rapports.php';

$luParHorloge = static fn (): bool => array_filter($GLOBALS['SQL'], fn ($q) => str_contains($q, 'FROM ceo_rapport WHERE actif = 1')) !== [];
$cron = static function () { $_GET['jeton'] = 'jeton'; $GLOBALS['SQL'] = []; return ep_rapports_cron(); };

// --- Livré suspendu.
verifie('avant la première lecture : rien n’est posé', !isset($GLOBALS['REGLAGES']['rapportsSuspension']));
$r = $cron();
verifie('première lecture : suspendu, et gravé', ($GLOBALS['REGLAGES']['rapportsSuspension']['suspendu'] ?? null) === true, json_encode($GLOBALS['REGLAGES']['rapportsSuspension'] ?? null));
verifie('suspendus : l’horloge ne lit aucun rapport', !$luParHorloge() && $r['faits'] === [], json_encode($r['faits']));
verifie('suspendus : la réponse le dit', is_string($r['suspendus']) && str_starts_with($r['suspendus'], 'depuis le '), json_encode($r['suspendus']));
verifie('suspendus : le reste du battement continue', ($r['ok'] ?? false) === true && array_key_exists('cadence', $r) && array_key_exists('planning', $r));
$r = $cron();
verifie('l’heure suivante : toujours suspendus', !$luParHorloge() && $r['suspendus'] !== false);

// --- Reprise depuis l'écran.
$GLOBALS['CORPS'] = ['suspendu' => false];
$w = wr_rapports_suspension();
verifie('reprise : enregistrée et journalisée', ($w['ok'] ?? false) === true && ($w['suspension']['suspendu'] ?? true) === false && str_contains(end($GLOBALS['JOURNAL']), 'repris'));
$r = $cron();
verifie('repris : l’horloge relit les rapports actifs', $luParHorloge() && $r['suspendus'] === false);
$r = $cron();
verifie('repris : l’interrupteur ne se repose pas tout seul', $luParHorloge() && ($GLOBALS['REGLAGES']['rapportsSuspension']['suspendu'] ?? null) === false);

// --- Suspendre de nouveau, et les refus.
$GLOBALS['CORPS'] = ['suspendu' => true];
$w = wr_rapports_suspension();
$r = $cron();
verifie('suspendre depuis l’écran : l’horloge s’arrête', ($w['suspension']['suspendu'] ?? false) === true && !$luParHorloge() && str_contains(end($GLOBALS['JOURNAL']), 'suspendus'));
$GLOBALS['CORPS'] = [];
$w = @wr_rapports_suspension();
verifie('sans « suspendu » dans le corps : refusé, rien ne change', isset($w['error']) && ($GLOBALS['REGLAGES']['rapportsSuspension']['suspendu'] ?? null) === true);
$_GET['jeton'] = 'faux';
$r = @ep_rapports_cron();
verifie('jeton faux : refusé avant tout', isset($r['error']));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
