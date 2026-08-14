<?php
/**
 * Configuration base de données — copiez ce fichier vers config/config.php
 * et adaptez les valeurs (ou définissez les variables d'environnement).
 *
 * En production, pointez sur la base du Manuel Opératoire (franchise_buddy_db)
 * pour que le cockpit lise of_tag / kpi / position existants.
 */
return [
    'db' => [
        'host'     => getenv('COCKPIT_DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('COCKPIT_DB_PORT') ?: '3306',
        'name'     => getenv('COCKPIT_DB_NAME') ?: 'franchise_buddy_db',
        'user'     => getenv('COCKPIT_DB_USER') ?: 'cockpit',
        'password' => getenv('COCKPIT_DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],
];
