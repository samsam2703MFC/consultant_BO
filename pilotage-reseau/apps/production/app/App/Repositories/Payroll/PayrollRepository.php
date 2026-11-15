<?php
declare(strict_types=1);

namespace App\Repositories\Payroll;

use App\Repositories\Workshop\WorkshopRepository;

/** [277] Płace : barèmes (n_pay_rate), mois (n_pay_run), lignes figées (n_pay_line), saisies (n_pay_adjust). */
final class PayrollRepository extends WorkshopRepository
{
    protected string $table = 'n_pay_run';

    /** Employés actifs ou partis dans le mois, avec la fiche HR (champs chiffrés tels quels). */
    public function employees(string $from, string $to): array
    {
        return $this->all(
            "SELECT e.id, e.active, TRIM(CONCAT(pe.name, ' ', pe.surname)) AS name, pe.surname, g.name_pl AS group_name, p.name_pl AS position,
                    h.birth_enc, h.gender, h.hire_date, h.contract_type, h.contract_end, h.work_time, h.pay_type, h.pay_amount, h.bank_enc,
                    h.address_enc, h.pesel_enc, h.pit2, h.kup_raised, h.young_relief, h.student, h.sick_insurance
             FROM n_prod_employee e JOIN production_employee pe ON pe.id = e.id_production_employee
             LEFT JOIN n_prod_group g ON g.id = e.id_primary_group
             LEFT JOIN n_position p ON p.id = e.id_position
             LEFT JOIN n_hr_employee h ON h.id_employee = e.id
             WHERE (e.active = 1 OR (h.contract_end IS NOT NULL AND h.contract_end >= ?))
               AND (h.hire_date IS NULL OR h.hire_date <= ?)
             ORDER BY g.sort_order IS NULL, g.sort_order, pe.surname, pe.name",
            [$from, $to]
        );
    }

    // --- barèmes

    /** @return array<string, array{value: float, source: ?string}> */
    public function rates(int $year): array
    {
        $out = [];
        foreach ($this->all('SELECT code, value, source FROM n_pay_rate WHERE year = ?', [$year]) as $r) $out[$r['code']] = ['value' => (float) $r['value'], 'source' => $r['source']];
        return $out;
    }

    public function latestRateYear(int $atMost): ?int
    {
        $y = $this->all('SELECT MAX(year) AS y FROM n_pay_rate WHERE year <= ?', [$atMost])[0]['y'] ?? null;
        return $y === null ? null : (int) $y;
    }

    public function saveRate(int $year, string $code, float $value, ?string $source, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_pay_rate (year, code, value, source, updated_by) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE value = VALUES(value), source = VALUES(source), updated_by = VALUES(updated_by)')
            ->execute([$year, $code, $value, $source, $userId]);
    }

    public function copyRates(int $from, int $to, ?int $userId): int
    {
        $st = $this->db->prepare('INSERT IGNORE INTO n_pay_rate (year, code, value, source, updated_by) SELECT ?, code, value, source, ? FROM n_pay_rate WHERE year = ?');
        $st->execute([$to, $userId, $from]);
        return $st->rowCount();
    }

    // --- mois

    public function run(string $month): ?array
    {
        return $this->all('SELECT r.*, u.displayed_name AS approved_name FROM n_pay_run r LEFT JOIN user u ON u.id = r.approved_by WHERE r.month = ?', [$month])[0] ?? null;
    }

    public function laterApproved(string $month): bool
    {
        return (bool) $this->all("SELECT 1 FROM n_pay_run WHERE month > ? AND status = 'approved' LIMIT 1", [$month]);
    }

