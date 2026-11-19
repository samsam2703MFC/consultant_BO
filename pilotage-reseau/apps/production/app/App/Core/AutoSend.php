<?php
declare(strict_types=1);

namespace App\Core;

/**
 * [295] Interrupteur des envois automatiques (n_prod_param « system.auto_send », Ustawienia › Parametry). Lu par les
 * tâches cron (Bin/mail_dispatch.php, Bin/poller.php, e-mails HR…) : à 0, rien ne part tout seul. Paramètre absent
 * (base avant la migration 185) : envois actifs, comme avant.
 */
final class AutoSend
{
    public const CODE = 'system.auto_send';

    public static function enabled(?\PDO $db = null): bool
    {
        try {
            $db ??= (new Database())->getConnection();
            $st = $db->prepare('SELECT value FROM n_prod_param WHERE code = ?');
            $st->execute([self::CODE]);
            $v = $st->fetchColumn();
            return $v === false || trim((string) $v) !== '0';
        } catch (\Throwable) {
            return true;
        }
    }

    /** Pour les scripts cron : true (et une ligne au journal) quand l'interrupteur est coupé. */
    public static function skip(string $script): bool
    {
        if (self::enabled()) return false;
        echo json_encode(['script' => $script, 'skipped' => 'auto_send = 0 (Ustawienia › Parametry)'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return true;
    }
}
