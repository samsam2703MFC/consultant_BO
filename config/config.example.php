<?php
/**
 * Configuration — copiez ce fichier vers config/config.php SUR LE SERVEUR
 * (hors Git, comme le config/db.local.php du panel consultant) et renseignez
 * les identifiants. Les variables d'environnement, si présentes, priment.
 *
 * En production, pointez sur la base du panel consultant (atelierby_db) :
 * le cockpit y lit of_tag / kpi / position et mac_report_share, et y crée
 * ses propres tables préfixées ceo_.
 */
return [
    'db' => [
        'host'     => getenv('COCKPIT_DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('COCKPIT_DB_PORT') ?: '3306',
        'name'     => getenv('COCKPIT_DB_NAME') ?: 'atelierby_db',
        'user'     => getenv('COCKPIT_DB_USER') ?: 'REMPLACER_USER',
        'password' => getenv('COCKPIT_DB_PASSWORD') ?: 'REMPLACER_PASS',
        'charset'  => 'utf8mb4',
    ],
    // Base d'URL du panel consultant (rapports) — prime sur le paramètre
    // pwaBase de ceo_app_setting. Ex. : http://185.180.206.46/pwa_consultant
    'pwaBase' => getenv('COCKPIT_PWA_BASE') ?: null,
];