    public function approve(string $month, array $lines, array $totals, ?int $userId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM n_pay_line WHERE month = ?')->execute([$month]);
            $ins = $this->db->prepare('INSERT INTO n_pay_line (month, id_employee, kind, gross, net, employer_cost, pension_base, tax_base, exempt_revenue, sick_days, detail)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $l) {
                $c = $l['calc'];
                $ins->execute([$month, $l['employee_id'], $c['kind'], $c['gross'], $c['net'], $c['cost'], $c['pension_base'], $c['tax_base'], $c['exempt_revenue'], $c['sick_days'],
                    json_encode($l, JSON_UNESCAPED_UNICODE)]);
            }
            $this->db->prepare("INSERT INTO n_pay_run (month, status, approved_by, approved_at, totals) VALUES (?, 'approved', ?, NOW(), ?)
                                ON DUPLICATE KEY UPDATE status = 'approved', approved_by = VALUES(approved_by), approved_at = NOW(), totals = VALUES(totals), xml_at = NULL, xml_by = NULL")
                ->execute([$month, $userId, json_encode($totals)]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reopen(string $month): void
    {
        $this->db->prepare('DELETE FROM n_pay_line WHERE month = ?')->execute([$month]);
        $this->db->prepare("UPDATE n_pay_run SET status = 'draft', approved_by = NULL, approved_at = NULL, totals = NULL, xml_at = NULL, xml_by = NULL WHERE month = ?")->execute([$month]);
    }

    public function markXml(string $month, string $execDate, ?int $userId): void
    {
        $this->db->prepare('UPDATE n_pay_run SET xml_at = NOW(), xml_by = ?, exec_date = ? WHERE month = ?')->execute([$userId, $execDate, $month]);
    }

    /** @return list<array> lignes figées (detail décodé) */
    public function lines(string $month): array
    {
        return array_map(fn($r) => json_decode($r['detail'], true) ?: [], $this->all('SELECT detail FROM n_pay_line WHERE month = ? ORDER BY id', [$month]));
    }

    /** Cumuls de l'année avant ce mois (lignes approuvées) par personne. @return array<int, array> */
    public function ytd(string $month): array
    {
        $out = [];
        foreach ($this->all('SELECT id_employee, SUM(pension_base) AS pension_base, SUM(tax_base) AS tax_base, SUM(exempt_revenue) AS exempt_revenue, SUM(sick_days) AS sick_days
                             FROM n_pay_line WHERE month >= ? AND month < ? GROUP BY id_employee', [substr($month, 0, 4) . '-01', $month]) as $r) {
            $out[(int) $r['id_employee']] = ['pension_base' => (float) $r['pension_base'], 'tax_base' => (float) $r['tax_base'],
                'exempt_revenue' => (float) $r['exempt_revenue'], 'sick_days' => (int) $r['sick_days']];
        }
        return $out;
    }

    /** Moyenne brute des 12 mois approuvés avant ce mois (podstawa chorobowego). @return array<int, float> */
    public function avgGross(string $month): array
    {
        $from = date('Y-m', strtotime($month . '-01 -12 months'));
        return array_map('floatval', array_column($this->all('SELECT id_employee, AVG(gross) AS g FROM n_pay_line WHERE month >= ? AND month < ? GROUP BY id_employee', [$from, $month]), 'g', 'id_employee'));
    }

    // --- saisies

    /** @return array<int, array> */
    public function adjusts(string $month): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM n_pay_adjust WHERE month = ?', [$month]) as $r) $out[(int) $r['id_employee']] = $r;
        return $out;
    }

    public function saveAdjust(string $month, int $employeeId, array $a, ?int $userId): void
    {
        $this->db->prepare('INSERT INTO n_pay_adjust (month, id_employee, overtime50_h, overtime100_h, bonus, deduction, note, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE overtime50_h = VALUES(overtime50_h), overtime100_h = VALUES(overtime100_h), bonus = VALUES(bonus),
                            deduction = VALUES(deduction), note = VALUES(note), updated_by = VALUES(updated_by)')
            ->execute([$month, $employeeId, $a['overtime50_h'], $a['overtime100_h'], $a['bonus'], $a['deduction'], $a['note'], $userId]);
    }

    // --- Czas pracy : maladie et absences non justifiées du mois

    /** @return array<int, int> personne => jours calendaires de L4 dans le mois */
    public function sickDays(string $from, string $to): array
    {
        $out = [];
        foreach ($this->all("SELECT id_employee, date_from, date_to FROM n_time_absence WHERE type = 'l4' AND date_from <= ? AND date_to >= ?", [$to, $from]) as $r) {
            $a = max($from, $r['date_from']);
            $b = min($to, $r['date_to']);
            $out[(int) $r['id_employee']] = ($out[(int) $r['id_employee']] ?? 0) + (int) ((strtotime($b) - strtotime($a)) / 86400) + 1;
        }
        return $out;
    }

    /** @return array<int, int> personne => jours d'absence non justifiée */
    public function unpaidDays(string $from, string $to): array
    {
        return array_map('intval', array_column($this->all("SELECT id_employee, COUNT(*) AS n FROM n_time_note WHERE kind = 'absence' AND reason = 'nieusprawiedliwiona' AND day BETWEEN ? AND ? GROUP BY id_employee",
            [$from, $to]), 'n', 'id_employee'));
    }

    public function company(): array
    {
        return $this->all('SELECT wz_company_name AS name, wz_company_address_1 AS address_1, wz_company_address_2 AS address_2, wz_company_nip AS nip,
                                  wz_company_bank_1 AS bank, wz_company_swift AS swift FROM erp_company_data WHERE id = 1')[0] ?? [];
    }

    public function log(?int $userId, ?int $employeeId, string $action): void
    {
        $this->db->prepare('INSERT INTO n_hr_log (id_user, id_employee, action) VALUES (?, ?, ?)')->execute([$userId, $employeeId, $action]);
    }
}
