<?php
declare(strict_types=1);
/*
 * Tablet grupy, « Reklamacje klientów » (28/10/2026, tylko do wiadomości) :
 *  - tylko reklamacje przyjęte przez biuro (ACCEPTED), typ PRODUCT, na produkty grupy tabletu (grupa główna karty produktu,
 *    inaczej pierwsza aktywna grupa działu) ;
 *  - ekran startowy : 30 dni, w tym 7 dni, sztuki (przyjęta ilość, inaczej zgłoszona), trzy ostatnie ; 12 miesięcy dla zakładki ;
 *  - lista : okresy 30 / 90 / 365 dni (inny = 30), najczęstsze produkty, powody ;
 *  - tłumaczenie komentarza i odpowiedzi na język tabletu (DeepL — transport testowy, nic nie wychodzi) : raz na reklamację
 *    i język, ponownie po zmianie tekstu, bez tłumaczenia w tym samym języku, bez klucza i przy błędzie — oryginał ;
 *  - zdjęcia na żądanie (system franczyzy zastąpiony atrapą) : tylko linki https, błąd → brak linku, reklamacja innej
 *    grupy albo nieprzyjęta → 404 ; tablet bez grupy → 409.
 *
 *   php tests/kiosk_complaints_test.php
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide) ;
 * structures recopiées depuis TFB_TEST_SOURCE_DB (défaut erp). La base erp_kc_test est recréée puis supprimée.
 */
require_once __DIR__ . '/../public/vendor/autoload.php';
require_once __DIR__ . '/../App/Core/functions.php';

use App\Core\Api\Workshop\ApiContext;
use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Repositories\Workshop\ComplaintBoardRepository;
use App\Services\Client\Complaint\ComplaintCollectorService;
use App\Services\Translation\DeepLTranslator;
use App\Services\Workshop\Execution\KioskComplaintService;

