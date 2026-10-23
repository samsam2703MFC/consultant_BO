<?php
declare(strict_types=1);

/**
 * Le module Motivation : primes de l'app worker, ventes croisées, meilleure vendeuse, record, prime B2B, grands prix
 * de l'année. Toutes ses tables portent le préfixe mot_ (23/10/2026, Sam), pour que le module s'exporte tel quel
 * vers l'ERP (docs/module-motivation.md).
 *
 *   mot_reglage     ce que la marque et les franchisés règlent, et ce qui est gravé (primes enregistrées, SMS
 *                   envoyés) : une clé, une valeur JSON. Ne se vide jamais.
 *   mot_cache       ce qui se recalcule (fiches du mois, moyennes, catalogue, ventes du jour relues) : une clé, une
 *                   valeur JSON. Se vide sans rien perdre.
 *   mot_grand_prix  les grands prix de l'année, du réseau (la marque) ou d'un magasin (src/grands_prix.php).
 *
 * Avant, tout vivait dans ceo_app_setting : la première lecture y reprend les clés du module puis les y efface
 * (motReprendre). setting() renvoie encore ici une clé du module, pour du code qui l'appellerait par habitude.
 */

/** Les réglages du module, clé exacte. */
const MOT_REGLAGES = ['ventePrimesApp', 'venteCroisees', 'ventePrimes', 'ventePrimesHist', 'ventePrimesCrossHist',
    'venteCrossPaliers', 'venteCrossTargets', 'venteCrossMontant', 'venteCrossMontantShop',
    'venteRecordEurDixieme', 'venteRecordMaxDixiemes', 'ventePrimeMagasin', 'venteConcoursTQ', 'ventePrimeGoogle'];
/** Les réglages du module, par début de clé (un mois, un magasin). */
const MOT_REGLAGES_DEBUT = ['primesSms'];
/** Les caches du module, par début de clé. */
const MOT_CACHES_DEBUT = ['primesColl', 'primesClos', 'primesMoi', 'primesS12', 'primesTous', 'primesJeton',
    'primesPromos3', 'primesCatalogue', 'prMoy', 'b2bM', 'pvJ', 'vmMois', 'grandsPrix'];
/** La ligne de mot_reglage qui dit que la reprise depuis ceo_app_setting est faite. */
const MOT_REPRISE = '_repris_de_ceo_app_setting';

/** 'reglage', 'cache' ou null : la table du module où vit une clé, s'il y en a une. */
function motCle(string $cle): ?string
{
    if (in_array($cle, MOT_REGLAGES, true)) { return 'reglage'; }
    foreach (MOT_REGLAGES_DEBUT as $d) { if (str_starts_with($cle, $d)) { return 'reglage'; } }
    foreach (MOT_CACHES_DEBUT as $d) { if (str_starts_with($cle, $d)) { return 'cache'; } }
    return null;
}

function motTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    foreach (['mot_reglage', 'mot_cache'] as $t) {
        Db::exec('CREATE TABLE IF NOT EXISTS ' . $t . ' ('
            . 'cle VARCHAR(120) NOT NULL PRIMARY KEY,'
            . 'valeur MEDIUMTEXT NOT NULL,'
            . 'maj TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
    // Les grands prix de l'année (src/grands_prix.php) : une ligne par grand prix ; « resultat », le classement gravé
    // une fois la saison finie.
    Db::exec('CREATE TABLE IF NOT EXISTS mot_grand_prix ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY,'
        . 'portee VARCHAR(10) NOT NULL,'              // reseau (la marque) ou magasin (un franchisé)
        . 'shop VARCHAR(20) NULL,'
        . 'defi VARCHAR(20) NOT NULL,'
        . 'titre VARCHAR(120) NOT NULL,'
        . 'lot VARCHAR(255) NULL,'
        . 'valeur INT NOT NULL DEFAULT 0,'
        . 'payeur VARCHAR(10) NOT NULL,'              // marque, magasin ou sponsor
        . 'sponsor VARCHAR(120) NULL,'
        . 'sponsor_site VARCHAR(190) NULL,'
        . 'photo VARCHAR(190) NULL,'
        . 'seuil INT NULL,'                           // heures (vendeuse, progression) ou tickets (ventes croisées) au minimum
        . 'du DATE NOT NULL,'
        . 'au DATE NOT NULL,'
        . 'actif TINYINT(1) NOT NULL DEFAULT 1,'
        . 'resultat MEDIUMTEXT NULL,'
        . 'cree_le DATETIME NOT NULL,'
        . 'maj TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        . 'KEY idx_gp_shop (portee, shop)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if (Db::row('SELECT valeur FROM mot_reglage WHERE cle = ?', [MOT_REPRISE]) === null) { motReprendre(); }
}

/**
 * La reprise, une fois : les clés du module passent de ceo_app_setting à mot_reglage ou mot_cache. Ce qui est déjà
 * dans mot_ gagne (INSERT IGNORE) ; une clé n'est effacée de ceo_app_setting qu'une fois copiée.
 */
function motReprendre(): void
{
    try {
        $par = ['mot_reglage' => [MOT_REGLAGES, MOT_REGLAGES_DEBUT], 'mot_cache' => [[], MOT_CACHES_DEBUT]];
        $n = 0;
        foreach ($par as $table => [$exactes, $debuts]) {
            $ou = []; $a = [];
            if ($exactes !== []) { $ou[] = 'CAST(`key` AS BINARY) IN (' . implode(',', array_fill(0, count($exactes), '?')) . ')'; array_push($a, ...$exactes); }
            foreach ($debuts as $d) { $ou[] = 'CAST(`key` AS BINARY) LIKE ?'; $a[] = $d . '%'; }
            $w = implode(' OR ', $ou);
            Db::exec('INSERT IGNORE INTO ' . $table . ' (cle, valeur) SELECT `key`, value FROM ceo_app_setting WHERE ' . $w, $a);
            $n += Db::exec('DELETE s FROM ceo_app_setting s JOIN ' . $table . ' m ON m.cle = CAST(s.`key` AS BINARY) WHERE ' . $w, $a);
        }
        Db::exec('INSERT INTO mot_reglage (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)',
            [MOT_REPRISE, json_encode(['le' => date('c'), 'cles' => $n])]);
        if ($n > 0 && function_exists('journalAdd')) { journalAdd('CEO', 'Paramètre', 'Motivation', $n . ' réglage(s) et cache(s) des primes rangés dans les tables mot_'); }
    } catch (Throwable $e) {
        // La reprise se refera à la prochaine lecture ; en attendant, setting() lit encore l'ancienne table.
        error_log('motReprendre : ' . $e->getMessage());
    }
}

function motLire(string $table, string $cle, mixed $defaut): mixed
{
    motTables();
    $r = Db::row('SELECT valeur FROM ' . $table . ' WHERE cle = ?', [$cle]);
    return $r === null ? $defaut : json_decode((string) $r['valeur'], true);
}

function motEcrire(string $table, string $cle, mixed $valeur): void
{
    motTables();
    Db::exec('INSERT INTO ' . $table . ' (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)',
        [$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE)]);
}

function motReglage(string $cle, mixed $defaut = null): mixed { return motLire('mot_reglage', $cle, $defaut); }
function motReglageEcrire(string $cle, mixed $valeur): void { motEcrire('mot_reglage', $cle, $valeur); }
function motCache(string $cle, mixed $defaut = null): mixed { return motLire('mot_cache', $cle, $defaut); }
function motCacheEcrire(string $cle, mixed $valeur): void { motEcrire('mot_cache', $cle, $valeur); }

/** Efface des caches, par motif LIKE (« primesMoi%:2026-10 », « primesTous2026-10 »). */
function motCacheVider(string ...$motifs): void
{
    if ($motifs === []) { return; }
    motTables();
    Db::exec('DELETE FROM mot_cache WHERE ' . implode(' OR ', array_fill(0, count($motifs), 'cle LIKE ?')), $motifs);
}
