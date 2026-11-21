<?php
declare(strict_types=1);

/**
 * Les magasins : petites lectures communes à plusieurs domaines.
 *
 * magasinNom : sorti de marketing/src/mesure.php le 21/11/2026 ; il sert aux réclamations
 * (panel/src/reclamations_magasin.php), aux primes (panel/src/primes.php) et aux invendus
 * (consultant/src/invendus.php).
 */

/** Le nom d'un magasin, pour le journal. */
function magasinNom(string $id): ?string
{
    if ($id === '') { return null; }
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$id]); } catch (PDOException $e) { return null; }
    return $r === null ? null : (string) $r['name'];
}
