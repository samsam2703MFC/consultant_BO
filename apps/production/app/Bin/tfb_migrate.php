#!/usr/bin/env php
<?php
declare(strict_types=1);
/*
 * Migrations du module « Gestion de production atelier » (tables tfb_*).
 *
 *   php Bin/tfb_migrate.php status   état de chaque fichier de migrations/tfb
 *   php Bin/tfb_migrate.php plan     ce qu'apply exécuterait
 *   php Bin/tfb_migrate.php apply    applique les migrations en attente
 *
 * Code de sortie : 0 succès, 1 erreur, 2 fichier appliqué modifié depuis (drift).
 */
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Services\Workshop\Migration\MigrationRunner;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$command = $argv[1] ?? 'status';
if (!in_array($command, ['status', 'plan', 'apply'], true)) {
    fwrite(STDERR, "Usage: php Bin/tfb_migrate.php status|plan|apply\n");
    exit(1);
}

try {
    $pdo = (new App\Core\Database())->getConnection();
    $runner = new MigrationRunner($pdo, $root . '/migrations/tfb');

    if ($command === 'apply') {
        $done = $runner->apply(static fn(string $line) => print($line . PHP_EOL));
        echo $done ? count($done) . " migration(s) applied.\n" : "Nothing to apply.\n";
        exit(0);
    }

    $rows = $runner->status();
    $drift = false;
    foreach ($rows as $row) {
        if ($command === 'plan' && $row['state'] !== 'pending') continue;
        $drift = $drift || in_array($row['state'], ['drift', 'missing'], true);
        printf("%-8s %-40s %s\n", $row['state'], $row['name'], $row['applied_at'] ?? '');
    }
    exit($drift ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'tfb_migrate: ' . $e->getMessage() . "\n");
    exit(str_contains($e->getMessage(), 'differ from the files') ? 2 : 1);
}
