<?php
namespace App\Services\Purchasing;

use App\Core\Api\Workshop\Validator;
use App\Core\TransactionManager;
use App\Exceptions\CustomException;
use App\Repositories\Communication\CommunicationTemplateRepository;
use App\Repositories\Purchasing\MaterialCostRepository;
use App\Repositories\Purchasing\TenderRepository;
use App\Repositories\Workshop\ParamRepository;
use App\Services\Communication\OutgoingMessageService;
use App\Services\Communication\TemplateRenderer;

/**
 * Appels d'offres sur une liste réduite de matières : envoi par e-mail aux fournisseurs choisis
 * (modèle tender_request, tableau CSV à compléter en pièce jointe), saisie des réponses comme
 * offres, comparaison, attribution (le prix retenu devient le prix courant) et e-mail de résultat.
 */
final class TenderService
{
    public function __construct(
        private TenderRepository $tenders,
        private MaterialCostRepository $costs,
        private OfferService $offers,
        private CommunicationTemplateRepository $templates,
        private TemplateRenderer $renderer,
        private OutgoingMessageService $outgoing,
        private ParamRepository $params,
        private TransactionManager $tx,
    ) {
    }

    public function list(): array
    {
        return $this->tenders->list();
    }

    public function create(array $input, ?int $userId): int
    {
        $v = (new Validator($input))->only(['title', 'reply_deadline', 'language', 'intro', 'material_ids', 'supplier_ids'])
            ->string('title', 200)
            ->date('reply_deadline', false, true)
            ->choice('language', ['pl', 'fr', 'nl'], false)
            ->string('intro', 2000, false, true);
        $materials = $this->idList($v, 'material_ids', 50);
        $suppliers = $this->idList($v, 'supplier_ids', 30);
        $known = [];
        foreach ($this->costs->suppliers() as $s) $known[(int) $s['id']] = $s;
        foreach ($suppliers as $sid) {
            if (!isset($known[$sid])) $v->add('supplier_ids', 'REFERENCE_NOT_FOUND', ['id' => $sid]);
            elseif (!filter_var(trim((string) $known[$sid]['email']), FILTER_VALIDATE_EMAIL)) $v->add('supplier_ids', 'INVALID_EMAIL', ['id' => $known[$sid]['name']]);
        }
        foreach ($materials as $mid) {
            if ($this->costs->material($mid) === null) $v->add('material_ids', 'REFERENCE_NOT_FOUND', ['id' => $mid]);
        }
        $v->throwIfInvalid();

        $consumption = $this->costs->consumption(max(1, (int) $this->params->get('purchasing.consumption_months', '12')));
        return $this->tx->execute(function () use ($v, $materials, $suppliers, $known, $consumption, $userId) {
            $id = $this->tenders->insert([
                'code' => $this->tenders->nextCode((int) date('Y')),
                'title' => $v->value('title'),
                'reply_deadline' => $v->value('reply_deadline') ?? date('Y-m-d', strtotime('+7 days')),
                'language' => $v->value('language') ?? 'pl',
                'intro' => $v->value('intro'),
                'status' => 'draft',
                'created_by' => $userId,
            ]);
            foreach ($materials as $mid) $this->tenders->addItem($id, $mid, $consumption[$mid] ?? null);
            foreach ($suppliers as $sid) $this->tenders->addSupplier($id, $sid, trim((string) $known[$sid]['email']));
            return $id;
        });
    }

    public function detail(int $id): array
    {
        $tender = $this->load($id);
        $items = [];
        $offersByKey = [];
        foreach ($this->offers->list(null, null, null, $id) as $offer) {
            $offersByKey[$offer['id_raw_material'] . ':' . $offer['id_supplier']] = $offer;
        }
        $suppliers = $this->tenders->suppliers($id);
        foreach ($this->tenders->items($id) as $item) {
            $unit = CostOptimizationService::displayUnit($item['unit']);
            $cells = [];
            $best = null;
            foreach ($suppliers as $s) {
                $offer = $offersByKey[$item['id_raw_material'] . ':' . $s['id_supplier']] ?? null;
                $cells[(int) $s['id_supplier']] = $offer;
                if ($offer && ($best === null || $offer['price_per_base'] < $best['price_per_base'])) $best = $offer;
            }
            $current = (float) $item['current_price'];
            $qty = (float) $item['annual_qty_base'];
            $items[] = $item + [
                'display_unit' => $unit['label'],
                'display_qty' => $qty / $unit['factor'],
                'display_current' => $current * $unit['factor'],
                'cells' => $cells,
                'best_offer_id' => $best ? (int) $best['id'] : null,
                'saving' => $best ? max(0, ($current - (float) $best['price_per_base']) * $qty) : 0.0,
            ];
        }
        return ['tender' => $tender, 'items' => $items, 'suppliers' => $suppliers];
    }

