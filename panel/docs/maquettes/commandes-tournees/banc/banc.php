<?php
declare(strict_types=1);
/* Banc d'essai du module Commandes (24/11/2026) : le vrai module (pcoRoute), une vraie base MariaDB locale, et les
 * commandes du panel réel (BANC_PANEL : l'adresse de l'API, avec un jeton qu'un proxy ajoute) ou simulées
 * (BANC_SIM=sim.json, tiré de la maquette par sim.js). Voir ../LISEZMOI.md, « Régénérer ».
 *   COCKPIT_DB_HOST=127.0.0.1 COCKPIT_DB_NAME=… COCKPIT_DB_USER=… COCKPIT_DB_PASSWORD=… BANC_SIM=sim.json \
 *     php -d date.timezone=America/Denver -S 127.0.0.1:8097 banc.php */
define('RACINE', dirname(__DIR__, 5));
require RACINE . '/shared/src/Db.php';
Db::exec('CREATE TABLE IF NOT EXISTS ceo_app_setting (`key` VARCHAR(190) PRIMARY KEY, value MEDIUMTEXT)');
Db::exec('CREATE TABLE IF NOT EXISTS ceo_journal_entry (id INT AUTO_INCREMENT PRIMARY KEY, happened_at DATETIME, actor VARCHAR(120), kind VARCHAR(60), project VARCHAR(120), message TEXT)');
function setting(string $k, mixed $d = null): mixed { $r = Db::row('SELECT value FROM ceo_app_setting WHERE `key` = ?', [$k]); if (!$r) { return $d; } $v = json_decode((string) $r['value'], true); return $v ?? $d; }
function analyseListe($r): array { if (!is_array($r)) { return []; } if (array_is_list($r)) { return $r; } foreach (['data', 'items'] as $k) { if (isset($r[$k]) && is_array($r[$k])) { return $r[$k]; } } return []; }
function body(): array { static $b = null; if ($b === null) { $b = json_decode(file_get_contents('php://input') ?: '[]', true) ?: []; } return $b; }
function journalAdd(string $a, string $k, ?string $p, string $m): void { Db::exec('INSERT INTO ceo_journal_entry (happened_at, actor, kind, project, message) VALUES (NOW(),?,?,?,?)', [$a, $k, $p ?? '—', $m]); }
final class PanelApi
{
    public static function configured(): bool { return true; }
    public static function sondeGet(string $path, int $t = 12): array
    {
        $sim = getenv('BANC_SIM');
        if ($sim) {
            $S = json_decode((string) file_get_contents($sim), true);
            if (str_contains($path, '/client-orders')) { return ['code' => 200, 'corps' => $S['commandes']]; }
            if (str_contains($path, '/clients')) { return ['code' => 200, 'corps' => $S['clients']]; }
            return ['code' => 404, 'corps' => null];
        }
        $ch = curl_init((getenv('BANC_PANEL') ?: 'https://atelierby.tfbuddy.com/api/v1') . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $t]);
        $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'corps' => json_decode((string) $res, true)];
    }
}
require RACINE . '/panel/src/commandes_tournees.php';
header('Content-Type: application/json; charset=utf-8');
$u = parse_url($_SERVER['REQUEST_URI']);
$path = preg_replace('#^.*/api/cockpit#', '', $u['path']);
parse_str($u['query'] ?? '', $_GET);
try { $r = pcoRoute($_SERVER['REQUEST_METHOD'], $path); }
catch (Throwable $e) { http_response_code(500); $r = ['error' => get_class($e) . ' : ' . $e->getMessage()]; }
echo json_encode($r ?? ['error' => 'route inconnue'], JSON_UNESCAPED_UNICODE);
