<?php
declare(strict_types=1);

function expectCommunicationDocument(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$migration = file_get_contents(__DIR__ . '/../migrations/035_communication_document_email.sql');
$service = file_get_contents(__DIR__ . '/../App/Services/Communication/CommunicationService.php');
$controller = file_get_contents(__DIR__ . '/../App/Controllers/Web/Communication/CommunicationController.php');
$routes = file_get_contents(__DIR__ . '/../App/Core/Routes/Communication/CommunicationRoutes.php');
$invoiceView = file_get_contents(__DIR__ . '/../App/Views/document/invoice/overview.view.php');
$shippingView = file_get_contents(__DIR__ . '/../App/Views/document/shipping/overview.view.php');
$reminderView = file_get_contents(__DIR__ . '/../App/Views/communication/reminder.view.php');
$smtpTransport = file_get_contents(__DIR__ . '/../App/Services/Communication/SmtpTransport.php');
$smtpImplementation = file_get_contents(__DIR__ . '/../App/Services/Communication/GmailSmtpTransport.php');
$emailAccountService = file_get_contents(__DIR__ . '/../App/Services/Communication/EmailAccountService.php');
$imapWorker = file_get_contents(__DIR__ . '/../App/Services/Communication/CommunicationImapSyncWorker.php');
$plaintextMigration = file_get_contents(__DIR__ . '/../migrations/036_communication_email_plaintext_secrets.sql');
$legacySecretNullableMigration = file_get_contents(__DIR__ . '/../migrations/038_communication_email_legacy_secret_nullable.sql');
$proformaReminderMigration = file_get_contents(__DIR__ . '/../migrations/037_communication_proforma_payment_reminder_templates.sql');
$proformaContext = file_get_contents(__DIR__ . '/../App/Services/Communication/ProformaEmailContextService.php');
$proformaRepository = file_get_contents(__DIR__ . '/../App/Repositories/Document/Proforma/ProformaRepository.php');
$deliveryWorker = file_get_contents(__DIR__ . '/../App/Services/Communication/CommunicationEmailDeliveryWorker.php');
$messageListView = file_get_contents(__DIR__ . '/../App/Views/communication/messages.view.php');
$composeView = file_get_contents(__DIR__ . '/../App/Views/communication/compose.view.php');

expectCommunicationDocument(str_contains($migration, 'id_invoice'), 'Messages must be linkable to invoices.');
expectCommunicationDocument(str_contains($service, '\'invoice\' => $this->invoices->generateDocumentPDF') && str_contains($service, '\'wdt\' => $this->wdts->generateDocumentPDF'), 'Invoice and WDT PDFs must be attached from their existing generators.');
expectCommunicationDocument(str_contains($service, '\'difference\' => $this->differences->generateDocumentPDF'), 'Difference reminders must attach the difference PDF.');
expectCommunicationDocument(str_contains($service, 'getUnpaidForReminder'), 'Unpaid proformas must be selectable for manual reminders.');
expectCommunicationDocument(str_contains($controller, 'saveReminderProformaMessage') && str_contains($controller, 'saveReminderDifferenceMessage'), 'Both reminder sources must be queueable.');
expectCommunicationDocument(str_contains($routes, '/communication/compose/invoice/') && str_contains($routes, '/communication/compose/wdt/'), 'Invoice and WDT composer routes are required.');
expectCommunicationDocument(str_contains($invoiceView, '/communication/compose/invoice/') && str_contains($shippingView, '/communication/compose/wdt/'), 'Document lists must expose manual email actions.');
expectCommunicationDocument(str_contains($reminderView, 'reminder-proforma') && str_contains($reminderView, 'compose/reminder/difference'), 'Reminder page must expose both manual sources.');
expectCommunicationDocument(str_contains($smtpTransport, 'interface SmtpTransport') && str_contains($smtpImplementation, 'implements SmtpTransport'), 'Delivery transport must be replaceable in tests.');
expectCommunicationDocument(!str_contains($emailAccountService, 'EMAIL_CONFIG_KEY') && !str_contains($imapWorker, 'EmailSecretCipher'), 'Mail configuration must not require an environment encryption key.');
expectCommunicationDocument(str_contains($plaintextMigration, 'smtp_secret') && str_contains($plaintextMigration, 'imap_secret'), 'Plaintext secret columns must be introduced by a migration.');
expectCommunicationDocument(str_contains($legacySecretNullableMigration, 'MODIFY COLUMN smtp_secret_encrypted TEXT NULL') && str_contains($emailAccountService, "'smtp_secret_encrypted'=>\$old['smtp_secret_encrypted'] ?? ''"), 'Legacy mandatory secret columns must not prevent creation of the mail account.');
expectCommunicationDocument(str_contains($service, 'proforma_payment_reminder') && str_contains($controller, 'proformaReminderType'), 'Proforma payment reminders must use dedicated message types.');
expectCommunicationDocument(str_contains($proformaContext, "'proforma' => [") && str_contains($proformaContext, "'number' => (string) \$proforma['doc_num']"), 'Proforma reminder context must expose the proforma number.');
expectCommunicationDocument(str_contains($proformaReminderMigration, "'proforma_payment_reminder', 'fr'") && str_contains($proformaReminderMigration, "'proforma_payment_reminder', 'nl'"), 'French and Dutch proforma reminder templates must be seeded.');
expectCommunicationDocument(str_contains($proformaRepository, 'SUM(dpp.total_price_net)') && str_contains($reminderView, 'Wartość netto'), 'The unpaid proforma list must show the historical proforma amount.');
expectCommunicationDocument(str_contains($proformaRepository, 'ORDER BY dp.established_date DESC') && str_contains($reminderView, 'sortable-proforma') && str_contains($reminderView, 'sortRows(activeColumn, direction);'), 'Unpaid proformas must default to newest first and support column sorting.');
expectCommunicationDocument(str_contains($controller, "'reminder/proforma'") && str_contains($controller, "'reminder/difference'"), 'Reminder composers must post to dedicated reminder endpoints.');
expectCommunicationDocument(str_contains($deliveryWorker, 'processMessageNow') && str_contains($messageListView, 'send-now'), 'Queued messages must be sendable immediately from the message list.');
expectCommunicationDocument(str_contains($messageListView, 'Trwa wysyłanie e-maila') && str_contains($messageListView, 'allowOutsideClick: false') && str_contains($messageListView, 'Swal.showLoading()'), 'Immediate delivery must show a non-dismissible progress modal until SMTP returns.');
expectCommunicationDocument(str_contains($composeView, "Swal.fire({") && str_contains($composeView, 'communicationMessagesUrl') && str_contains($composeView, 'queueSuccessRedirect'), 'Queueing must show a success modal with the immediate-send destination.');

echo "communication_document_email_contract_test: OK\n";
