<?php
declare(strict_types=1);

// La Prospection passe dans le portail Commercial (19/11/2026, Sam : « transvaser tout le /prospection/ dans le
// /commercial ») : sa table prend le préfixe sm_ (registre : shared/src/reorg_tables.php, groupe SM). Un seul RENAME
// TABLE, données gardées, lignes comptées avant et après.
require_once __DIR__ . '/../../src/reorg_tables.php';

return [
    'quoi' => 'Commercial : la table de la Prospection → sm_prospect',
    'up' => static fn (): array => migrationRenommer(REORG_TABLES['SM']),
    'down' => static fn (): array => migrationRendre(REORG_TABLES['SM']),
];
