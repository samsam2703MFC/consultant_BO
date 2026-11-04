#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Chiffre une liste de comptes de la console pour la clé publique de dépôt du serveur (écrite au journal de
 * livraison par bin/console_bo.php) — sur son propre poste, jamais dans le dépôt en clair :
 *
 *   php bin/console_bo_seal.php cle-publique.pem comptes.json > config/console_bo_accounts.sealed
 *
 * Le pli peut être commis : seul le serveur, qui garde la clé privée, sait le lire. À la livraison suivante,
 * bin/console_bo.php le déchiffre et crée les comptes. comptes.json, lui, ne se commet jamais.
 */

require __DIR__ . '/console_bo.php';

if ($argc !== 3 || !is_file($argv[1]) || !is_file($argv[2])) {
    fwrite(STDERR, "usage : php bin/console_bo_seal.php cle-publique.pem comptes.json > config/console_bo_accounts.sealed\n");
    exit(2);
}
$pub = (string) file_get_contents($argv[1]);
$json = (string) file_get_contents($argv[2]);
if (openssl_pkey_get_public($pub) === false) {
    fwrite(STDERR, "clé publique illisible\n");
    exit(2);
}
if (!is_array(json_decode($json, true))) {
    fwrite(STDERR, "comptes.json n'est pas un JSON valide\n");
    exit(2);
}
echo cbSeal($json, $pub);
fwrite(STDERR, 'pli scellé pour la clé ' . substr(cbKeyFingerprint($pub), 0, 16) . "…\n");
