<?php
declare(strict_types=1);

namespace App\Repositories\Ai;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [387] Rutyny poranne AI : przebiegi (n_ai_routine_run) i propozycje (n_ai_routine_proposal). Jedyne tabele, do których
 * rutyny piszą — żadnej tabeli ERP ani wyników asystentów.
 */
final class AiRoutineRepository extends WorkshopRepository
{
    protected string $table = 'n_ai_routine_run';
    protected array $writable = ['code', 'day', 'trigger_kind', 'started_at', 'finished_at', 'status', 'model', 'model_served', 'input_tokens', 'output_tokens',
        'cache_read_tokens', 'cache_write_tokens', 'cost_usd', 'cost_pln', 'signals', 'proposals', 'rejected', 'summary', 'codes_json', 'error', 'id_user'];

    /**
     * Nowy przebieg i jego propozycje ; propozycje « pending » tego asystenta i dnia z wcześniejszych przebiegów są
     * zastępowane (usunięte), gdy $replace. Jedna transakcja.
     * @param list<array{ref:string, decision:string, note:string, reason:string, confidence:string}> $proposals
     */
    public function saveRun(array $run, array $proposals, bool $replace): int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->insert($run);
            if ($replace) {
                $this->db->prepare("DELETE FROM n_ai_routine_proposal WHERE code = ? AND day = ? AND status = 'pending'")->execute([$run['code'], $run['day']]);
            }
            $st = $this->db->prepare('INSERT INTO n_ai_routine_proposal (id_run, code, day, ref, decision, note, reason, confidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($proposals as $p) {
                $st->execute([$id, $run['code'], $run['day'], $p['ref'], $p['decision'], $p['note'] !== '' ? $p['note'] : null, $p['reason'] !== '' ? $p['reason'] : null, $p['confidence']]);
            }
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Koszt i liczba przebiegów w miesiącu (dni od $from do $to włącznie). @return array{pln:float, runs:int} */
    public function monthCost(string $from, string $to): array
    {
        $r = $this->all("SELECT COALESCE(SUM(cost_pln), 0) AS pln, SUM(status IN ('ok','refused','error')) AS runs FROM n_ai_routine_run WHERE day >= ? AND day <= ?", [$from, $to])[0] ?? [];
        return ['pln' => round((float) ($r['pln'] ?? 0), 4), 'runs' => (int) ($r['runs'] ?? 0)];
    }

    /** Statusy przebiegów asystenta w danym dniu (najnowsze pierwsze). @return list<string> */
    public function statuses(string $code, string $day): array
    {
        return array_column($this->all('SELECT status FROM n_ai_routine_run WHERE code = ? AND day = ? ORDER BY id DESC', [$code, $day]), 'status');
    }

    /** Ostatni przebieg asystenta (dowolny status). */
    public function last(string $code): ?array
    {
        return $this->all('SELECT * FROM n_ai_routine_run WHERE code = ? ORDER BY id DESC LIMIT 1', [$code])[0] ?? null;
    }

    /** Ostatni udany przebieg asystenta w dniu (propozycje dnia). */
    public function lastOk(string $code, string $day): ?array
    {
        return $this->all("SELECT * FROM n_ai_routine_run WHERE code = ? AND day = ? AND status = 'ok' ORDER BY id DESC LIMIT 1", [$code, $day])[0] ?? null;
    }

    /** Ostatnie przebiegi (wszystkie asystenty) z licznikami decyzji ludzi. */
    public function recent(int $limit = 8): array
    {
        return $this->all("SELECT r.*, (SELECT COUNT(*) FROM n_ai_routine_proposal p WHERE p.id_run = r.id AND p.status IN ('accepted','edited')) AS taken,
                (SELECT COUNT(*) FROM n_ai_routine_proposal p WHERE p.id_run = r.id AND p.status = 'accepted') AS accepted
            FROM n_ai_routine_run r ORDER BY r.id DESC LIMIT " . max(1, min(50, $limit)));
    }

    /** Propozycje przebiegu. */
    public function proposals(int $runId): array
    {
        return $this->all('SELECT * FROM n_ai_routine_proposal WHERE id_run = ? ORDER BY id', [$runId]);
    }

    /** Propozycja oczekująca dla linii asystenta w dniu. */
    public function pendingFor(string $code, string $day, string $ref): ?array
    {
        return $this->all("SELECT * FROM n_ai_routine_proposal WHERE code = ? AND day = ? AND ref = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$code, $day, $ref])[0] ?? null;
    }

    public function proposal(int $id): ?array
    {
        return $this->all('SELECT * FROM n_ai_routine_proposal WHERE id = ?', [$id])[0] ?? null;
    }

    public function run(int $id): ?array
    {
        return $this->find($id);
    }

    /** Decyzja człowieka o propozycji (tylko z « pending »). */
    public function decide(int $id, string $status, ?string $finalDecision, ?int $userId): bool
    {
        $st = $this->db->prepare("UPDATE n_ai_routine_proposal SET status = ?, final_decision = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'pending'");
        $st->execute([$status, $finalDecision, $userId, $id]);
        return $st->rowCount() > 0;
    }
}
