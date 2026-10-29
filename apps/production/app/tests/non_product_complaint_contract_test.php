<?php

declare(strict_types=1);

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/App/Services/Client/Complaint/ComplaintService.php');
$collector = (string) file_get_contents($root . '/App/Services/Client/Complaint/ComplaintCollectorService.php');
$repository = (string) file_get_contents($root . '/App/Repositories/Complaint/ComplaintRepository.php');
$view = (string) file_get_contents($root . '/App/Views/client/complaint/overview.view.php');
$migration = (string) file_get_contents($root . '/migrations/039_non_product_complaints.sql');
$baseRepository = (string) file_get_contents($root . '/App/Core/Helpers/BaseRepository.php');

assertContainsText("['PRODUCT', 'NON_PRODUCT']", $service, 'Serwis musi rozpoznawać oba typy reklamacji.');
assertContainsText('insertNonProductFromCollector', $service, 'Musi istnieć osobny import reklamacji nieproduktowej.');
assertContainsText('($complaint[\'complaint_type\'] ?? \'PRODUCT\') === \'PRODUCT\'', $service, 'Tylko reklamacja produktowa może trafić do wysyłki.');
assertContainsText("'complaint_uuid'", $repository, 'Repository musi zapisywać UUID z outboxu.');
assertContainsText("'complaint_type'", $repository, 'Repository musi zapisywać typ reklamacji.');
assertContainsText("'complaint_type' => \$complaintType", $collector, 'Collector musi przekazać typ do serwisu.');
assertContainsText("'secret' => \$credentials['secret']", $collector, 'Aktualizacja reklamacji musi używać poprawnego sekretu HMAC.');
assertContainsText("\$isNonProduct", $view, 'UI musi rozpoznawać reklamacje nieproduktowe.');
assertContainsText('Inna reklamacja', $view, 'UI musi pokazywać badge reklamacji nieproduktowej.');
assertContainsText('complaint_uuid', $migration, 'Migracja musi dodawać UUID outboxu.');
assertContainsText("complaint_type ENUM('PRODUCT', 'NON_PRODUCT')", $migration, 'Migracja musi dodawać typ reklamacji.');
assertContainsText('syncCollectorAttachments', $service, 'Import musi obsługiwać załączniki razem z reklamacją.');
assertContainsText('insertFromCollector([', $collector, 'Collector musi importować reklamację przed ACK done.');
assertContainsText("=== 'PRODUCT') {", $collector, 'NON_PRODUCT nie może wysyłać ilości uznanej do API.');
if (str_contains($baseRepository, 'show($this->db); exit();')) {
    fwrite(STDERR, "FAIL: Debugowy exit blokuje działanie repozytoriów.\n");
    exit(1);
}

echo "PASS: non-product complaint contract\n";
