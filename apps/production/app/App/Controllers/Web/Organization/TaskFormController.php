<?php
namespace App\Controllers\Web\Organization;

use App\Core\Web\BaseController;
use App\Core\Web\JsonErrors;
use App\Services\Organization\TaskFormService;
use App\Services\Workshop\Execution\ProductionFollowUpService;
use Symfony\Component\HttpFoundation\Request;

/** Zadania z formularzem (protokoły zebrań), lista i podgląd protokołów, śledzenie produkcji. */
class TaskFormController extends BaseController
{
    use JsonErrors;

    public const WASTE_REASONS = ['quality' => 'Jakość / wygląd', 'burnt' => 'Przypalone', 'damaged' => 'Uszkodzone', 'samples' => 'Próbki / testy', 'other' => 'Inne'];

    public function __construct(private TaskFormService $forms, private ProductionFollowUpService $followUp)
    {
    }

    /** Formularz okresu zadania (okres śledzenia produkcji z ?from=&to=). */
    public function form($id)
    {
        $this->view('organization/task_form', $this->forms->open((int) $id, $_GET['from'] ?? null, $_GET['to'] ?? null) + [
            'waste_reasons' => self::WASTE_REASONS,
        ]);
    }

    public function save($id)
    {
        try {
            $r = $this->forms->save((int) $id, $this->getJson(Request::createFromGlobals()), $this->userId());
            return $this->json(['message' => $r['final'] ? 'Protokół zakończony — zadanie zrobione.' : 'Zapisano szkic protokołu.', 'id' => $r['saved_id'], 'final' => $r['final']]);
        } catch (\Throwable $e) {
            return $this->jsonError($e);
        }
    }

    public function list()
    {
        $this->view('organization/task_forms', ['forms' => $this->forms->list()]);
    }

    public function show($id)
    {
        $this->view('organization/task_form_view', $this->forms->view((int) $id) + ['waste_reasons' => self::WASTE_REASONS]);
    }

    /** Śledzenie produkcji : plan, zrobione, straty, zatwierdzone dni, niezgodności (okres z ?from=&to=). */
    public function followUp()
    {
        $this->view('production/follow_up', [
            'f' => $this->followUp->summary($_GET['from'] ?? null, $_GET['to'] ?? null),
            'waste_reasons' => self::WASTE_REASONS,
            'presets' => $this->presets(),
        ]);
    }

    /** Szybkie okresy : ten tydzień, poprzedni tydzień, ten miesiąc, ostatnie 30 dni. */
    private function presets(): array
    {
        $today = date('Y-m-d');
        $monday = date('Y-m-d', strtotime('monday this week'));
        return [
            ['label' => 'Ten tydzień', 'from' => $monday, 'to' => $today],
            ['label' => 'Poprzedni tydzień', 'from' => date('Y-m-d', strtotime($monday . ' -7 days')), 'to' => date('Y-m-d', strtotime($monday . ' -1 day'))],
            ['label' => 'Ten miesiąc', 'from' => date('Y-m-01'), 'to' => $today],
            ['label' => 'Ostatnie 30 dni', 'from' => date('Y-m-d', strtotime('-29 days')), 'to' => $today],
        ];
    }

    private function userId(): ?int
    {
        $id = user('id');
        return $id ? (int) $id : null;
    }
}
