<?php
/**
 * [372] SMS de commande aux clients : brouillon dans la langue du client (relance, confirmation, modification), envoi par
 * la passerelle avec client / contexte / référence pour l'historique, refus clairs (texte, « … » à compléter, pas de
 * téléphone, type inconnu, échec de la passerelle), dernier SMS par ligne, routes et garde d'accès.
 *
 * Base : TFB_TEST_DSN (défaut mysql:unix_socket=/run/mysqld/mysqld.sock), TFB_TEST_USER (root), TFB_TEST_PASS (vide).
 * Base erp_order_sms_test recréée puis supprimée. Passerelle factice : aucun SMS réel.
 */
declare(strict_types=1);
require_once __DIR__ . '/../public/vendor/autoload.php';

use App\Exceptions\CustomException;
use App\Repositories\Client\Order\OrderSmsRepository;
use App\Services\Client\Order\OrderSmsService;
use App\Services\Logistics\Sms\SmsTransport;

$pdo = new PDO(getenv('TFB_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock', getenv('TFB_TEST_USER') ?: 'root', getenv('TFB_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS erp_order_sms_test'); $pdo->exec('CREATE DATABASE erp_order_sms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $pdo->exec('USE erp_order_sms_test');
$pdo->exec("CREATE TABLE customer (id INT PRIMARY KEY, company VARCHAR(80), phone_head VARCHAR(40) NULL, phone_alt VARCHAR(40) NULL, communication_lang VARCHAR(5) NULL)");
$pdo->exec("CREATE TABLE customer_order (id INT PRIMARY KEY, id_customer INT, reference VARCHAR(60) NULL, expected_shipping_date DATE NULL)");
$pdo->exec('CREATE TABLE customer_order_product (id INT AUTO_INCREMENT PRIMARY KEY, id_order INT, quantity_carton DECIMAL(10,2))');
$pdo->exec('CREATE TABLE erp_company_data (id INT PRIMARY KEY, proforma_company_name VARCHAR(120))');
$pdo->exec("CREATE TABLE n_sms_log (id INT AUTO_INCREMENT PRIMARY KEY, id_customer INT NULL, context VARCHAR(30) NOT NULL, ref_id INT NULL, recipient VARCHAR(40) NOT NULL,
    body VARCHAR(1000) NOT NULL, status VARCHAR(16) NOT NULL, provider VARCHAR(40) NULL, provider_id VARCHAR(64) NULL, error VARCHAR(255) NULL, created_by VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO erp_company_data VALUES (1, 'Atelier Test')");
$pdo->exec("INSERT INTO customer VALUES (1, 'Sklep A', '0476 12 34 56', NULL, 'fr'), (2, 'Sklep B', '', '+48 600 300 400', 'pl'), (3, 'Sklep C', NULL, NULL, 'de')");
$pdo->exec("INSERT INTO customer_order VALUES (7, 1, 'PO-4411', '2026-11-27'), (8, 2, NULL, '2026-11-28'), (9, 3, '', NULL)");
$pdo->exec('INSERT INTO customer_order_product (id_order, quantity_carton) VALUES (7, 10), (7, 2.5), (8, 4)');

$fake = new class implements SmsTransport {
    public array $sent = [];
    public bool $deliver = false;
    public string $status = 'logged';
    public function name(): string { return 'fake'; }
    public function delivers(): bool { return $this->deliver; }
    public function send(string $to, string $text, array $context = []): array
    {
        $this->sent[] = ['to' => $to, 'text' => $text] + $context;
        return ['status' => $this->status, 'provider_id' => null, 'error' => $this->status === 'failed' ? 'odrzucony' : null];
    }
};
$svc = new OrderSmsService(new OrderSmsRepository($pdo), $fake);

$fail = 0;
$check = function (bool $ok, string $label) use (&$fail) { echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; };
$err = function (callable $f): string { try { $f(); return 'none'; } catch (CustomException $e) { return 'custom:' . $e->getCode() . ':' . $e->getMessage(); } };

// --- Brouillons
$d = $svc->draft('order_confirm', 7);
$check($d['language'] === 'fr' && str_contains($d['text'], 'Atelier Test') && str_contains($d['text'], 'PO-4411 (nr 7)') && str_contains($d['text'], '27.11.2026')
    && str_contains($d['text'], '12,5 cartons'), 'confirmation fr : société, référence client + n°, date, cartons (12,5)');
$check($d['phone'] === '0476 12 34 56' && $d['customer'] === 'Sklep A' && $d['delivers'] === false && $d['last'] === null, 'brouillon : téléphone, client, passerelle, pas de SMS précédent');
$d = $svc->draft('order_confirm', 8);
$check($d['language'] === 'pl' && str_contains($d['text'], 'zamówienie nr 8 przyjęte') && str_contains($d['text'], '4 kart.') && $d['phone'] === '+48 600 300 400', 'confirmation pl, second téléphone si le premier est vide');
$d = $svc->draft('order_changed', 9);
$check($d['language'] === 'fr' && str_contains($d['text'], 'nr 9') && str_contains($d['text'], '…') && str_contains($d['text'], 'le —'), 'langue inconnue (de) → fr ; modification sans détail : « … » à compléter');
$d = $svc->draft('order_changed', 7, ['detail' => 'Tarte remplacée par Croissant']);
$check(str_contains($d['text'], 'modifiée : Tarte remplacée par Croissant — expédition'), 'modification : détail repris');
$d = $svc->draft('order_changed', 7, ['date' => '2026-12-02']);
$check(str_contains($d['text'], "modifiée : nouvelle date d'expédition — expédition le 02.12.2026"), 'nouvelle date : mention dans la langue du client et date remplacée');
$check(str_contains($svc->draft('order_changed', 8, ['date' => '2026-12-02'])['text'], 'zmienione : nowy termin wysyłki — wysyłka 02.12.2026'), 'nouvelle date : polonais');
$check(str_contains($svc->draft('order_changed', 7, ['date' => 'x'])['text'], '27.11.2026'), 'nouvelle date invalide : date de la commande');
$d = $svc->draft('order_missing', 1, ['expected' => '2026-11-23']);
$check(str_contains($d['text'], 'habituellement le 23.11') && $d['kind_label'] === OrderSmsService::KINDS['order_missing'], 'relance : date attendue');
$check(str_contains($svc->draft('order_missing', 2, ['expected' => 'x'])['text'], 'zwykle —'), 'relance pl : date invalide ignorée');
$check($err(fn() => $svc->draft('promo', 7)) === 'custom:422:Nieznany rodzaj SMS.', 'type inconnu refusé');
$check(str_starts_with($err(fn() => $svc->draft('order_confirm', 999)), 'custom:404'), 'commande inconnue : 404');
$check(str_starts_with($err(fn() => $svc->draft('order_missing', 999)), 'custom:404'), 'client inconnu : 404');

// --- Envoi
$check(str_contains($err(fn() => $svc->send('order_confirm', 7, 'krótki', 1)), 'co najmniej 10'), 'texte trop court refusé');
$check(str_contains($err(fn() => $svc->send('order_confirm', 7, str_repeat('x', 481), 1)), 'najwyżej 480'), 'texte trop long refusé');
$check(str_contains($err(fn() => $svc->send('order_changed', 7, 'Commande modifiée : … Merci.', 1)), 'uzupełnij'), 'modification avec « … » refusée');
$check(str_contains($err(fn() => $svc->send('order_changed', 9, 'Commande 9 modifiée : date. Merci.', 1)), 'Brak numeru telefonu'), 'client sans téléphone refusé');
$check(!$fake->sent, 'aucun envoi après les refus');
$r = $svc->send('order_confirm', 7, "  Commande PO-4411   bien reçue.\r\nMerci.  ", 5);
$s = $fake->sent[0] ?? [];
$check($r['status'] === 'logged' && $r['customer'] === 1 && str_contains($r['message'], 'zapisany w historii') && $s['text'] === "Commande PO-4411 bien reçue.\nMerci.",
    'envoi : texte nettoyé, passerelle sans envoi réel → « zapisany w historii »');
$check(($s['context'] ?? '') === 'order_confirm' && ($s['customer'] ?? 0) === 1 && ($s['ref'] ?? 0) === 7 && ($s['author'] ?? '') === 'order_sms:5' && $s['to'] === '0476 12 34 56',
    'envoi : contexte, client, référence commande, auteur, téléphone (historique du client)');
$fake->deliver = true; $fake->status = 'sent';
$r = $svc->send('order_missing', 2, 'Nie otrzymaliśmy jeszcze zamówienia. Dziękujemy.', 5);
$check($r['status'] === 'sent' && str_ends_with($r['message'], 'wysłany.') && ($fake->sent[1]['ref'] ?? 0) === 2 && ($fake->sent[1]['context'] ?? '') === 'order_missing', 'relance : référence = client, message « wysłany »');
$fake->status = 'failed';
$check(str_contains($err(fn() => $svc->send('order_confirm', 8, 'Zamówienie przyjęte. Dziękujemy.', 5)), 'SMS nie wysłany : odrzucony'), 'échec de la passerelle : message clair');

// --- Dernier SMS par ligne
$pdo->exec("INSERT INTO n_sms_log (id_customer, context, ref_id, recipient, body, status, created_at) VALUES
    (1, 'order_confirm', 7, '+32476123456', 'a', 'logged', NOW() - INTERVAL 2 DAY), (1, 'order_changed', 7, '+32476123456', 'b', 'sent', NOW() - INTERVAL 1 HOUR),
    (2, 'order_missing', 2, '+48600300400', 'c', 'sent', NOW() - INTERVAL 40 DAY), (2, 'payment_reminder', 8, '+48600300400', 'd', 'sent', NOW())");
$l = $svc->lastFor(['order_confirm', 'order_changed'], [7, 8], date('Y-m-d 00:00:00', strtotime('-14 days')));
$check(isset($l[7]) && $l[7]['kind'] === 'order_changed' && $l[7]['status'] === 'sent' && !isset($l[8]), 'dernier SMS : le plus récent, autres contextes ignorés');
$check($svc->lastFor(['order_missing'], [2], date('Y-m-d 00:00:00', strtotime('-14 days'))) === [], 'dernier SMS : trop ancien ignoré');
$check($svc->draft('order_confirm', 7)['last']['status'] === 'logged', 'brouillon : dernier SMS du même type');

// --- Routes et garde
$routes = (string) file_get_contents(__DIR__ . '/../App/Core/Routes/Client/OrderSmsRoutes.php');
$check(str_contains($routes, "'GET', '/ajax/client-orders/sms/draft'") && str_contains($routes, "'POST', '/ajax/client-orders/sms/send'"), 'routes brouillon et envoi');
$ctrl = (string) file_get_contents(__DIR__ . '/../App/Controllers/Web/Client/Order/OrderSmsController.php');
$check(substr_count($ctrl, '$this->guard();') === 2 && str_contains($ctrl, "PATH = '/client-orders'"), 'garde d\'accès « orders » sur les deux appels');

// Chaque appel $this->sms->x() des contrôleurs vise une méthode publique d'OrderSmsService (sinon page en erreur).
$fake->deliver = false;
$check($svc->delivers() === false && ($fake->deliver = true) && $svc->delivers() === true, 'delivers() relaie la passerelle');
$bad = [];
foreach (glob(__DIR__ . '/../App/Controllers/Web/*/*/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (!str_contains($src, 'OrderSmsService $sms')) continue;
    preg_match_all('/\$this->sms->(\w+)\(/', $src, $m);
    foreach (array_unique($m[1]) as $meth) if (!(new ReflectionClass(OrderSmsService::class))->hasMethod($meth) || !(new ReflectionMethod(OrderSmsService::class, $meth))->isPublic()) $bad[] = basename($f) . '::' . $meth;
}
$check(!$bad, 'contrôleurs : méthodes SMS existantes' . ($bad ? ' — manquantes : ' . implode(', ', $bad) : ''));

$pdo->exec('DROP DATABASE erp_order_sms_test');
echo $fail ? "\n{$fail} FAIL\n" : "\nall ok\n";
exit($fail ? 1 : 0);
