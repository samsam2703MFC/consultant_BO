<?php
declare(strict_types=1);

// Réorganisation : les 19 tables du domaine Formation prennent le préfixe for_ (registre :
// shared/src/reorg_tables.php). Un seul RENAME TABLE, données gardées, lignes comptées avant et après.
require_once __DIR__ . '/../../src/reorg_tables.php';

return [
    'quoi' => 'Formation : 19 tables → for_',
    'up' => static fn (): array => migrationRenommer(REORG_TABLES['FOR']),
    'down' => static fn (): array => migrationRendre(REORG_TABLES['FOR']),
];
