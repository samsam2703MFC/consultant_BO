#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Benchmark concurrents — en ligne de commande, depuis le déploiement servi
 * (config/config.php, comme l'application).
 *
 *   php bin/benchmark.php verifier [--shop=4] [--ecrire]   les routes Benchmark de TFBuddy, une par une
 *   php bin/benchmark.php cougnou [--appliquer] [--shop=2,4] pose le relevé cougnou (simulation sans --appliquer)
 *   php bin/benchmark.php rapport [--html=fichier] [--sans-web]   le rapport cougnou (prix au kilo + visibilité web)
 *   php bin/benchmark.php routine [--sans-mail]             la routine du lundi/jeudi, tout de suite
 *
 * Code de sortie : 0 si tout est passé, 1 sinon — utilisable en supervision.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/endpoints.php';
require __DIR__ . '/../src/writes.php';
require __DIR__ . '/../src/panel_api.php';
require __DIR__ . '/../src/google_api.php';
require __DIR__ . '/../src/smtp.php';
require __DIR__ . '/../src/benchmark.php';

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'aide';
$opt = static function (string $n) use ($args): ?string {
    foreach ($args as $a) {
        if ($a === "--$n") { return '1'; }
        if (str_starts_with($a, "--$n=")) { return substr($a, strlen($n) + 3); }
    }
    return null;
};
$out = static fn (string $m) => fwrite(STDOUT, $m . "\n");

switch ($cmd) {
    case 'verifier':
        $r = benchmarkVerifier((int) ($opt('shop') ?? 4), $opt('ecrire') !== null);
        foreach ($r['etapes'] as $e) {
            $out(sprintf('  %s  %-6s %-4d %5d ms  %-36s %s', $e['ok'] ? 'ok' : 'KO', $e['methode'], $e['code'], $e['ms'], $e['etape'], $e['note']));
        }
        foreach ($r['alertes'] as $a) { $out('  !  ' . $a); }
        $out($r['resume']);
        exit($r['ok'] ? 0 : 1);
    case 'cougnou':
        $shops = $opt('shop') !== null ? array_map('intval', explode(',', (string) $opt('shop'))) : [];
        $r = benchmarkCougnouSemer($opt('appliquer') === null, $shops);
        foreach ($r['journal'] as $l) { $out('  ' . $l); }
        $out(($r['simuler'] ? 'SIMULATION (ajoutez --appliquer) — ' : '') . json_encode($r['compte'], JSON_UNESCAPED_UNICODE) . (isset($r['erreur']) ? ' — ' . $r['erreur'] : ''));
        exit($r['ok'] ? 0 : 1);
    case 'rapport':
        $r = benchmarkCougnouRapport($opt('sans-web') === null);
        if (($f = $opt('html')) !== null && $f !== '1') {
            file_put_contents($f, benchmarkCougnouHtml($r));
            $out("rapport HTML écrit : $f");
        } else {
            $out(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        exit(0);
    case 'routine':
        $r = benchmarkRoutine(true, $opt('sans-mail') === null);
        $out(json_encode(['fait' => $r['fait'], 'resume' => $r['verification']['resume'] ?? null, 'envois' => $r['envois'] ?? [], 'web' => $r['web'] ?? null], JSON_UNESCAPED_UNICODE));
        exit(($r['verification']['ok'] ?? false) ? 0 : 1);
    default:
        $out("usage : php bin/benchmark.php verifier|cougnou|rapport|routine [options] — voir l'en-tête du fichier");
        exit($cmd === 'aide' ? 0 : 1);
}
