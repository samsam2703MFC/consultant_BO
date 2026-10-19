<?php
declare(strict_types=1);
/*
 * Zakupy i zadania stanowisk : test na lokalnej bazie ERP (erp), z przywróceniem stanu w finally.
 * Analiza kosztów, oferta (walidacja, przeliczenie jednostek), zapytanie ofertowe od utworzenia
 * do rozstrzygnięcia (e-maile tylko w kolejce, z załącznikiem CSV), rejestr NC, okresy i statusy
 * zadań stanowisk.
 *
 *   php tests/purchasing_test.php
 */
require_once __DIR__ . '/../App/Core/init.php';

use App\Core\Api\Workshop\ApiException;
use App\Exceptions\CustomException;
use App\Services\Organization\PositionTaskService;
use App\Services\Purchasing\CostOptimizationService;
use App\Services\Purchasing\NcService;
use App\Services\Purchasing\OfferService;
use App\Services\Purchasing\TenderService;
use App\Services\Recipe\RecipeCostRecalculationService;

function expectPurch(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

function apiFields(callable $fn): array
{
    try { $fn(); } catch (ApiException $e) { return array_column($e->fieldErrors(), 'field'); }
    return [];
}

$container = require __DIR__ . '/../App/Core/bootstrap.php';
$pdo = $container->get(PDO::class);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Surowiec w gramach używany w recepturach, z dostawcą : ceny są przywracane na końcu.
$material = $pdo->query("SELECT rm.* FROM raw_material rm JOIN unit u ON u.id = rm.id_unit AND u.name = 'g'
    WHERE rm.price_per_unit > 0 AND rm.id_current_supplier IS NOT NULL AND rm.package_size_in_base_unit > 0
      AND EXISTS (SELECT 1 FROM flattened_recipe_ingredient f WHERE f.material_id = rm.id) ORDER BY rm.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
expectPurch((bool) $material, 'a material in grams is available');
$materialId = (int) $material['id'];
$maxMessage = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM communication_message')->fetchColumn();
$maxOccurrence = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM tfb_task_occurrence')->fetchColumn();
$userId = (int) $pdo->query('SELECT MIN(id) FROM user')->fetchColumn();
$userPosition = $pdo->query("SELECT * FROM tfb_user_position WHERE id_user = {$userId}")->fetch(PDO::FETCH_ASSOC) ?: null;
$supplierIds = [];
$tenderId = null;

try {
    // --- Analiza kosztów
    $costs = $container->get(CostOptimizationService::class);
    $overview = $costs->overview();
    expectPurch($overview['totals']['spend'] > 0, 'annual spend computed from orders × recipes');
    $spends = array_column($overview['rows'], 'spend');
    $sorted = $spends;
    rsort($sorted);
    expectPurch($spends == $sorted, 'rows sorted by spend');
    $detail = $costs->material($materialId);
    expectPurch($detail['unit'] === 'kg' && abs($detail['price'] - $material['price_per_unit'] * 1000) < 0.001, 'grams shown per kg');
    $sim = $costs->simulate($materialId, $detail['price'] * 1.1);
    expectPurch($detail['qty'] <= 0 || $sim['annual_delta'] > 0, 'a 10 % rise increases annual cost');

    // --- Dostawcy testowi z adresem e-mail
    foreach (['A', 'B'] as $suffix) {
        $pdo->prepare("INSERT INTO supplier (name, phone, email, is_active) VALUES (?, '0', ?, 1)")->execute(["TEST dostawca {$suffix}", "dostawca{$suffix}@example.test"]);
        $supplierIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->exec("INSERT INTO supplier (name, phone, email, is_active) VALUES ('TEST bez maila', '0', '', 1)");
    $supplierIds[] = $noMail = (int) $pdo->lastInsertId();

    // --- Oferta : walidacja i jednostki (cena za opakowanie 25 kg)
    $offers = $container->get(OfferService::class);
    $missing = apiFields(fn() => $offers->add(['pack_price' => 'abc'], $userId));
    expectPurch(in_array('supplier_id', $missing, true) && in_array('material_id', $missing, true) && in_array('pack_price', $missing, true) && in_array('pack_size', $missing, true), 'offer: all errors at once');
    $offerId = $offers->add(['supplier_id' => $supplierIds[0], 'material_id' => $materialId, 'pack_price' => '250', 'pack_size' => '25'], $userId);
    $offer = $pdo->query("SELECT * FROM tfb_mp_offer WHERE id = {$offerId}")->fetch(PDO::FETCH_ASSOC);
    expectPurch((float) $offer['pack_size_base'] === 25000.0 && abs((float) $offer['price_per_base'] - 0.01) < 1e-9, '25 kg pack stored as 25 000 g, 10 zł/kg');
    expectPurch($offer['valid_to'] > $offer['valid_from'], 'default validity applied');
    $offers->reject($offerId);

    // --- Zapytanie ofertowe
    $tenders = $container->get(TenderService::class);
    $errors = apiFields(fn() => $tenders->create(['title' => 'X', 'material_ids' => [$materialId], 'supplier_ids' => [$noMail]], $userId));
    expectPurch($errors === ['supplier_ids'], 'a supplier without e-mail is refused');
    $tenderId = $tenders->create(['title' => 'TEST zapytanie', 'language' => 'pl', 'intro' => 'Dostawy co tydzień.',
        'material_ids' => [$materialId], 'supplier_ids' => array_slice($supplierIds, 0, 2)], $userId);
    $preview = $tenders->preview($tenderId);
    expectPurch(str_contains($preview['body'], $material['name']) && str_contains($preview['body'], 'Dostawy co tydzień.') && str_contains($preview['subject'], 'AO-'), 'preview lists the material and the note');
    expectPurch($tenders->send($tenderId) === 2, 'one e-mail per supplier');
    expectPurch($tenders->send($tenderId) === 0, 'a second send does not repeat invitations');
    $queued = $pdo->query("SELECT m.recipient_email, m.status, a.filename, a.mime_type FROM communication_message m
        JOIN communication_message_attachment a ON a.id_message = m.id WHERE m.id > {$maxMessage} AND m.message_type = 'tender_request' ORDER BY m.id")->fetchAll(PDO::FETCH_ASSOC);
    expectPurch(count($queued) === 2 && $queued[0]['status'] === 'queued' && $queued[0]['mime_type'] === 'text/csv' && str_ends_with($queued[0]['filename'], '.csv'), 'queued with the CSV to fill in');

    // Odpowiedzi : dostawca A tańszy, B droższy niż obecna cena
    $currentKg = (float) $material['price_per_unit'] * 1000;
    expectPurch($tenders->recordReply($tenderId, $supplierIds[0], [['material_id' => $materialId, 'pack_price' => (string) round($currentKg * 0.9 * 10, 2), 'pack_size' => '10']], $userId) === 1, 'reply A saved');
    expectPurch($tenders->recordReply($tenderId, $supplierIds[1], [['material_id' => $materialId, 'pack_price' => (string) round($currentKg * 1.2 * 10, 2), 'pack_size' => '10'], ['material_id' => 999999, 'pack_price' => '1']], $userId) === 1, 'reply B saved, unknown material ignored');
    $refused = false;
    try { $tenders->recordReply($tenderId, $noMail, [['material_id' => $materialId, 'pack_price' => '1', 'pack_size' => '1']], $userId); } catch (CustomException) { $refused = true; }
    expectPurch($refused, 'a supplier not invited cannot reply');
    $matrix = $tenders->detail($tenderId);
    $item = $matrix['items'][0];
    $best = $item['cells'][$supplierIds[0]];
    expectPurch($item['best_offer_id'] === (int) $best['id'] && $item['saving'] >= 0, 'cheapest reply is the best offer');

    // Rozstrzygnięcie : cena A staje się aktualna, B odrzucona, wynik e-mailem
    $result = $tenders->award($tenderId, [$item['id'] => $best['id']], true);
    expectPurch($result['applied'] === [$material['name']] && $result['notified'] === 2, 'award applied and result e-mails queued');
    $after = $pdo->query("SELECT price_per_unit, id_current_supplier FROM raw_material WHERE id = {$materialId}")->fetch(PDO::FETCH_ASSOC);
    expectPurch((int) $after['id_current_supplier'] === $supplierIds[0] && abs((float) $after['price_per_unit'] * 1000 - $currentKg * 0.9) < 0.05, 'material price and supplier updated');
    $statuses = $pdo->query("SELECT id_supplier, status FROM tfb_mp_offer WHERE id_tender = {$tenderId}")->fetchAll(PDO::FETCH_KEY_PAIR);
    expectPurch($statuses[$supplierIds[0]] === 'accepted' && $statuses[$supplierIds[1]] === 'rejected', 'winner accepted, other refused');
    expectPurch($pdo->query("SELECT status FROM tfb_tender WHERE id = {$tenderId}")->fetchColumn() === 'awarded', 'tender closed');
    $awardMails = $pdo->query("SELECT body_text FROM communication_message WHERE id > {$maxMessage} AND message_type = 'tender_award' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    expectPurch(count($awardMails) === 2 && str_contains(implode("\n", $awardMails), 'Wybraliśmy Państwa ofertę') && str_contains(implode("\n", $awardMails), 'nie wybraliśmy'), 'winner and loser get their own result');

    // --- NC
    $nc = $container->get(NcService::class);
    $errors = apiFields(fn() => $nc->save(['severity' => 'huge'], null, $userId));
    expectPurch(in_array('source', $errors, true) && in_array('description', $errors, true) && in_array('severity', $errors, true), 'NC: required fields and choices checked');
    $ncId = $nc->save(['source' => 'supplier_delivery', 'supplier_id' => $supplierIds[0], 'material_id' => $materialId, 'description' => 'TEST: uszkodzone worki', 'quantity' => '12.5', 'unit' => 'kg'], null, $userId);
    $row = $pdo->query("SELECT number, status, closed_at FROM tfb_nc WHERE id = {$ncId}")->fetch(PDO::FETCH_ASSOC);
    expectPurch(preg_match('/^NC-\d{4}-\d{3}$/', $row['number']) === 1 && $row['status'] === 'open' && $row['closed_at'] === null, 'NC numbered and open');
    $nc->save(['status' => 'closed', 'root_cause' => 'Transport', 'corrective_action' => 'Palety foliowane'], $ncId, $userId);
    expectPurch($pdo->query("SELECT closed_at FROM tfb_nc WHERE id = {$ncId}")->fetchColumn() !== null, 'closing sets the date');
    $nc->save(['status' => 'in_progress'], $ncId, $userId);
    expectPurch($pdo->query("SELECT closed_at FROM tfb_nc WHERE id = {$ncId}")->fetchColumn() === null, 'reopening clears it');

    // --- Zadania stanowisk : okresy
    $tasks = $container->get(PositionTaskService::class);
    $wed = new DateTimeImmutable('2026-10-21');
    expectPurch($tasks->period('daily', $wed) === ['key' => '2026-10-21', 'due' => '2026-10-21'], 'daily on a workday');
    expectPurch($tasks->period('daily', new DateTimeImmutable('2026-10-24')) === null, 'no daily task on Saturday (default workdays)');
    expectPurch($tasks->period('weekly', $wed) === ['key' => '2026-W43', 'due' => '2026-10-23'], 'weekly: ISO week, due Friday');
    expectPurch($tasks->period('weekly', new DateTimeImmutable('2027-01-01')) === ['key' => '2026-W53', 'due' => '2027-01-01'], 'ISO week year at new year');
    expectPurch($tasks->period('monthly', $wed) === ['key' => '2026-10', 'due' => '2026-10-31'], 'monthly: end of month');
    expectPurch($tasks->period('quarterly', $wed) === ['key' => '2026-Q4', 'due' => '2026-12-31'], 'quarterly: end of quarter');
    expectPurch($tasks->period('quarterly', new DateTimeImmutable('2026-02-10')) === ['key' => '2026-Q1', 'due' => '2026-03-31'], 'Q1 ends 31.03');

    // Moje zadania : stanowisko Zakupy, wtorek → środa (zaległe), zrobione, pominięte z powodem
    $positionId = (int) $pdo->query("SELECT id FROM tfb_position WHERE code = 'ZAKUPY'")->fetchColumn();
    $tasks->assign($userId, ['position_id' => $positionId]);
    $tue = $tasks->myTasks($userId, new DateTimeImmutable('2026-10-20'));
    expectPurch(array_keys($tue['groups']) === ['daily', 'weekly', 'monthly', 'quarterly'], 'tasks grouped by frequency');
    $daily = $tue['groups']['daily']['items'][0]['occurrence'];
    expectPurch($daily['period_key'] === '2026-10-20' && $daily['status'] === 'open', 'daily occurrence created on first view');
    $wedBoard = $tasks->myTasks($userId, $wed);
    expectPurch(count($wedBoard['overdue']) === 1 && $wedBoard['overdue'][0]['period_key'] === '2026-10-20', "yesterday's open task is overdue");
    $tasks->setStatus((int) $daily['id'], ['status' => 'done'], $userId);
    expectPurch($tasks->myTasks($userId, $wed)['overdue'] === [], 'done → no longer overdue');
    $weekly = $wedBoard['groups']['weekly']['items'][0]['occurrence'];
    $needsNote = false;
    try { $tasks->setStatus((int) $weekly['id'], ['status' => 'skipped'], $userId); } catch (CustomException) { $needsNote = true; }
    expectPurch($needsNote, 'skipping needs a reason');
    $tasks->setStatus((int) $weekly['id'], ['status' => 'skipped', 'note' => 'Brak ofert w tym tygodniu'], $userId);
    $sheet = $tasks->userSheet($userId, $wed);
    expectPurch(count($sheet['history']) >= 2 && $sheet['user']['position_name'] === 'Specjalista ds. zakupów', 'user sheet shows position and history');

    echo "purchasing_test: OK\n";
} finally {
    // Przywrócenie : ceny, historia, koszty receptur, dane testowe, wiadomości w kolejce.
    $pdo->prepare('UPDATE raw_material SET price_per_unit = ?, id_current_supplier = ?, package_size_in_base_unit = ? WHERE id = ?')
        ->execute([$material['price_per_unit'], $material['id_current_supplier'], $material['package_size_in_base_unit'], $materialId]);
    $container->get(RecipeCostRecalculationService::class)->recalculateForMaterial($materialId);
    if ($tenderId) $pdo->exec("DELETE FROM tfb_tender WHERE id = {$tenderId}");
    if ($supplierIds) {
        $in = implode(',', $supplierIds);
        $pdo->exec("DELETE FROM raw_material_price_changelog WHERE id_supplier IN ({$in})");
        $pdo->exec("DELETE FROM tfb_mp_offer WHERE id_supplier IN ({$in})");
        $pdo->exec("DELETE FROM tfb_nc WHERE id_supplier IN ({$in})");
        $pdo->exec("DELETE FROM supplier WHERE id IN ({$in})");
    }
    foreach (['communication_message_attachment', 'communication_message_attempt'] as $table) {
        $pdo->exec("DELETE FROM {$table} WHERE id_message > {$maxMessage}");
    }
    $pdo->exec("DELETE FROM communication_message WHERE id > {$maxMessage}");
    $pdo->exec("DELETE FROM tfb_task_occurrence WHERE id > {$maxOccurrence}");
    $pdo->exec("DELETE FROM tfb_user_position WHERE id_user = {$userId}");
    if ($userPosition) {
        $pdo->prepare('INSERT INTO tfb_user_position (id_user, id_position, since) VALUES (?, ?, ?)')->execute([$userId, $userPosition['id_position'], $userPosition['since']]);
    }
}
