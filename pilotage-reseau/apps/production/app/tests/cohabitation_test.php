<?php
declare(strict_types=1);
/*
 * [295] Mode cohabitation (COHABITATION=1 : cette version sur la base de l'ERP d'origine, qui reste en service).
 * Liste des migrations mises de côté, mots de passe laissés au format d'origine, interrupteur des envois automatiques.
 * Sans serveur MySQL (SQLite en mémoire pour l'interrupteur).
 *
 *   php tests/cohabitation_test.php
 */

require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Core\AutoSend;
use App\Core\Cohabitation;
use App\Core\Security\Passwords;
use App\Services\Workshop\Migration\MigrationRunner;

function expectCo(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

// Interrupteur du mode
putenv('COHABITATION=0');
expectCo(!Cohabitation::on(), '0 = arrêt');
foreach (['1', 'true', 'on', 'YES'] as $v) { putenv("COHABITATION={$v}"); expectCo(Cohabitation::on(), "{$v} = marche"); }

// Migrations mises de côté : celles qui touchent aux tables ou données d'origine ; les nouvelles (183–186) s'appliquent
$dir = __DIR__ . '/../migrations';
$skip = MigrationRunner::skipList($dir . '/cohabitation_skip.txt');
foreach (['021', '034', '057', '072', '074', '076', '077', '100', '115', '127', '132', '144', '158', '159'] as $n) {
    expectCo(isset($skip[$n]) && $skip[$n] !== '', "{$n} mise de côté avec sa raison");
    expectCo(count(glob("{$dir}/tfb/{$n}_*.sql")) === 1, "{$n} : le fichier existe");
}
foreach (['183', '184', '185', '186'] as $n) expectCo(!isset($skip[$n]), "{$n} s'applique en cohabitation");
expectCo(MigrationRunner::skipList($dir . '/absent.txt') === [], 'liste absente = rien de côté');

// Mots de passe : l'ERP d'origine compare la valeur telle quelle → pas de chiffrement, pas de rechiffrement
putenv('COHABITATION=1');
expectCo(Passwords::forStorage('Secret-test-1') === 'Secret-test-1', 'cohabitation : enregistré tel quel');
expectCo(!Passwords::needsRehash('Secret-test-1'), 'cohabitation : pas de rechiffrement à la connexion');
expectCo(Passwords::matches('Secret-test-1', 'Secret-test-1') && !Passwords::matches('Secret-test-1', 'autre'), 'cohabitation : connexion avec la valeur d\'origine');
putenv('COHABITATION=0');
expectCo(Passwords::isHash(Passwords::forStorage('Secret-test-1')), 'hors cohabitation : chiffré');

// Envois automatiques : n_prod_param system.auto_send
$db = new PDO('sqlite::memory:');
expectCo(AutoSend::enabled($db), 'table absente : envois actifs (comme avant)');
$db->exec('CREATE TABLE n_prod_param (code TEXT PRIMARY KEY, value TEXT)');
expectCo(AutoSend::enabled($db), 'paramètre absent : envois actifs');
$db->exec("INSERT INTO n_prod_param VALUES ('system.auto_send', '0')");
expectCo(!AutoSend::enabled($db), '0 : envois coupés');
$db->exec("UPDATE n_prod_param SET value = '1'");
expectCo(AutoSend::enabled($db), '1 : envois actifs');

echo "cohabitation_test: OK\n";
