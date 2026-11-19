<?php
declare(strict_types=1);

namespace App\Services\Logistics;

use App\Exceptions\CustomException;
use App\Repositories\Logistics\LogisticsRepository;
use App\Services\Hr\ContractTemplateService;

/**
 * [309] Kierowcy w Logistyce (makieta 57) : etat (umowa o pracę — karta HR jak każdy pracownik) albo zlecenie (tylko dane
 * do umowy). Dla kierowcy na zlecenie Logistyka przygotowuje « Umowę zlecenia na przewóz towaru » (szablon HR
 * zlecenie_transport) z danymi kierowcy, pojazdem, trasą i wynagrodzeniem za przewóz : DOCX albo PDF w karcie HR kierowcy.
 * Rodzaj współpracy = karta HR (contract_type 'zlecenie' = zlecenie, inna umowa = etat).
 */
final class DriverContractService
{
    public const TEMPLATE = 'zlecenie_transport';
    public const PAY = ['kurs' => 'za kurs', 'godzina' => 'za godzinę', 'km' => 'za km', 'ryczalt' => 'ryczałt miesięczny'];

    public function __construct(private LogisticsRepository $repo, private ContractTemplateService $templates)
    {
    }

    /** Kierowcy (stanowisko, pojazd, trasa albo dane transportowe) : współpraca, pojazdy, trasy, umowa. */
    public function drivers(): array
    {
        return array_map(function (array $r) {
            $coop = $r['contract_type'] === 'zlecenie' ? 'zlecenie' : ($r['contract_type'] !== null ? 'etat' : null);
            return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'cooperation' => $coop, 'licence' => $r['licence'],
                'pay' => $this->payText($r['pay_basis'], $r['pay_amount']), 'vehicles' => $r['vehicles'] ? explode('|', (string) $r['vehicles']) : [],
                'tours' => $r['tours'] ? explode('|', (string) $r['tours']) : [], 'contract_from' => $r['hire_date'], 'contract_to' => $r['contract_end'],
                'contract_doc' => $r['contract_doc'], 'contract_ending' => $coop === 'zlecenie' && $r['contract_end'] !== null && $r['contract_end'] <= date('Y-m-d', strtotime('+14 days'))];
        }, $this->repo->driversOverview());
    }

    /** Dane transportowe kierowcy (prawo jazdy, wynagrodzenie za przewóz). */
    public function saveDriverData(int $employeeId, ?string $licence, ?string $basis, ?float $amount, ?int $userId): void
    {
        if ($basis !== null && !isset(self::PAY[$basis])) throw new CustomException('Wynagrodzenie : za kurs, za godzinę, za km albo ryczałt.', 'errors', 422);
        $this->repo->saveDriverData($employeeId, $licence !== null ? (mb_substr(trim($licence), 0, 20) ?: null) : null, $basis, $amount, $userId);
    }

    /** @return array{filename: string, content: string} */
    public function docx(int $employeeId, ?int $viewer): array
    {
        return $this->templates->docx(self::TEMPLATE, $this->driverOrFail($employeeId), $this->extra($employeeId), $viewer);
    }

    /** PDF w dokumentach kierowcy (karta HR, zaszyfrowany). */
    public function savePdf(int $employeeId, ?int $viewer): array
    {
        return $this->templates->savePdf(self::TEMPLATE, $this->driverOrFail($employeeId), $this->extra($employeeId), $viewer);
    }

    /** Pola umowy z Logistyki : prawo jazdy, pojazd, trasa (kierowcy albo pojazdu), wynagrodzenie za przewóz. */
    public function extra(int $employeeId): array
    {
        $d = null;
        foreach ($this->repo->driversOverview() as $r) if ((int) $r['id'] === $employeeId) $d = $r;
        if ($d === null) return [];
        $vehicles = $d['vehicles'] ? explode('|', (string) $d['vehicles']) : [];
        $tours = $d['tours'] ? explode('|', (string) $d['tours']) : [];
        if (!$tours && $d['vehicle_tours']) $tours = explode('|', (string) $d['vehicle_tours']);
        return ['licence' => $d['licence'], 'transport_pay' => $this->payText($d['pay_basis'], $d['pay_amount']),
            'vehicle' => $vehicles ? implode(', ', $vehicles) : 'wskazanym przez Zleceniodawcę',
            'route' => $tours ? implode(', ', $tours) : 'według planu tras Zleceniodawcy'];
    }

    private function payText(?string $basis, $amount): ?string
    {
        if ($basis === null || $amount === null || !isset(self::PAY[$basis])) return null;
        return number_format((float) $amount, 2, ',', ' ') . ' zł brutto ' . self::PAY[$basis];
    }

    private function driverOrFail(int $employeeId): int
    {
        foreach ($this->drivers() as $d) {
            if ($d['id'] !== $employeeId) continue;
            if ($d['cooperation'] !== 'zlecenie') throw new CustomException('Umowa zlecenia na przewóz : tylko kierowca na zlecenie (kierowca etatowy ma umowę o pracę w karcie HR).', 'errors', 422);
            return $employeeId;
        }
        throw new CustomException('Nie ma takiego kierowcy.', 'errors', 404);
    }
}