    /** Texte de l'e-mail pour un fournisseur (sans rien envoyer). */
    public function preview(int $id): array
    {
        $tender = $this->load($id);
        $template = $this->template($tender['language'], 'tender_request');
        $variables = $this->variables($tender, $this->tenders->items($id));
        return [
            'subject' => $this->renderer->render($template['subject_template'], $variables),
            'body' => $this->renderer->render($template['body_template'], $variables),
            'recipients' => array_column($this->tenders->suppliers($id), 'email'),
        ];
    }

    /** Met en file un e-mail par fournisseur pas encore invité. */
    public function send(int $id): int
    {
        $tender = $this->load($id);
        if (!in_array($tender['status'], ['draft', 'sent'], true)) {
            throw new CustomException('To zapytanie jest już zamknięte.', 'errors', 409);
        }
        $items = $this->tenders->items($id);
        if (!$items) throw new CustomException('Zapytanie nie zawiera surowców.', 'errors', 422);
        $template = $this->template($tender['language'], 'tender_request');
        $variables = $this->variables($tender, $items);
        $subject = $this->renderer->render($template['subject_template'], $variables);
        $body = $this->renderer->render($template['body_template'], $variables);
        $csv = $this->itemsCsv($tender, $items);

        return $this->tx->execute(function () use ($id, $tender, $template, $variables, $subject, $body, $csv) {
            $sent = 0;
            foreach ($this->tenders->suppliers($id) as $s) {
                if ($s['id_message']) continue;
                $messageId = $this->outgoing->queue([
                    'message_type' => 'tender_request',
                    'template' => $template,
                    'recipient' => $s['email'],
                    'subject' => $subject,
                    'body' => $body,
                    'variables' => $variables,
                    'created_by' => 'tender:' . $tender['code'],
                ], [['filename' => $tender['code'] . '.csv', 'mime_type' => 'text/csv', 'content' => $csv]]);
                $this->tenders->markInvited((int) $s['id'], $messageId);
                $sent++;
            }
            $this->tenders->update($id, ['status' => 'sent', 'sent_at' => $tender['sent_at'] ?? date('Y-m-d H:i:s')]);
            return $sent;
        });
    }

    /** Réponse d'un fournisseur : une offre par matière renseignée. */
    public function recordReply(int $id, int $supplierId, array $rows, ?int $userId): int
    {
        $tender = $this->load($id);
        $invited = array_filter($this->tenders->suppliers($id), fn($s) => (int) $s['id_supplier'] === $supplierId);
        if (!$invited) throw new CustomException('Ten dostawca nie jest zaproszony do zapytania.', 'errors', 422);
        $materials = array_map('intval', array_column($this->tenders->items($id), 'id_raw_material'));
        return $this->tx->execute(function () use ($id, $tender, $supplierId, $rows, $materials, $userId) {
            $count = 0;
            foreach ($rows as $row) {
                if (!is_array($row) || ($row['pack_price'] ?? '') === '' || !in_array((int) ($row['material_id'] ?? 0), $materials, true)) continue;
                $this->offers->add([
                    'supplier_id' => $supplierId,
                    'material_id' => (int) $row['material_id'],
                    'pack_price' => $row['pack_price'],
                    'pack_size' => $row['pack_size'] ?? null,
                    'min_order_qty' => ($row['min_order_qty'] ?? '') === '' ? null : $row['min_order_qty'],
                    'lead_time_days' => ($row['lead_time_days'] ?? '') === '' ? null : $row['lead_time_days'],
                    'valid_to' => ($row['valid_to'] ?? '') === '' ? null : $row['valid_to'],
                    'notes' => 'Odpowiedź na ' . $tender['code'],
                ], $userId, $id);
                $count++;
            }
            if ($count > 0) $this->tenders->markResponded($id, $supplierId);
            return $count;
        });
    }

