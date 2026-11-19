<?php
declare(strict_types=1);

namespace App\Core\Security;

/**
 * Mots de passe des comptes de l'ERP (table user) : stockés en bcrypt (password_hash). Les comptes historiques
 * en clair restent acceptés le temps de la migration : ils sont rechiffrés à la première connexion réussie
 * (et en bloc par Bin/hash_passwords.php).
 */
final class Passwords
{
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * [295] Mot de passe à enregistrer dans la table user. Mode cohabitation : dans le format de l'ERP d'origine, qui
     * travaille sur la même base et compare le mot de passe tel quel — les deux ERP gardent les mêmes mots de passe.
     * Le chiffrement (bcrypt) revient à la bascule (Bin/hash_passwords.php).
     */
    public static function forStorage(string $plain): string
    {
        return \App\Core\Cohabitation::on() ? $plain : self::hash($plain);
    }

    public static function isHash(string $stored): bool
    {
        return password_get_info($stored)['algoName'] !== 'unknown';
    }

    public static function matches(string $stored, string $given): bool
    {
        if ($stored === '' || $given === '') {
            return false;
        }
        return self::isHash($stored) ? password_verify($given, $stored) : hash_equals($stored, $given);
    }

    /** En clair, ou chiffré avec d'anciens paramètres : à rechiffrer. */
    public static function needsRehash(string $stored): bool
    {
        if (\App\Core\Cohabitation::on()) return false;   // [295] mêmes mots de passe que l'ERP d'origine
        return !self::isHash($stored) || password_needs_rehash($stored, PASSWORD_DEFAULT);
    }
}
