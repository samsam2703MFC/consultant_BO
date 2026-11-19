<?php
declare(strict_types=1);
/**
 * Admin DB du cockpit — diagnostic et remise à zéro des tables ceo_*.
 *
 *   php bin/db_admin.php diag    liste les tables ceo_* et affiche le SHOW
 *                                CREATE des tables portant une colonne
 *                                « description » (repérage d'un schéma obsolète).
 *   php bin/db_admin.php reset   DROP toutes les tables ceo_* de la base. Les
 *                                tables du panel (non préfixées ceo_) ne sont
 *                                PAS touchées. L'auto-installation les recrée
 *                                ensuite depuis le schéma courant + seed.
 *
 * Utilise config/config.php (mêmes identifiants que l'application). À exécuter
 * depuis le déploiement servi pour hériter du bon config :
 *   php /var/www/consulant_bo/bin/db_admin.php reset
 */
require __DIR__ . '/../src/Db.php';

$action = $argv[1] ?? 'diag';
$dbName = Db::config()['db']['name'];

$rows = Db::rows(
    'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?',
    [$dbName]
);
$tables = array_values(array_filter(
    array_map(static fn ($r) => (string) $r['t'], $rows),
    static fn ($n) => str_starts_with($n, 'ceo_')
));
sort($tables);

fwrite(STDERR, sprintf(
    "[db_admin] base=%s — %d table(s) ceo_ : %s\n",
    $dbName, count($tables), $tables ? implode(', ', $tables) : '(aucune)'
));

switch ($action) {
    case 'diag':
        foreach (['ceo_project_task', 'ceo_report_schedule'] as $t) {
            if (in_array($t, $tables, true)) {
                $c = Db::row("SHOW CREATE TABLE `$t`");
                $ddl = $c['Create Table'] ?? ($c ? (string) reset($c) : '');
                fwrite(STDERR, "\n-- $t --\n$ddl\n");
            }
        }
        break;

    case 'reset':
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            Db::pdo()->exec("DROP TABLE IF EXISTS `$t`");
            fwrite(STDERR, "[db_admin] DROP $t\n");
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        fwrite(STDERR, sprintf("[db_admin] reset terminé — %d table(s) supprimée(s)\n", count($tables)));
        break;

    default:
        fwrite(STDERR, "action inconnue : $action (attendu : diag | reset)\n");
        exit(2);
}
