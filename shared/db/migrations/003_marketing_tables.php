<?php
declare(strict_types=1);

// Réorganisation : les 12 tables du domaine Marketing prennent le préfixe mar_ (registre :
// shared/src/reorg_tables.php). Un seul RENAME TABLE, données gardées, lignes comptées avant et après.
require_once __DIR__ . '/../../src/reorg_tables.php';

return [
    'quoi' => 'Marketing : 12 tables → mar_',
    'up' => static fn (): array => migrationRenommer(REORG_TABLES['MAR']),
    'down' => static fn (): array => migrationRendre(REORG_TABLES['MAR']),
];
