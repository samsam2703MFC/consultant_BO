<?php
declare(strict_types=1);
/*
 * E-mails automatiques, de bout en bout sur la copie locale de l'ERP (base du .env). L'état de
 * départ est noté et tout ce que le test crée est supprimé à la fin, même en cas d'échec ;
 * rien ne part (transport factice).
 *   php tests/mail_automation_test.php
 * Vérifie : règles « distribution » activées « à partir de maintenant », messages en file avec PDF,
 * copie à la comptabilité (ligne « Biuro rachunkowe »), livraison prévue, changement de tarif,
 * un seul envoi par événement, « Ponów » ne renvoie pas ce qui est parti, papier du fichier de
 * livraison, mode log_only (aucun SMTP), mode redirect (tout vers l'adresse de test).
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Services\Communication\CommunicationEmailDeliveryWorker;
use App\Services\Communication\DocumentDistributionService;
use App\Services\Communication\GuardedSmtpTransport;
use App\Services\Communication\MailAutomationService;
use App\Services\Communication\MailDeliveryPolicy;
use App\Services\Communication\SmtpTransport;

function expectMail(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

final class RecordingTransport implements SmtpTransport
{
    public array $sent = [];
    public function send(array $account, string $to, string $subject, string $body, string $messageId, array $attachments = []): string
    {
        $this->sent[] = compact('to', 'subject', 'body');
        return 'fake-' . count($this->sent);
    }
}

try {
    $container = require __DIR__ . '/../App/Core/bootstrap.php';
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    echo "mail_automation_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
if (!$pdo->query("SHOW TABLES LIKE 'tfb_doc_distribution'")->fetchColumn()) {
    echo "mail_automation_test: SKIPPED (run php Bin/tfb_migrate.php apply first)\n";
    exit(0);
}

$logOnly = new MailDeliveryPolicy('log_only');
$recorder = new RecordingTransport();
$automation = $container->make(MailAutomationService::class, ['policy' => $logOnly]);
$distribution = $container->get(DocumentDistributionService::class);
$shipping = $container->get(App\Services\Shipping\ShippingDocumentService::class);
$worker = $container->make(CommunicationEmailDeliveryWorker::class, ['transport' => new GuardedSmtpTransport($recorder, $logOnly), 'policy' => $logOnly]);
$rule = fn(string $code) => (int) $pdo->query("SELECT id FROM tfb_mail_rule WHERE code = " . $pdo->quote($code))->fetchColumn();

$before = [
    'message' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM communication_message')->fetchColumn(),
    'dispatch' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tfb_mail_dispatch')->fetchColumn(),
    'notice' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tfb_delivery_notice')->fetchColumn(),
    'rules' => $pdo->query('SELECT id, enabled, start_ref_id, enabled_at FROM tfb_mail_rule')->fetchAll(),
    'moments' => $pdo->query('SELECT doc_key, moment FROM tfb_doc_distribution_moment')->fetchAll(PDO::FETCH_KEY_PAIR),
    'accounting' => $pdo->query('SELECT doc_key, mail_to, mail_address FROM tfb_doc_distribution WHERE id_customer = -1')->fetchAll(),
    'states' => $pdo->query('SELECT id_customer, hash FROM tfb_price_list_state')->fetchAll(PDO::FETCH_KEY_PAIR),
    'accounts' => $pdo->query('SELECT id, is_active FROM communication_email_account')->fetchAll(),
    'address' => $pdo->query("SELECT value FROM tfb_prod_param WHERE code = 'mail.accounting_address'")->fetchColumn(),
    'price' => $pdo->query('SELECT p.id, p.id_customer, p.price FROM final_product_customer_price p JOIN final_product fp ON fp.id = p.id_final_product AND fp.active = 1
                            JOIN customer c ON c.id = p.id_customer AND c.active = 1 WHERE p.available = 1 AND p.price >= 10 ORDER BY p.id LIMIT 1')->fetch(),
];
try {
    (function () use ($pdo, $automation, $worker, $recorder, $rule, $distribution, $shipping, $before) {
        $pdo->exec('UPDATE communication_email_account SET is_active = 0');
        $pdo->exec("UPDATE tfb_prod_param SET value = 'compta@example.org' WHERE code = 'mail.accounting_address'");
        $pdo->exec('UPDATE tfb_mail_rule SET enabled = 0');
        $pdo->exec('DELETE FROM tfb_price_list_state');
        foreach (['proforma', 'wdt', 'invoice'] as $doc) $distribution->saveCell(DocumentDistributionService::ACCOUNTING, $doc, ['mail_to' => 'other'], 'test');

        // Moments de la distribution = règles actives, à partir de maintenant : rien d'ancien ne part.
        $distribution->saveMoment('wdt', 'wdt_issued', 'test');
        $active = $pdo->query("SELECT code FROM tfb_mail_rule WHERE enabled = 1 ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
        expectMail(in_array('WDT_TO_CLIENT', $active, true) && in_array('ORDER_CONFIRMATION', $active, true) && !in_array('WDT_KSEF_TO_CLIENT', $active, true)
            && !array_filter($active, fn($c) => str_starts_with($c, 'ACCOUNTING_')), 'active rules follow the distribution moments: ' . implode(',', $active));
        $first = $automation->dispatch();
        $pastOnly = (int) $pdo->query("SELECT COUNT(*) FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule WHERE r.code <> 'DELIVERY_PLANNED' AND d.id > {$before['dispatch']}")->fetchColumn();
        expectMail($pastOnly === 0, 'enabling a rule does not send documents issued before');
        expectMail((int) $pdo->query('SELECT COUNT(*) FROM tfb_price_list_state')->fetchColumn() > 0, 'first pass only records the price lists');

        // Le dernier WDT et la dernière commande deviennent « nouveaux ».
        $pdo->exec("UPDATE tfb_mail_rule SET start_ref_id = start_ref_id - 1 WHERE code IN ('WDT_TO_CLIENT', 'ORDER_CONFIRMATION')");
        $second = $automation->dispatch();
        $wdtId = (int) $pdo->query('SELECT MAX(id) FROM document_wdt')->fetchColumn();

        $row = $pdo->query("SELECT d.status, d.reason, m.recipient_email, m.subject_text, m.id AS message_id
                            FROM tfb_mail_dispatch d LEFT JOIN communication_message m ON m.id = d.id_message WHERE d.ref_key = 'wdt:{$wdtId}>acc:wdt'")->fetch();
        expectMail($row && $row['status'] === 'queued', 'the new WDT is queued for accounting: ' . json_encode($row));
        expectMail($row['recipient_email'] === 'compta@example.org', 'accounting copy goes to the accounting address');
        expectMail(str_starts_with($row['subject_text'], 'Faktura WDT '), 'Polish accounting subject: ' . $row['subject_text']);
        $pdf = $pdo->query('SELECT filename, content FROM communication_message_attachment WHERE id_message = ' . (int) $row['message_id'])->fetch();
        expectMail($pdf && str_starts_with(ltrim($pdf['content']), '%PDF'), 'the WDT PDF is attached');

        $case = $pdo->query("SELECT status, reason FROM tfb_mail_dispatch WHERE ref_key = 'wdt:{$wdtId}'")->fetch();
        expectMail($case && in_array($case['status'], ['queued', 'skipped'], true), 'the WDT event is recorded once: ' . json_encode($case));
        $client = $pdo->query("SELECT m.recipient_email, m.body_text FROM tfb_mail_dispatch d JOIN communication_message m ON m.id = d.id_message
                               WHERE d.ref_key LIKE 'wdt:{$wdtId}>%' AND d.ref_key NOT LIKE '%>acc:%'")->fetch();
        if (!$client) expectMail(str_contains((string) $case['reason'], 'adresu'), 'no client message: the reason names the missing address');

        $order = $pdo->query("SELECT d.status, m.subject_text FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule
                              LEFT JOIN communication_message m ON m.id = d.id_message WHERE r.code = 'ORDER_CONFIRMATION' AND d.ref_key LIKE 'order:%>%' AND d.ref_key NOT LIKE '%>acc:%'")->fetch();
        expectMail(!$order || str_contains($order['subject_text'], 'commande') || str_contains($order['subject_text'], 'bestelling') || str_contains($order['subject_text'], 'zamówieni') || str_contains($order['subject_text'], 'order'), 'order confirmation in the client language');

        $delivery = (int) $pdo->query("SELECT COUNT(*) FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule WHERE r.code = 'DELIVERY_PLANNED' AND d.ref_key NOT LIKE '%>%' AND d.id > {$before['dispatch']}")->fetchColumn();
        $upcoming = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT id_customer, expected_shipping_date FROM customer_order co WHERE " . App\Domain\Client\Order\OpenOrders::sql('co') . "
                                       AND expected_shipping_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY) x")->fetchColumn();
        expectMail($delivery === $upcoming, "one planned-delivery event per client and day ({$delivery} / {$upcoming})");

        // Changement de tarif : un message (ou un cas noté) pour ce client, une seule fois.
        if ($before['price']) {
            $pdo->exec('UPDATE final_product_customer_price SET price = price + 1 WHERE id = ' . (int) $before['price']['id']);
            $automation->dispatch();
            $price = $pdo->query("SELECT status FROM tfb_mail_dispatch WHERE ref_key LIKE 'price:{$before['price']['id_customer']}:%' AND ref_key NOT LIKE '%>%'")->fetchColumn();
            expectMail(in_array($price, ['queued', 'skipped'], true), 'price change handled: ' . var_export($price, true));
        }

        $third = $automation->dispatch();
        expectMail($third === ['queued' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0], 'each event is handled once: ' . json_encode($third));

        // « Ponów » sur un envoi en échec : le cas est oublié, ce qui est parti reste noté.
        $wdtRule = $rule('WDT_TO_CLIENT');
        $pdo->exec("INSERT INTO tfb_mail_dispatch (id_rule, ref_key, status, reason) VALUES ({$wdtRule}, 'test:1', 'failed', 'x'), ({$wdtRule}, 'test:1>a', 'queued', NULL), ({$wdtRule}, 'test:1>b', 'failed', 'x')");
        $automation->retry((int) $pdo->query("SELECT id FROM tfb_mail_dispatch WHERE ref_key = 'test:1>b'")->fetchColumn());
        $left = $pdo->query("SELECT ref_key FROM tfb_mail_dispatch WHERE ref_key LIKE 'test:1%' ORDER BY ref_key")->fetchAll(PDO::FETCH_COLUMN);
        expectMail($left === ['test:1>a'], 'retry forgets the failed send and its case, keeps the queued one: ' . implode(',', $left));

        // Papier du fichier de livraison = exemplaires de la distribution du client.
        $shipment = $pdo->query('SELECT * FROM shipment_log WHERE id_cmr IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch();
        if ($shipment) {
            $copies = $distribution->paperCopies((int) $shipment['id_customer']);
            $printed = $shipping->buildPaper(new App\Services\Utils\TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false), $shipment, true);
            expectMail($printed['wz'] === $copies['wz'] && $printed['cmr'] === $copies['cmr'] && $printed['wdt'] === $copies['wdt'], 'paper copies follow the distribution: ' . json_encode($printed));
            $all = $shipping->buildPaper(new App\Services\Utils\TCPDFService('P', 'mm', 'a4', true, 'UTF-8', false), $shipment, false);
            expectMail($all['wz'] === WZ_PRINT_COUNT && $all['cmr'] === CMR_PRINT_COUNT, 'the full set is still available');
        }

        // log_only : tout est traité, rien n'atteint un serveur SMTP.
        $queued = (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE status = 'queued' AND created_by LIKE 'automat:%'")->fetchColumn();
        $result = $worker->processDue(500);
        expectMail($result['sent'] >= $queued && $recorder->sent === [], 'log_only: messages processed, no SMTP call');
        $logged = (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE created_by LIKE 'automat:%' AND provider_message_id LIKE 'LOG_ONLY:%' AND id > {$before['message']}")->fetchColumn();
        expectMail($logged === $queued, "log_only messages are marked as such ({$logged} / {$queued})");

    })();
} finally {
    $pdo->exec('DELETE FROM communication_message_attachment WHERE id_message > ' . $before['message']);
    $pdo->exec('DELETE FROM communication_message_attempt WHERE id_message > ' . $before['message']);
    $pdo->exec('DELETE FROM communication_message WHERE id > ' . $before['message']);
    $pdo->exec('DELETE FROM tfb_mail_dispatch WHERE id > ' . $before['dispatch']);
    $pdo->exec('DELETE FROM tfb_delivery_notice WHERE id > ' . $before['notice']);
    $restore = $pdo->prepare('UPDATE tfb_mail_rule SET enabled = ?, start_ref_id = ?, enabled_at = ? WHERE id = ?');
    foreach ($before['rules'] as $r) $restore->execute([$r['enabled'], $r['start_ref_id'], $r['enabled_at'], $r['id']]);
    $moment = $pdo->prepare('UPDATE tfb_doc_distribution_moment SET moment = ? WHERE doc_key = ?');
    foreach ($before['moments'] as $doc => $m) $moment->execute([$m, $doc]);
    $cell = $pdo->prepare('UPDATE tfb_doc_distribution SET mail_to = ?, mail_address = ? WHERE id_customer = -1 AND doc_key = ?');
    foreach ($before['accounting'] as $c) $cell->execute([$c['mail_to'], $c['mail_address'], $c['doc_key']]);
    $pdo->exec('DELETE FROM tfb_price_list_state');
    $state = $pdo->prepare('INSERT INTO tfb_price_list_state (id_customer, hash) VALUES (?, ?)');
    foreach ($before['states'] as $id => $hash) $state->execute([$id, $hash]);
    if ($before['price']) $pdo->prepare('UPDATE final_product_customer_price SET price = ? WHERE id = ?')->execute([$before['price']['price'], $before['price']['id']]);
    $account = $pdo->prepare('UPDATE communication_email_account SET is_active = ? WHERE id = ?');
    foreach ($before['accounts'] as $a) $account->execute([$a['is_active'], $a['id']]);
    $pdo->prepare("UPDATE tfb_prod_param SET value = ? WHERE code = 'mail.accounting_address'")->execute([$before['address']]);
}

// redirect : le vrai destinataire n'est jamais contacté.
$redirect = new GuardedSmtpTransport($recorder, new MailDeliveryPolicy('redirect', 'test@example.org'));
$redirect->send([], 'client@example.com', 'Facture 1', 'Bonjour', '<id@x>');
expectMail($recorder->sent[0]['to'] === 'test@example.org' && str_starts_with($recorder->sent[0]['subject'], '[TEST → client@example.com]'), 'redirect mode sends to the test address only');
expectMail((new MailDeliveryPolicy('redirect', 'not-an-address'))->mode() === 'log_only', 'redirect without a valid address falls back to log_only');
expectMail((new MailDeliveryPolicy('whatever'))->mode() === 'log_only', 'an unknown mode never sends');

expectMail((int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM communication_message')->fetchColumn() === $before['message'], 'the test leaves no message behind');
echo "mail_automation_test: OK\n";
