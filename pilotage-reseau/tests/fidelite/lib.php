<?php
declare(strict_types=1);
// Banc d'essai de src/fidelite_profils.php : vraie base MariaDB locale, faux panel aux rythmes connus.
$SRC = __DIR__ . '/../../src';
require $SRC . '/Db.php';
require $SRC . '/writes.php';

function setting(string $key, mixed $default = null): mixed { $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$key]); return $r === null ? $default : json_decode($r['value'], true); }
function analyseListe($r): array { if (!is_array($r)) { return []; } if (array_is_list($r)) { return $r; } foreach (['data', 'items'] as $k) { if (isset($r[$k]) && is_array($r[$k]) && array_is_list($r[$k])) { return $r[$k]; } } return []; }
function fidTexte(mixed $v, int $max, string $defaut = ''): string { $t = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? ''))); return $t === '' ? $defaut : mb_substr($t, 0, $max); }
function fidEntier(mixed $v, int $min, int $max, int $defaut): int { return is_numeric($v) ? max($min, min($max, (int) round((float) $v))) : $defaut; }
function fidMagasins(): array { return [['id' => '4', 'nom' => 'Halle'], ['id' => '2', 'nom' => 'Corbais']]; }

/** Le faux panel : client → tous les combien de jours il passe (0 = une seule fois, il y a N jours). */
final class PanelApi
{
    public static int $appels = 0;
    public const RYTHMES = [1 => 2, 2 => 4, 3 => 3.5, 4 => 12, 5 => 30, 6 => [60], 7 => [10], 8 => [100], 9 => 7, 10 => [200]];
    public static function configured(): bool { return true; }
    public static function liste(array $d): array { return array_is_list($d) ? $d : ($d['data'] ?? []); }
    public static function sondeGet(string $p, int $t = 12): array
    {
        if (str_contains($p, 'product-categories')) { return ['code' => 200, 'corps' => [['id' => 1, 'name' => 'Pain'], ['id' => 2, 'name' => 'Viennoiserie Ind.'], ['id' => 3, 'name' => 'Tartes']]]; }
        return ['code' => 200, 'corps' => [['id' => 100, 'name' => 'Pain Doré', 'id_category' => 1], ['id' => 200, 'name' => 'Croissant', 'id_category' => 2], ['id' => 300, 'name' => 'Tarte Fraises', 'id_category' => 3]]];
    }
    /** Les tickets d'un jour : un client passe si l'écart au jour d'aujourd'hui est un multiple de son rythme. */
    public static function jour(int $shop, string $d): array
    {
        $ago = (int) round((strtotime(date('Y-m-d')) - strtotime($d)) / 86400);
        $L = [['id' => crc32($shop . $d . 'anon'), 'id_client' => null, 'is_client_b2b' => 0, 'insert_timestamp' => $d . ' 09:00:00', 'total_gross_amount_after_discount' => 3.2]];
        if ($shop !== 4) { return $L; }
        foreach (self::RYTHMES as $c => $r) {
            $passe = is_array($r) ? $ago === $r[0] : fmod($ago, $r) < 1;
            if ($passe) {
                $L[] = ['id' => $c * 100000 + $ago, 'id_client' => $c, 'is_client_b2b' => 0, 'insert_timestamp' => $d . sprintf(' %02d:15:00', 8 + $c % 10),
                    'total_gross_amount_after_discount' => 5.8 + $c, 'source' => $c % 3 === 0 ? 'webshop' : 'pos', 'order_ref' => $c === 1 ? 77 : null, 'client_name' => 'X'];
            }
        }
        $L[] = ['id' => 999000 + $ago, 'id_client' => 55, 'is_client_b2b' => 1, 'insert_timestamp' => $d . ' 10:00:00', 'total_gross_amount_after_discount' => 80];
        return $L;
    }
    public static function getParalleleDetail(array $paths, int $f = 4, int $t = 25, bool $r = true): array
    {
        $o = [];
        foreach ($paths as $k => $p) {
            self::$appels++;
            if (preg_match('#^/shops/(\d+)/transactions\?date=(\S+)$#', $p, $m)) { $o[$k] = ['code' => 200, 'data' => ['data' => self::jour((int) $m[1], $m[2])]]; }
            elseif (preg_match('#^/transactions/(\d+)#', $p, $m)) {
                $c = intdiv((int) $m[1], 100000);
                $o[$k] = ['code' => 200, 'data' => ['data' => ['id' => (int) $m[1], 'products' => [['id_product' => 100, 'product_name' => 'Pain Doré', 'quantity' => 1], ['id_product' => $c % 2 ? 200 : 300, 'product_name' => $c % 2 ? 'Croissant' : 'Tarte Fraises', 'quantity' => 2]]]]];
            } elseif (preg_match('#^/shops/\d+/clients/(\d+)$#', $p, $m)) { $o[$k] = ['code' => 200, 'data' => ['client' => ['name' => 'Prénom' . $m[1], 'surname' => 'Nom' . $m[1]]]]; }
            else { $o[$k] = ['code' => 404, 'data' => null]; }
        }
        return $o;
    }
}

Db::exec('CREATE TABLE IF NOT EXISTS ceo_app_setting (`key` VARCHAR(120) PRIMARY KEY, value MEDIUMTEXT) DEFAULT CHARSET=utf8mb4');
Db::exec('CREATE TABLE IF NOT EXISTS ceo_journal_entry (id INT AUTO_INCREMENT PRIMARY KEY, happened_at DATETIME, actor VARCHAR(60), kind VARCHAR(60), project VARCHAR(120), message TEXT) DEFAULT CHARSET=utf8mb4');
require $SRC . '/fidelite_profils.php';
