<?php
namespace App\Repositories\Organization;

use App\Repositories\Workshop\WorkshopRepository;

/**
 * [368] Matryca obowiązków (n_position_duty) : każdy obowiązek z zakresu prac i obowiązków → stanowisko (właściciel) →
 * asystent kontroli (TaskWizards::CATALOG) albo asystent proponowany « do utworzenia », rytm.
 */
final class PositionDutyRepository extends WorkshopRepository
{
    protected string $table = 'n_position_duty';
    protected array $writable = ['id_position', 'number', 'area_no', 'area', 'text_pl', 'text_fr', 'wizard_code', 'proposed_wizard', 'rhythm', 'source', 'sort_order', 'active', 'updated_by'];

    /** Obowiązki aktywne z nazwą stanowiska, w kolejności stanowisk i numerów. */
    public function duties(): array
    {
        return $this->all('SELECT d.*, p.name_pl AS position_name, p.code AS position_code, p.active AS position_active
            FROM n_position_duty d JOIN n_position p ON p.id = d.id_position
            WHERE d.active = 1 ORDER BY p.sort_order, p.name_pl, d.id_position, d.sort_order, d.id');
    }

    /** id_position → {duties, covered, proposed} (obowiązki aktywne). */
    public function stats(): array
    {
        $out = [];
        foreach ($this->all('SELECT id_position, COUNT(*) AS duties, SUM(wizard_code IS NOT NULL) AS covered,
                SUM(wizard_code IS NULL AND proposed_wizard IS NOT NULL) AS proposed FROM n_position_duty WHERE active = 1 GROUP BY id_position') as $r) {
            $out[(int) $r['id_position']] = ['duties' => (int) $r['duties'], 'covered' => (int) $r['covered'], 'proposed' => (int) $r['proposed']];
        }
        return $out;
    }

    /** Ostatnie wykonanie każdego asystenta (n_wizard_done) : kod → dzień. */
    public function lastDone(): array
    {
        return array_column($this->all('SELECT code, MAX(day) AS day FROM n_wizard_done GROUP BY code'), 'day', 'code');
    }

    /** Zadania stanowisk zamykane przez asystenta : « id_position:kod » → true (auto_code albo link asystenta). */
    public function positionTasks(): array
    {
        return $this->all('SELECT id_position, auto_code, link_path FROM n_position_task WHERE active = 1 AND (auto_code IS NOT NULL OR link_path IS NOT NULL)');
    }

    public function nextSort(int $positionId): int
    {
        return (int) ($this->all('SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM n_position_duty WHERE id_position = ?', [$positionId])[0]['s'] ?? 10);
    }

    public function hasDuties(int $positionId): bool
    {
        return (bool) $this->all('SELECT 1 AS x FROM n_position_duty WHERE id_position = ? AND active = 1 LIMIT 1', [$positionId]);
    }
}
