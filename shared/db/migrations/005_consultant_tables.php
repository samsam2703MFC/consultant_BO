<?php
declare(strict_types=1);

// Réorganisation : les 84 tables du domaine Pilotage consultant prennent le préfixe con_ (registre :
// shared/src/reorg_tables.php). Un seul RENAME TABLE, données gardées, lignes comptées avant et après.
require_once __DIR__ . '/../../src/reorg_tables.php';

return [
    'quoi' => 'Pilotage consultant : 84 tables → con_',
    'up' => static fn (): array => migrationRenommer(REORG_TABLES['CON']),
    'down' => static fn (): array => migrationRendre(REORG_TABLES['CON']),
];
