<?php
declare(strict_types=1);

namespace App\Core;

/**
 * [295] Mode cohabitation : cette version tourne sur la base de l'ERP d'origine, qui reste en service à côté
 * (serveur local de l'atelier). COHABITATION=1 dans .env :
 *  - les migrations listées dans migrations/cohabitation_skip.txt (celles qui changent la forme, le sens ou les
 *    données des tables d'origine) ne sont pas appliquées — elles le seront à la bascule (COHABITATION=0) ;
 *  - les mots de passe de la table user restent dans le format de l'ERP d'origine (pas de rechiffrement) ;
 *  - raw_material.waste_perc garde le sens de l'ERP d'origine (« wykorzystanie », 100 = sans perte) : la perte
 *    de cette version vit dans n_raw_material_loss (App\Services\Material\MaterialLoss) ;
 *  - les envois automatiques suivent l'interrupteur system.auto_send de Ustawienia › Parametry (App\Core\AutoSend).
 */
final class Cohabitation
{
    public static function on(): bool
    {
        $v = $_ENV['COHABITATION'] ?? getenv('COHABITATION');
        return in_array(strtolower(trim((string) ($v === false ? '' : $v))), ['1', 'true', 'yes', 'on'], true);
    }
}