function expectKc(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function expectKcError(callable $fn, string $code, string $message): void
{
    try { $fn(); } catch (ApiException $e) {
        expectKc($e->errorCode() === $code, $message . ' (got ' . $e->errorCode() . ')');
        return;
    }
    throw new RuntimeException('FAILED: ' . $message . ' (no error)');
}

/** System franczyzy : atrapa (żadnego połączenia), linki według numeru zdjęcia. */
final class FakeComplaintCollector extends ComplaintCollectorService
{
    public array $asked = [];
    public function __construct(private array $replies)
    {
    }
    public function getAttachmentPresigned($id)
    {
        $this->asked[] = (int) $id;
        $r = $this->replies[(int) $id] ?? null;
        if ($r instanceof Throwable) throw $r;
        return $r;
    }
}

$dsn = getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$source = getenv('TFB_TEST_SOURCE_DB') ?: 'erp';
$testDb = 'erp_kc_test';
try {
    $pdo = new PDO($dsn . ';charset=utf8mb4', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "kiosk_complaints_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
if (!$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($source) . " AND table_name = 'n_complaint_translation'")->fetchColumn()) {
    echo "kiosk_complaints_test: SKIPPED (run php Bin/tfb_migrate.php apply)\n";
    exit(0);
}

$envKey = getenv('DEEPL_API_KEY');
$setKey = function (?string $key): void {
    if ($key === null) { putenv('DEEPL_API_KEY'); unset($_ENV['DEEPL_API_KEY']); }
    else { putenv('DEEPL_API_KEY=' . $key); $_ENV['DEEPL_API_KEY'] = $key; }
};
// DeepL : transport testowy — « [PL] tekst », język źródłowy według tekstu ; status do symulacji błędu.
$calls = [];
$status = 200;
DeepLTranslator::$transport = function (string $method, string $url, array $headers, ?array $body) use (&$calls, &$status) {
    $calls[] = $body;
    if ($status !== 200) return [$status, '{}'];
    $target = substr((string) $body['target_lang'], 0, 2);
    return [200, json_encode(['translations' => array_map(fn($t) => [
        'text' => '[' . $target . '] ' . $t,
        'detected_source_language' => preg_match('/Baguette|Nous|trop|croissant/i', $t) ? 'FR' : 'PL',
    ], $body['text'])])];
};

$pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
$pdo->exec("CREATE DATABASE {$testDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
try {
    foreach (['complaint', 'complaint_attachment', 'final_product', 'customer', 'n_prod_item', 'n_prod_product_settings', 'n_prod_group',
                 'n_complaint_translation', 'n_translation_log', 'n_app_secret'] as $table) {
        $pdo->exec("CREATE TABLE {$testDb}.{$table} LIKE {$source}.{$table}");
    }
    $pdo->exec("USE {$testDb}");
    $pdo->exec("SET SESSION sql_mode = ''");
    // Grupy : Piekarnia (dział 1, pierwsza aktywna), Rogal (dział 2), stara nieaktywna w dziale 1.
    $pdo->exec("INSERT INTO n_prod_group (id, code, name_pl, name_fr, active, id_section) VALUES
        (1, 'PIE', 'Piekarnia', 'Boulangerie', 1, 1), (2, 'ROG', 'Rogal', 'Croissanterie', 1, 2), (3, 'OLD', 'Stara', 'Ancienne', 0, NULL)");
    // Bagietka i Chleb : dział 1 → Piekarnia ; Croissant : dział 1, ale grupa główna Rogal.
    $pdo->exec("INSERT INTO final_product (id, name, id_section) VALUES (10, 'Bagietka', 1), (11, 'Croissant', 1), (12, 'Chleb', 1)");
    $pdo->exec("INSERT INTO n_prod_item (id, item_type, id_final_product) VALUES (100, 'product', 10), (101, 'product', 11), (102, 'product', 12)");
    $pdo->exec("INSERT INTO n_prod_product_settings (id_item, id_primary_group) VALUES (101, 2), (102, NULL)");
    $pdo->exec("INSERT INTO customer (id, company) VALUES (5, 'Sklep Bruksela'), (6, 'Sklep Liège')");
    $day = fn(int $ago) => date('Y-m-d H:i:s', strtotime("-{$ago} days 10:00"));
    $ins = $pdo->prepare("INSERT INTO complaint (id, complaint_key, complaint_type, complaint_reason, requested_action, compensation_method, id_customer, id_product,
            product_quantity, accepted_quantity, client_comment, production_response, acceptance_status, acceptance_timestamp, reported_at)
        VALUES (?, UUID(), ?, ?, 'REFUND', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ([
        [1, 'PRODUCT', 'product_quality', 'REPLACEMENT', 5, 10, 5, 3, 'Baguette trop cuite, noire en dessous', 'Nous avons remplacé les baguettes', 'ACCEPTED', $day(2), $day(3)],
        [2, 'PRODUCT', 'size_or_weight_issue', 'CREDIT', 6, 12, 4, 0, 'Chleb za mało wypieczony', null, 'ACCEPTED', $day(20), $day(20)],
        [3, 'PRODUCT', 'broken_packaging', 'REFUND', 5, 10, 2, 2, null, null, 'ACCEPTED', $day(60), null],
        [4, 'PRODUCT', 'product_quality', null, 5, 10, 9, 0, 'Nowa, jeszcze bez decyzji', null, 'NEW', $day(1), $day(1)],
        [5, 'PRODUCT', 'product_quality', null, 5, 10, 9, 0, 'Odrzucona', null, 'REJECTED', $day(1), $day(1)],
        [6, 'NON_PRODUCT', 'other', 'CREDIT', 5, 10, 1, 1, 'Kierowca spóźniony', null, 'ACCEPTED', $day(1), $day(1)],
        [7, 'PRODUCT', 'lack_of_conformity', 'REFUND', 6, 11, 6, 6, 'Le croissant est plat', null, 'ACCEPTED', $day(4), $day(4)],
        [8, 'PRODUCT', 'other', 'REFUND', 5, 10, 1, 1, null, null, 'ACCEPTED', $day(400), $day(400)],
    ] as $r) {
        $ins->execute($r);
    }
    $pdo->exec("INSERT INTO complaint_attachment (id, complaint_id, client_attachment_id, object_key) VALUES (31, 1, 1, 'a/1.jpg'), (32, 1, 2, 'a/2.jpg'), (33, 1, 3, 'a/3.jpg'), (34, 7, 4, 'a/4.jpg')");

    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([PDO::class => $pdo]);
    $container = $builder->build();
    $collector = new FakeComplaintCollector([
        31 => ['ok' => true, 'data' => ['url' => 'https://files.example.invalid/1.jpg?sig=x']],
        32 => ['ok' => true, 'data' => ['url' => 'http://files.example.invalid/2.jpg']],
        33 => new RuntimeException('franchise down'),
    ]);
    $service = function () use ($container, $pdo, $collector): KioskComplaintService {
        return new KioskComplaintService(new ComplaintBoardRepository($pdo), $container->make(DeepLTranslator::class), $collector);
    };
    $tablet = fn(string $lang, ?int $group) => new ApiContext('kc', new Translator($lang), null, [], [], [], 9, null, $group);

    // --- Bez klucza DeepL : oryginały, flaga translator false
    $setKey(null);
    $kc = $service();
    $sum = $kc->summary($tablet('pl', 1));
    expectKc($sum['count'] === 2 && $sum['week'] === 1 && abs($sum['pieces'] - 7.0) < 1e-9 && $sum['year'] === 3 && $sum['days'] === 30,
        'summary: accepted PRODUCT complaints of the group in 30 days (7 days, pieces accepted else reported), 12 months for the tab');
    expectKc(array_column($sum['latest'], 'id') === [1, 2] && $sum['latest'][0]['product'] === 'Bagietka' && $sum['latest'][0]['reason'] === 'product_quality'
        && $sum['latest'][0]['quantity'] === 3.0, 'summary: latest first');
    $list = $kc->list(30, $tablet('pl', 1));
    expectKc(array_column($list['items'], 'id') === [1, 2] && $list['group']['name'] === 'Piekarnia' && !$list['translator'] && $calls === [],
        'list: same complaints, no translator call without a key');
    $first = $list['items'][0];
    expectKc($first['comment'] === 'Baguette trop cuite, noire en dessous' && $first['comment_tr'] === null && $first['response'] === 'Nous avons remplacé les baguettes'
        && $first['new'] && $first['photos'] === 3 && $first['customer'] === 'Sklep Bruksela' && $first['compensation'] === 'REPLACEMENT'
        && $first['reported_at'] === substr($day(3), 0, 10) && $first['product'] === ['id' => 10, 'name' => 'Bagietka'], 'item: original texts, photos count, dates, product');
    expectKc(!$list['items'][1]['new'] && $list['items'][1]['quantity'] === 4.0 && $list['items'][1]['response'] === null, 'older complaint not new, reported quantity when none accepted');
    expectKc($list['totals'] === ['count' => 2, 'week' => 1, 'pieces' => 7.0] && $list['periods'] === [30, 90, 365], 'totals and periods');
    expectKc(array_column($list['products'], 'id') === [12, 10] && $list['products'][0]['pieces'] === 4.0, 'top products: same count → more pieces first');
    expectKc(count($list['reasons']) === 2, 'reasons counted');
    expectKc(array_column($kc->list(90, $tablet('pl', 1))['items'], 'id') === [1, 2, 3], '90 days');
    expectKc(array_column($kc->list(365, $tablet('pl', 1))['items'], 'id') === [1, 2, 3], '365 days : older than a year left out');
    expectKc($kc->list(7, $tablet('pl', 1))['days'] === 30, 'unknown period → 30 days');
    expectKc(array_column($kc->list(30, $tablet('pl', 2))['items'], 'id') === [7] && $kc->summary($tablet('pl', 2))['count'] === 1,
        'Rogal: primary group of the product wins over the section group');
    expectKc($kc->list(30, $tablet('fr', 2))['group']['name'] === 'Croissanterie', 'group name in the tablet language');
    expectKc((int) $pdo->query('SELECT COUNT(*) FROM n_complaint_translation')->fetchColumn() === 0, 'nothing stored without a key');

    // --- Z kluczem : tłumaczenie raz, z pamięci, ponownie po zmianie tekstu
    $setKey('test0000-0000-0000-0000-000000000000:fx');
    $kc = $service();
    $pl = $kc->list(30, $tablet('pl', 1));
    expectKc(count($calls) === 1 && $calls[0]['target_lang'] === 'PL' && count($calls[0]['text']) === 3, 'one DeepL call : non-empty comments and responses');
    expectKc($pl['translator'] && $pl['items'][0]['comment_tr'] === '[PL] Baguette trop cuite, noire en dessous' && $pl['items'][0]['response_tr'] === '[PL] Nous avons remplacé les baguettes'
        && $pl['items'][0]['source_lang'] === 'fr', 'French complaint translated into Polish');
    expectKc($pl['items'][1]['comment_tr'] === null && $pl['items'][1]['comment'] === 'Chleb za mało wypieczony', 'Polish text on a Polish tablet : no translation');
    expectKc((int) $pdo->query("SELECT COUNT(*) FROM n_complaint_translation WHERE lang = 'pl'")->fetchColumn() === 2
        && (int) $pdo->query("SELECT COUNT(*) FROM n_translation_log WHERE context = 'complaint'")->fetchColumn() === 1, 'translations stored, usage logged as complaint');
    $again = $service()->list(30, $tablet('pl', 1));
    expectKc(count($calls) === 1 && $again['items'][0]['comment_tr'] === $pl['items'][0]['comment_tr'], 'second view : from memory, no DeepL call');
    $pdo->exec("UPDATE complaint SET client_comment = 'Baguette trop cuite et dure' WHERE id = 1");
    $changed = $service()->list(30, $tablet('pl', 1));
    expectKc(count($calls) === 2 && count($calls[1]['text']) === 2 && $changed['items'][0]['comment_tr'] === '[PL] Baguette trop cuite et dure', 'text changed : translated again (only that complaint)');
    $fr = $service()->list(30, $tablet('fr', 1));
    expectKc(count($calls) === 3 && $calls[2]['target_lang'] === 'FR' && $fr['items'][0]['comment_tr'] === null && $fr['items'][1]['comment_tr'] === '[FR] Chleb za mało wypieczony',
        'French tablet : Polish text translated, French one left as is');
    expectKc(!array_key_exists('comment_tr', $service()->summary($tablet('pl', 1))['latest'][0]) && count($calls) === 3, 'home screen summary : never translates');

    // --- Błąd DeepL : oryginał, nic nie zapisane
    $status = 456;
    $pdo->exec("UPDATE complaint SET production_response = 'Nous avons remboursé' WHERE id = 1");
    $broken = $service()->list(30, $tablet('pl', 1));
    expectKc(count($calls) === 4 && $broken['items'][0]['response'] === 'Nous avons remboursé' && $broken['items'][0]['response_tr'] === null,
        'DeepL error : originals shown');
    $status = 200;

    // --- Zdjęcia : tylko https, błąd → brak linku ; inna grupa, nieprzyjęta → 404 ; bez grupy → 409
    expectKc($collector->asked === [], 'franchise system not called by lists');
    $photos = $kc->photos(1, $tablet('pl', 1));
    expectKc($photos['photos'] === [['id' => 31, 'url' => 'https://files.example.invalid/1.jpg?sig=x'], ['id' => 32, 'url' => null], ['id' => 33, 'url' => null]]
        && $collector->asked === [31, 32, 33], 'photos : https only, failure → no link');
    expectKcError(fn() => $kc->photos(7, $tablet('pl', 1)), 'NOT_FOUND', 'complaint of another group');
    expectKcError(fn() => $kc->photos(4, $tablet('pl', 1)), 'NOT_FOUND', 'complaint not accepted');
    expectKcError(fn() => $kc->photos(6, $tablet('pl', 1)), 'NOT_FOUND', 'non-product complaint');
    expectKc(count($collector->asked) === 3, 'franchise system not called for refused complaints');
    expectKcError(fn() => $kc->summary($tablet('pl', null)), 'DEVICE_WITHOUT_GROUP', 'tablet without group');
    expectKcError(fn() => $kc->list(30, $tablet('pl', null)), 'DEVICE_WITHOUT_GROUP', 'tablet without group (list)');

    echo "kiosk_complaints_test: OK\n";
} finally {
    DeepLTranslator::$transport = null;
    $setKey($envKey === false ? null : $envKey);
    $pdo->exec("DROP DATABASE IF EXISTS {$testDb}");
}
