<?php
declare(strict_types=1);
/*
 * E-mails automatiques, de bout en bout sur la copie locale de l'ERP (base du .env). L'état de
 * départ est noté et tout ce que le test crée est supprimé à la fin, même en cas d'échec ;
 * rien ne part (transport factice).
 *   php tests/mail_automation_test.php
 * Vérifie : règles activées « à partir de maintenant », messages en file avec PDF, copie à la
 * comptabilité, livraison prévue, un seul envoi par événement, mode log_only (aucun SMTP),
 * mode redirect (tout vers l'adresse de test).
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Services\Communication\CommunicationEmailDeliveryWorker;
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
if (!$pdo->query("SHOW TABLES LIKE 'tfb_mail_rule'")->fetchColumn()) {
    echo "mail_automation_test: SKIPPED (run php Bin/tfb_migrate.php apply first)\n";
    exit(0);
}

$logOnly = new MailDeliveryPolicy('log_only');
$recorder = new RecordingTransport();
$automation = $container->make(MailAutomationService::class, ['policy' => $logOnly]);
$worker = $container->make(CommunicationEmailDeliveryWorker::class, ['transport' => new GuardedSmtpTransport($recorder, $logOnly), 'policy' => $logOnly]);
$rule = fn(string $code) => (int) $pdo->query("SELECT id FROM tfb_mail_rule WHERE code = " . $pdo->quote($code))->fetchColumn();

$before = [
    'message' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM communication_message')->fetchColumn(),
    'dispatch' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tfb_mail_dispatch')->fetchColumn(),
    'rules' => $pdo->query('SELECT id, enabled, start_ref_id, enabled_at FROM tfb_mail_rule')->fetchAll(),
    'accounts' => $pdo->query('SELECT id, is_active FROM communication_email_account')->fetchAll(),
    'address' => $pdo->query("SELECT value FROM tfb_prod_param WHERE code = 'mail.accounting_address'")->fetchColumn(),
];
try {
    (function () use ($pdo, $automation, $worker, $recorder, $rule) {
        $pdo->exec('UPDATE communication_email_account SET is_active = 0');
        $pdo->exec("UPDATE tfb_prod_param SET value = 'compta@example.org' WHERE code = 'mail.accounting_address'");
        $pdo->exec('UPDATE tfb_mail_rule SET enabled = 0');

        // Activer = à partir de maintenant : rien d'ancien ne part.
        foreach (['ACCOUNTING_WDT', 'WDT_TO_CLIENT', 'DELIVERY_PLANNED', 'ORDER_CONFIRMATION'] as $code) {
            $automation->updateRule($rule($code), ['enabled' => true], 'test');
        }
        $first = $automation->dispatch();
        $pastOnly = (int) $pdo->query("SELECT COUNT(*) FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule WHERE r.code <> 'DELIVERY_PLANNED'")->fetchColumn();
        expectMail($pastOnly === 0, 'enabling a rule does not send documents issued before');

        // Le dernier WDT et la dernière commande deviennent « nouveaux ».
        $pdo->exec("UPDATE tfb_mail_rule SET start_ref_id = start_ref_id - 1 WHERE code IN ('ACCOUNTING_WDT', 'WDT_TO_CLIENT', 'ORDER_CONFIRMATION')");
        $second = $automation->dispatch();

        $row = $pdo->query("SELECT d.status, d.reason, m.recipient_email, m.subject_text, m.id AS message_id
                            FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule
                            LEFT JOIN communication_message m ON m.id = d.id_message WHERE r.code = 'ACCOUNTING_WDT'")->fetch();
        expectMail($row && $row['status'] === 'queued', 'the new WDT is queued for accounting: ' . json_encode($row));
        expectMail($row['recipient_email'] === 'compta@example.org', 'accounting copy goes to the accounting address');
        expectMail(str_starts_with($row['subject_text'], 'Faktura WDT '), 'Polish accounting subject: ' . $row['subject_text']);
        $pdf = $pdo->query('SELECT filename, content FROM communication_message_attachment WHERE id_message = ' . (int) $row['message_id'])->fetch();
        expectMail($pdf && str_starts_with(ltrim($pdf['content']), '%PDF'), 'the WDT PDF is attached');

        $client = $pdo->query("SELECT d.status, d.reason FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule WHERE r.code = 'WDT_TO_CLIENT'")->fetch();
        expectMail($client && in_array($client['status'], ['queued', 'skipped'], true), 'the client copy is queued or skipped with a reason');
        if ($client['status'] === 'skipped') expectMail(str_contains($client['reason'], 'adresu'), 'skip reason names the missing address');

        $order = $pdo->query("SELECT d.status, m.subject_text FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule
                              LEFT JOIN communication_message m ON m.id = d.id_message WHERE r.code = 'ORDER_CONFIRMATION'")->fetch();
        expectMail($order && ($order['status'] !== 'queued' || str_contains($order['subject_text'], 'Confirmation de votre commande')), 'order confirmation in French');

        $delivery = $pdo->query("SELECT COUNT(*) FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule
                                 JOIN communication_message m ON m.id = d.id_message
                                 WHERE r.code = 'DELIVERY_PLANNED' AND m.subject_text LIKE 'Livraison prévue le %'")->fetchColumn();
        $upcoming = (int) $pdo->query("SELECT COUNT(DISTINCT id_customer) FROM customer_order WHERE status = 1 AND expected_shipping_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY")->fetchColumn();
        expectMail($upcoming === 0 || (int) $delivery + (int) $pdo->query("SELECT COUNT(*) FROM tfb_mail_dispatch d JOIN tfb_mail_rule r ON r.id = d.id_rule WHERE r.code = 'DELIVERY_PLANNED' AND d.status = 'skipped'")->fetchColumn() === $upcoming,
            'one planned-delivery event per client and day');

        $third = $automation->dispatch();
        expectMail($third === ['queued' => 0, 'skipped' => 0, 'failed' => 0], 'each event is handled once');

        // log_only : tout est traité, rien n'atteint un serveur SMTP.
        $queued = (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE status = 'queued' AND created_by LIKE 'automat:%'")->fetchColumn();
        $result = $worker->processDue(100);
        expectMail($result['sent'] >= $queued && $recorder->sent === [], 'log_only: messages processed, no SMTP call');
        $logged = (int) $pdo->query("SELECT COUNT(*) FROM communication_message WHERE created_by LIKE 'automat:%' AND provider_message_id LIKE 'LOG_ONLY:%'")->fetchColumn();
        expectMail($logged === $queued, 'log_only messages are marked as such');

    })();
} finally {
    $pdo->exec('DELETE FROM communication_message_attachment WHERE id_message > ' . $before['message']);
    $pdo->exec('DELETE FROM communication_message_attempt WHERE id_message > ' . $before['message']);
    $pdo->exec('DELETE FROM communication_message WHERE id > ' . $before['message']);
    $pdo->exec('DELETE FROM tfb_mail_dispatch WHERE id > ' . $before['dispatch']);
    $restore = $pdo->prepare('UPDATE tfb_mail_rule SET enabled = ?, start_ref_id = ?, enabled_at = ? WHERE id = ?');
    foreach ($before['rules'] as $r) $restore->execute([$r['enabled'], $r['start_ref_id'], $r['enabled_at'], $r['id']]);
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
