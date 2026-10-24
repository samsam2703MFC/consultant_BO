<?php
declare(strict_types=1);
/*
 * Dystrybucja dokumentów sur la copie locale de l'ERP (base du .env) : adresses de repli, noms des documents
 * dans la langue du client, validation d'une cellule, client sans réglage propre (réglages par défaut,
 * franchisés), plan d'envoi d'un événement (un message par adresse, copie comptable, documents d'un autre
 * moment exclus), papier, moment commun → règles e-mail, copie des réglages. Tout est restauré à la fin.
 *   php tests/document_distribution_test.php
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Exceptions\CustomException;
use App\Services\Communication\DocumentDistributionService as D;

function expectDist(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function rejects(callable $call, string $message): void
{
    try { $call(); } catch (CustomException) { return; }
    throw new RuntimeException('FAILED (no error): ' . $message);
}

// Sans base : adresses et noms
$emails = ['head' => 'shop@example.be', 'accountant' => '', 'salesman' => 'sales@example.be'];
expectDist(D::address('acc', null, $emails) === 'shop@example.be', 'księgowość absente → adresse principale');
expectDist(D::address('head', null, ['head' => '', 'accountant' => 'a@x.be', 'salesman' => 's@x.be']) === 's@x.be', 'principale absente → commercial');
expectDist(D::address('other', 'compta@example.org', $emails) === 'compta@example.org' && D::address('other', 'nope', $emails) === '', 'autre adresse validée');
expectDist(D::address('', null, $emails) === '' && D::address('head', null, ['head' => '', 'accountant' => '', 'salesman' => '']) === '', 'pas d\'envoi / pas d\'adresse');
expectDist(D::names(['wz', 'ppwr'], 'fr') === 'Bon de livraison (WZ), Emballages (PPWR)' && D::names(['goal'], 'nl') === 'Omzetdoel' && D::names(['price'], 'xx') === 'Tarif', 'noms dans la langue du client');
foreach (D::DOCS as $key => $doc) {
    expectDist(array_diff($doc['moments'], array_keys(D::MOMENTS)) === [], "$key : moments connus");
    foreach (['fr', 'nl', 'pl', 'en'] as $lang) expectDist(($doc['names'][$lang] ?? '') !== '', "$key : nom $lang");
}

try {
    $container = require __DIR__ . '/../App/Core/bootstrap.php';
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    echo "document_distribution_test: SKIPPED (no database: {$e->getMessage()})\n";
    exit(0);
}
if (!$pdo->query("SHOW TABLES LIKE 'tfb_doc_distribution'")->fetchColumn()) {
    echo "document_distribution_test: SKIPPED (run php Bin/tfb_migrate.php apply first)\n";
    exit(0);
}
$customers = $pdo->query('SELECT id, (franchise_network_id IS NOT NULL OR type = 1) AS franchise FROM customer WHERE active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
if (count($customers) < 2) {
    echo "document_distribution_test: SKIPPED (two active customers needed)\n";
    exit(0);
}
[$x, $y] = [(int) $customers[0]['id'], (int) $customers[1]['id']];
$service = $container->get(D::class);
$snapshot = [
    'cells' => $pdo->query('SELECT * FROM tfb_doc_distribution')->fetchAll(PDO::FETCH_ASSOC),
    'moments' => $pdo->query('SELECT * FROM tfb_doc_distribution_moment')->fetchAll(PDO::FETCH_ASSOC),
    'rules' => $pdo->query('SELECT id, enabled, start_ref_id, enabled_at FROM tfb_mail_rule')->fetchAll(PDO::FETCH_ASSOC),
];
try {
    // Client sans réglage propre : réglages par défaut ; podsumowanie / cel tylko dla franczyzobiorców
    $pdo->exec("DELETE FROM tfb_doc_distribution WHERE id_customer IN ($x, $y)");
    $row = array_values(array_filter($service->matrix()['rows'], fn($r) => $r['id'] === $x))[0];
    expectDist($row['inherited'] && $row['cells']['wz']['paper_copies'] === (int) $pdo->query("SELECT paper_copies FROM tfb_doc_distribution WHERE id_customer = 0 AND doc_key = 'wz'")->fetchColumn(), 'nouveau client : réglages par défaut');
    expectDist(($row['cells']['summary']['mail_to'] === 'head') === (bool) $customers[0]['franchise'], 'récapitulatif mensuel seulement pour les franchisés');

    // Validation d'une cellule
    rejects(fn() => $service->saveCell($x, 'nope', [], 'test'), 'document inconnu');
    rejects(fn() => $service->saveCell($x, 'wz', ['mail_to' => 'boss'], 'test'), 'destinataire inconnu');
    rejects(fn() => $service->saveCell($x, 'wz', ['mail_to' => 'other', 'mail_address' => ''], 'test'), 'autre adresse vide');
    rejects(fn() => $service->saveCell($x, 'wz', ['mail_to' => 'other', 'mail_address' => 'pas-un-email'], 'test'), 'adresse invalide');
    rejects(fn() => $service->saveCell($x, 'wz', ['paper_copies' => 10], 'test'), 'au plus 9 exemplaires');
    rejects(fn() => $service->saveCell(D::ACCOUNTING, 'wz', ['mail_to' => 'other'], 'test'), 'comptabilité : seulement proformas et factures');
    rejects(fn() => $service->saveCell(D::ACCOUNTING, 'wdt', ['mail_to' => 'head'], 'test'), 'comptabilité : seulement sa propre adresse');

    // Première modification : le client reçoit une copie complète des réglages par défaut
    $row = $service->saveCell($x, 'wz', ['mail_to' => 'head', 'paper_copies' => 2], 'test');
    expectDist(!$row['inherited'] && (int) $pdo->query("SELECT COUNT(*) FROM tfb_doc_distribution WHERE id_customer = $x")->fetchColumn() === count(D::DOCS), 'cellule modifiée : réglages propres complets');
    $service->saveCell($x, 'cmr', ['mail_to' => '', 'paper_copies' => 3], 'test');
    $service->saveCell($x, 'ppwr', ['mail_to' => 'other', 'mail_address' => 'ppwr@example.org'], 'test');
    $service->saveCell($x, 'wdt', ['mail_to' => 'acc', 'paper_copies' => 1], 'test');
    $service->saveCell($x, 'notice', ['mail_to' => '', 'sms' => true, 'paper_copies' => 4], 'test');
    $service->saveCell(D::ACCOUNTING, 'wdt', ['mail_to' => 'other', 'mail_address' => ''], 'test');
    $cells = $service->forCustomer($x);
    expectDist($cells['notice']['sms'] && $cells['notice']['paper_copies'] === 0, 'pas de papier pour un avis de livraison');
    expectDist($service->paperCopies($x) === ['wdt' => 1, 'wz' => 2, 'cmr' => 3, 'diff' => $cells['diff']['paper_copies'], 'ppwr' => 0], 'exemplaires papier du client');

    // Plan d'un événement : documents du moment, un message par adresse, copie comptable
    $service->saveMoment('wdt', 'wdt_ksef_accepted', 'test');
    $plan = $service->plan('shipment_sent', $x, $emails, ['wz', 'cmr', 'ppwr', 'wdt']);
    expectDist($plan['docs'] === ['wz', 'cmr', 'ppwr'], 'WDT exclue : elle part au moment KSeF');
    expectDist($plan['client'] === [['address' => 'shop@example.be', 'docs' => ['wz']], ['address' => 'ppwr@example.org', 'docs' => ['ppwr']]], 'un message par adresse : ' . json_encode($plan['client']));
    expectDist($plan['accounting'] === [] && !$plan['sms'], 'pas de copie comptable ni de SMS à l\'expédition');
    $plan = $service->plan('wdt_ksef_accepted', $x, $emails, ['wdt']);
    expectDist($plan['client'] === [['address' => 'shop@example.be', 'docs' => ['wdt']]] && $plan['accounting'] === [['address' => '', 'doc' => 'wdt']], 'WDT : client (repli) + copie comptable (adresse des réglages)');
    $plan = $service->plan('wdt_ksef_accepted', $x, ['head' => '', 'accountant' => '', 'salesman' => ''], ['wdt']);
    expectDist($plan['client'] === [] && $plan['unreachable'] === ['wdt' => 'księgowość'], 'client sans adresse : document signalé');
    expectDist($service->plan('delivery_planned', $x, $emails, ['notice'])['sms'], 'avis de livraison : SMS');

    // Moment commun → règles e-mail activées / désactivées
    rejects(fn() => $service->saveMoment('wz', 'month_start', 'test'), 'moment non autorisé pour le document');
    $service->saveMoment('wdt', 'wdt_issued', 'test');
    $enabled = $pdo->query("SELECT code, enabled FROM tfb_mail_rule WHERE code IN ('WDT_TO_CLIENT', 'WDT_KSEF_TO_CLIENT')")->fetchAll(PDO::FETCH_KEY_PAIR);
    expectDist((int) $enabled['WDT_TO_CLIENT'] === 1 && (int) $enabled['WDT_KSEF_TO_CLIENT'] === 0, 'moment changé : règle correspondante active, l\'autre arrêtée');
    $start = $pdo->query("SELECT start_ref_id FROM tfb_mail_rule WHERE code = 'WDT_TO_CLIENT'")->fetchColumn();
    expectDist((int) $start === (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM document_wdt')->fetchColumn(), 'règle activée à partir de maintenant');
    $service->saveMoment('wdt', 'wdt_ksef_accepted', 'test');

    // Copie des réglages d'un client vers un autre
    expectDist($service->copyClient($x, [$y, $x, -1], 'test') === 1 && $service->forCustomer($y) === $service->forCustomer($x), 'réglages copiés');
    rejects(fn() => $service->copyClient(D::ACCOUNTING, [$y], 'test'), 'pas de copie depuis la comptabilité');
} finally {
    $pdo->exec('DELETE FROM tfb_doc_distribution');
    $insert = $pdo->prepare('INSERT INTO tfb_doc_distribution (id_customer, doc_key, mail_to, mail_address, paper_copies, sms, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($snapshot['cells'] as $c) $insert->execute([$c['id_customer'], $c['doc_key'], $c['mail_to'], $c['mail_address'], $c['paper_copies'], $c['sms'], $c['updated_by'], $c['updated_at']]);
    $pdo->exec('DELETE FROM tfb_doc_distribution_moment');
    $insert = $pdo->prepare('INSERT INTO tfb_doc_distribution_moment (doc_key, moment, updated_by, updated_at) VALUES (?, ?, ?, ?)');
    foreach ($snapshot['moments'] as $m) $insert->execute([$m['doc_key'], $m['moment'], $m['updated_by'], $m['updated_at']]);
    $restore = $pdo->prepare('UPDATE tfb_mail_rule SET enabled = ?, start_ref_id = ?, enabled_at = ? WHERE id = ?');
    foreach ($snapshot['rules'] as $r) $restore->execute([$r['enabled'], $r['start_ref_id'], $r['enabled_at'], $r['id']]);
}

// Code : pages, menu, fichier de livraison
$root = __DIR__ . '/..';
$routes = file_get_contents("$root/App/Core/Routes/Communication/CommunicationRoutes.php") . file_get_contents("$root/App/Core/Routes/Logistics/LogisticsRoutes.php");
foreach (["'/communication/distribution'", '/ajax/communication/distribution/cell', '/ajax/communication/distribution/moment', '/ajax/communication/distribution/copy',
          "'/logistics/documents'", '/ajax/logistics/documents/day.pdf', "'/communication/automations', ['redirect'"] as $route) {
    expectDist(str_contains($routes, $route), "trasa $route");
}
$sidebar = file_get_contents("$root/App/Views/page_components/sidebar.twig");
expectDist(str_contains($sidebar, '/communication/distribution') && str_contains($sidebar, '/logistics/documents') && !str_contains($sidebar, 'Automatyczne e-maile</a>'), 'menu : Dystrybucja dokumentów, Dokumenty dostawy');
$shipping = file_get_contents("$root/App/Services/Shipping/ShippingDocumentService.php");
expectDist(str_contains($shipping, '$this->distribution->paperCopies('), 'fichier de livraison : papier selon la distribution');
$migration = file_get_contents("$root/migrations/tfb/066_document_distribution.sql");
expectDist(!preg_match('/ADD COLUMN IF NOT EXISTS/i', $migration), 'migration compatible MySQL 8');
foreach (['price_list', 'monthly_report'] as $type) foreach (['fr', 'nl', 'pl', 'en'] as $lang) expectDist(str_contains($migration, "'$type', '$lang'"), "modèle $type $lang");

echo "document_distribution_test: OK\n";
