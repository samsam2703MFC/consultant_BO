#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Benchmark concurrents — en ligne de commande, depuis le déploiement servi
 * (config/config.php, comme l'application).
 *
 *   php bin/benchmark.php verifier [--shop=4] [--ecrire]   les routes Benchmark de TFBuddy, une par une
 *   php bin/benchmark.php semer cougnou|galette|tout [--appliquer] [--si-nouveau] [--shop=2,4]
 *                                                           pose le relevé dans TFBuddy (simulation sans --appliquer ;
 *                                                           --si-nouveau : rien si ce relevé est déjà posé)
 *   php bin/benchmark.php rapport cougnou|galette|tout [--html=fichier] [--sans-web]
 *                                                           le rapport (prix comparable + visibilité web)
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
    case 'cougnou':   // ancien nom : « semer cougnou »
    case 'galette':
        array_splice($args, 0, 1, ['semer', $cmd]);
        // no break
    case 'semer':
        $f = $args[1] ?? 'tout';
        $shops = $opt('shop') !== null ? array_map('intval', explode(',', (string) $opt('shop'))) : [];
        $simuler = $opt('appliquer') === null;
        $nouveau = $opt('si-nouveau') !== null;
        $res = $f === 'tout' ? benchmarkSemerTout($simuler, $shops, $nouveau)['familles'] : [$f => benchmarkSemer($f, $simuler, $shops, $nouveau)];
        $okTout = true;
        foreach ($res as $fam => $r) {
            $out("== $fam");
            foreach ($r['journal'] as $l) { $out('  ' . $l); }
            $out(($r['simuler'] ? 'SIMULATION (ajoutez --appliquer) — ' : '') . json_encode($r['compte'], JSON_UNESCAPED_UNICODE) . (isset($r['erreur']) ? ' — ' . $r['erreur'] : ''));
            $okTout = $okTout && $r['ok'];
        }
        exit($okTout ? 0 : 1);
    case 'rapport':
        $f = $args[1] ?? 'tout';
        $f = str_starts_with($f, '--') ? 'tout' : $f;
        $rs = array_map(fn ($x) => benchmarkRapport($x, $opt('sans-web') === null), $f === 'tout' ? BENCHMARK_FAMILLES : [$f]);
        if (($fic = $opt('html')) !== null && $fic !== '1') {
            file_put_contents($fic, benchmarkHtml($rs));
            $out("rapport HTML écrit : $fic");
        } else {
            $out(json_encode(count($rs) === 1 ? $rs[0] : $rs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        exit(0);
    case 'routine':
        $r = benchmarkRoutine(true, $opt('sans-mail') === null);
        $out(json_encode(['fait' => $r['fait'], 'familles' => $r['familles'] ?? [], 'resume' => $r['verification']['resume'] ?? null, 'envois' => $r['envois'] ?? [], 'web' => $r['web'] ?? null], JSON_UNESCAPED_UNICODE));
        exit(($r['verification']['ok'] ?? false) ? 0 : 1);
    default:
        $out("usage : php bin/benchmark.php verifier|semer|rapport|routine [options] — voir l'en-tête du fichier");
        exit($cmd === 'aide' ? 0 : 1);
}