    /**
     * Attribution : $awards = [id ligne => id offre | null]. Chaque offre retenue devient le prix
     * courant ; les autres offres de l'appel pour ces matières sont refusées. Optionnellement,
     * un e-mail de résultat part à chaque fournisseur invité.
     */
    public function award(int $id, array $awards, bool $notify): array
    {
        $tender = $this->load($id);
        $detail = $this->detail($id);
        $applied = [];
        $won = [];
        foreach ($detail['items'] as $item) {
            $offerId = $awards[$item['id']] ?? null;
            if ($offerId === null || $offerId === '') continue;
            $offer = null;
            foreach ($item['cells'] as $cell) if ($cell && (int) $cell['id'] === (int) $offerId) $offer = $cell;
            if ($offer === null) throw new CustomException('Oferta nie należy do tej pozycji zapytania.', 'errors', 422);
            if ($offer['status'] === 'received') $this->offers->apply((int) $offer['id']);
            $this->tenders->award((int) $item['id'], (int) $offer['id']);
            foreach ($item['cells'] as $cell) {
                if ($cell && (int) $cell['id'] !== (int) $offer['id'] && $cell['status'] === 'received') $this->offers->reject((int) $cell['id']);
            }
            $applied[] = $item['material_name'];
            $won[(int) $offer['id_supplier']][] = $item['material_name'];
        }
        $this->tenders->update($id, ['status' => 'awarded', 'closed_at' => date('Y-m-d H:i:s')]);

        $notified = 0;
        if ($notify) {
            $template = $this->template($tender['language'], 'tender_award');
            $pl = $tender['language'] === 'pl';
            foreach ($detail['suppliers'] as $s) {
                $items = $won[(int) $s['id_supplier']] ?? [];
                $result = $items
                    ? ($pl ? "Wybraliśmy Państwa ofertę dla: " : "Nous avons retenu votre offre pour : ") . implode(', ', $items) . '.'
                    : ($pl ? 'Tym razem nie wybraliśmy Państwa oferty. Dziękujemy za udział.' : 'Votre offre n\'a pas été retenue cette fois. Merci de votre participation.');
                $variables = $this->variables($tender, $this->tenders->items($id)) + [];
                $variables['tender']['result'] = $result;
                $this->outgoing->queue([
                    'message_type' => 'tender_award',
                    'template' => $template,
                    'recipient' => $s['email'],
                    'subject' => $this->renderer->render($template['subject_template'], $variables),
                    'body' => $this->renderer->render($template['body_template'], $variables),
                    'variables' => $variables,
                    'created_by' => 'tender:' . $tender['code'],
                ]);
                $notified++;
            }
        }
        return ['applied' => $applied, 'notified' => $notified];
    }

    private function variables(array $tender, array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $unit = CostOptimizationService::displayUnit($item['unit']);
            $name = $tender['language'] === 'pl' ? $item['material_name'] : ($item['material_name_fr'] ?: $item['material_name']);
            $qty = (float) $item['annual_qty_base'] / $unit['factor'];
            $lines[] = '- ' . $name . ($qty > 0 ? ' — ok. ' . number_format($qty, 0, ',', ' ') . ' ' . $unit['label'] . ($tender['language'] === 'pl' ? '/rok' : '/an') : '');
        }
        return [
            'tender' => [
                'code' => $tender['code'],
                'title' => $tender['title'],
                'deadline' => $tender['reply_deadline'] ? date('d.m.Y', strtotime($tender['reply_deadline'])) : '',
                'intro' => (string) ($tender['intro'] ?? ''),
                'items' => implode("\n", $lines),
                'result' => '',
            ],
            'company' => ['name' => $this->costs->companyName()],
        ];
    }

    /** Tableau à compléter par le fournisseur (séparateur ; pour Excel). */
    private function itemsCsv(array $tender, array $items): string
    {
        $pl = $tender['language'] === 'pl';
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $pl
            ? ['Kod', 'Surowiec', 'Jednostka', 'Zapotrzebowanie roczne', 'Cena za opakowanie', 'Wielkość opakowania', 'Min. zamówienie', 'Termin dostawy (dni)']
            : ['Code', 'Matière', 'Unité', 'Besoin annuel', 'Prix par conditionnement', 'Taille du conditionnement', 'Commande minimale', 'Délai (jours)'], ';');
        foreach ($items as $item) {
            $unit = CostOptimizationService::displayUnit($item['unit']);
            fputcsv($out, [
                $item['id_raw_material'],
                $pl ? $item['material_name'] : ($item['material_name_fr'] ?: $item['material_name']),
                $unit['label'],
                number_format((float) $item['annual_qty_base'] / $unit['factor'], 0, ',', ''),
                '', '', '', '',
            ], ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private function template(string $language, string $type): array
    {
        $template = $this->templates->getDefault($type, $language) ?: $this->templates->getDefault($type, 'pl');
        if (!$template) throw new CustomException('Brak szablonu e-mail „' . $type . '”.', 'errors', 422);
        return $template;
    }

    private function idList(Validator $v, string $field, int $max): array
    {
        $raw = $v->raw($field);
        if (!is_array($raw) || $raw === []) {
            $v->add($field, 'REQUIRED');
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($raw, fn($x) => is_numeric($x)))));
        if (count($ids) > $max) $v->add($field, 'TOO_LONG', ['max' => $max]);
        return array_slice($ids, 0, $max);
    }

    private function load(int $id): array
    {
        return $this->tenders->find($id) ?? throw new CustomException('Nie znaleziono zapytania ofertowego.', 'errors', 404);
    }
}
