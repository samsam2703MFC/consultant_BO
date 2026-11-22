<?php
declare(strict_types=1);

// Réorganisation : les 29 tables du domaine Dashboard franchisé prennent le préfixe panel_ (registre :
// shared/src/reorg_tables.php). Un seul RENAME TABLE, données gardées, lignes comptées avant et après.
require_once __DIR__ . '/../../src/reorg_tables.php';

return [
    'quoi' => 'Dashboard franchisé : 29 tables → panel_',
    'up' => static fn (): array => migrationRenommer(REORG_TABLES['PANEL']),
    'down' => static fn (): array => migrationRendre(REORG_TABLES['PANEL']),
];
