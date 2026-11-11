<?php
declare(strict_types=1);
/*
 * [261] « Pobierz zamówienia » : le bouton rend le bilan réel du passage (boutiques sans adresse ou clé, boutiques
 * qui ne répondent pas, commandes importées) au lieu d'un succès général. Aucun appel réseau : boutiques sans
 * configuration seulement.
 *
 *   php tests/order_collect_report_test.php
 */
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Repositories\Client\ClientRepository;
use App\Repositories\Client\Order\OrderCollectIssueRepository;
use App\Services\Client\Order\OrderCollectorService;

function expectOc(bool $c, string $m): void { if (!$c) throw new RuntimeException('FAILED: ' . $m); }

$noInit = fn(string $class) => (new ReflectionClass($class))->newInstanceWithoutConstructor();
$clients = new class extends ClientRepository {
    public array $rows = [];
    public function __construct() {}
    public function getFranchisee() { return $this->rows; }
};
// Journal des cas (Stan wysyłek) : vraie table, base de test jetable.
$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_order_collect_report_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    echo "order_collect_report_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("CREATE TABLE {$testDb}.n_order_collect_issue LIKE {$source}.n_order_collect_issue");
$pdo->exec("USE {$testDb}");
register_shutdown_function(fn() => $pdo->exec("DROP DATABASE IF EXISTS {$testDb}"));
$issues = new OrderCollectIssueRepository($pdo);
$recorded = fn() => (int) $pdo->query("SELECT COUNT(*) FROM n_order_collect_issue WHERE kind = 'config' AND closed_at IS NULL")->fetchColumn();
$svc = $noInit(OrderCollectorService::class);
foreach (['clientRepository' => $clients, 'issues' => $issues] as $prop => $value) {
    $p = new ReflectionProperty(OrderCollectorService::class, $prop);
    $p->setValue($svc, $value);
}

// 1) Quatre boutiques sans adresse de webhooks (cas du serveur de test) : rien n'est récupéré, et c'est dit.
$clients->rows = array_map(fn($i) => ['id' => $i, 'credentials' => '{}', 'franchisee_uid' => 'shop-' . $i], [1, 2, 3, 4]);
$r = $svc->runManualCollector();
expectOc($r === ['shops' => 4, 'not_configured' => 4, 'unreachable' => 0, 'answered' => 0, 'imported' => 0, 'failed' => 0], 'bilan : 4 boutiques non configurées');
expectOc($recorded() === 4, 'chaque boutique notée dans Stan wysyłek (cas « config » ouverts)');
$msg = OrderCollectorService::reportMessage($r);
expectOc(str_contains($msg, 'Sklepy : 4') && str_contains($msg, 'Bez adresu lub klucza sklepu w karcie klienta : 4') && !str_contains($msg, 'Odpowiedziały'), 'message : rien pris, raison donnée');

// 2) Une clé mal formée compte aussi comme « non configurée » ; le bilan repart de zéro à chaque passage.
$clients->rows = [['id' => 9, 'credentials' => json_encode(['webhooks_base_url' => 'https://shop.example.invalid', 'secret' => 'zz']), 'franchisee_uid' => 'shop-9']];
$r = $svc->runManualCollector();
expectOc($r['shops'] === 1 && $r['not_configured'] === 1 && $r['answered'] === 0, 'clé invalide = non configurée, compteur remis à zéro');

// 3) Message d'un passage réussi.
$ok = OrderCollectorService::reportMessage(['shops' => 4, 'not_configured' => 0, 'unreachable' => 1, 'answered' => 3, 'imported' => 5, 'failed' => 1]);
expectOc(str_contains($ok, 'Odpowiedziały : 3, nowe zamówienia : 5, odrzucone : 1') && str_contains($ok, 'Nie odpowiadają : 1'), 'message : boutiques, commandes, refus');

echo "order_collect_report_test: OK\n";
