<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

use App\Exceptions\CustomException;
use App\Repositories\Finances\FxRateRepository;
use App\Repositories\Workshop\ParamRepository;

/**
 * [438] Kursy średnie NBP (tabela A) pour les Różnice kursowe :
 *  - récupération par période (GET {fx.nbp_api_url}/exchangerates/tables/A/{od}/{do}/?format=json, 93 jours au plus par
 *    appel, toutes les devises de la table : celles des clients en font partie) ; 404 = aucune table dans la période ;
 *  - chaque jour passé de la période est noté (table publiée ou non : week-end, jour férié) ; le jour même sans table n'est
 *    pas noté (NBP publie vers midi) ;
 *  - kurs « przed » une date = table du dernier jour ouvré AVANT cette date (jour sans table → jour précédent), avec
 *    demande à NBP pour les jours encore inconnus ;
 *  - tous les jours (Bin/fx_rates_fetch.php) : les 10 derniers jours.
 * L'adresse de l'API est un réglage (n_prod_param fx.nbp_api_url), jamais écrite ici. Les tests injectent un faux client.
 */
final class NbpRateService
{
    public const PARAM_URL = 'fx.nbp_api_url';
    /** Jours examinés avant une date : la plus longue suite sans table NBP (Wielkanoc, Boże Narodzenie) tient dans 7 jours. */
    public const LOOKBACK = 14;
    /** Limite de l'API NBP pour une période. */
    public const MAX_RANGE = 93;

    /** @var array<string, array{has_table: bool, table_no: ?string}> */
    private array $days = [];
    /** @var array<string, array<string, array{mid: float, table_no: string}>> devise → jour → kurs */
    private array $rates = [];
    /** @var array<string, bool> lectures déjà faites (devise|od|do) */
    private array $loaded = [];
    /** @var array<string, bool> périodes déjà demandées à NBP pendant cette requête */
    private array $asked = [];
    private ?string $lastError = null;

    public function __construct(private FxRateRepository $repo, private NbpHttpClient $http, private ParamRepository $params)
    {
    }

    /** Adresse de l'API (réglage) ; null = récupération désactivée. */
    public function baseUrl(): ?string
    {
        $url = trim($this->params->get(self::PARAM_URL, ''));
        return $url === '' ? null : rtrim($url, '/');
    }

    /** Nom d'hôte de l'API pour l'écran (« api.nbp.pl »). */
    public function host(): ?string
    {
        $u = $this->baseUrl();
        return $u === null ? null : (parse_url($u, PHP_URL_HOST) ?: $u);
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastCheck(): ?string
    {
        return $this->repo->lastCheck();
    }

    /**
     * Tables A du $from au $to (au plus aujourd'hui) : cours enregistrés, jours notés.
     * @return array{tables: int, rates: int, days: int}
     */
    public function fetchRange(string $from, string $to, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if ($to > $today) $to = $today;
        $out = ['tables' => 0, 'rates' => 0, 'days' => 0];
        if ($from > $to) return $out;
        $base = $this->baseUrl() ?? throw new CustomException('Brak adresu API NBP (Ustawienia › Parametry : ' . self::PARAM_URL . ').', 'errors', 422);
        for ($start = $from; $start <= $to; $start = self::addDays($end, 1)) {
            $end = min($to, self::addDays($start, self::MAX_RANGE - 1));
            [$code, $body] = $this->http->get($base . '/exchangerates/tables/A/' . $start . '/' . $end . '/?format=json');
            $tables = [];
            if ($code === 200) {
                $json = json_decode($body, true);
                if (!is_array($json)) throw new CustomException('NBP : nieczytelna odpowiedź.', 'errors', 502);
                foreach ($json as $t) {
                    $day = is_array($t) ? (string) ($t['effectiveDate'] ?? '') : '';
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !is_array($t['rates'] ?? null)) continue;
                    $no = mb_substr((string) ($t['no'] ?? ''), 0, 32);
                    foreach ($t['rates'] as $r) {
                        $cur = strtoupper((string) ($r['code'] ?? ''));
                        $mid = $r['mid'] ?? null;
                        if (!preg_match('/^[A-Z]{3}$/', $cur) || !is_numeric($mid) || (float) $mid <= 0) continue;
                        $this->repo->saveRate($cur, $day, (float) $mid, $no);
                        $out['rates']++;
                    }
                    $tables[$day] = $no;
                    $out['tables']++;
                }
            } elseif ($code !== 404) {
                throw new CustomException('NBP niedostępny (HTTP ' . $code . ').', 'errors', 502);
            }
            for ($d = $start; $d <= $end; $d = self::addDays($d, 1)) {
                if (isset($tables[$d])) $this->repo->markDay($d, true, $tables[$d]);
                elseif ($d < $today) $this->repo->markDay($d, false, null);
                else continue;
                $out['days']++;
            }
        }
        $this->days = [];
        $this->rates = [];
        $this->loaded = [];
        return $out;
    }

    /** Cron : les $days derniers jours (tables manquées, jours fériés). */
    public function refresh(?string $today = null, int $days = 10): array
    {
        $today ??= date('Y-m-d');
        return $this->fetchRange(self::addDays($today, -max(1, $days)), $today, $today);
    }

    /**
     * Avant un calcul : demande à NBP les jours encore inconnus du $from au $to (au plus hier). Une panne NBP n'arrête
     * pas l'écran : false et lastError() ; les kursy manquants restent « brak kursu ».
     */
    public function ensure(string $from, string $to, ?string $today = null): bool
    {
        $today ??= date('Y-m-d');
        $to = min($to, self::addDays($today, -1));
        if ($from > $to) return true;
        $known = $this->repo->days($from, $to);
        $missing = [];
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) if (!isset($known[$d])) $missing[] = $d;
        if (!$missing) return true;
        $key = $missing[0] . '|' . end($missing);
        if (isset($this->asked[$key])) return $this->lastError === null;
        $this->asked[$key] = true;
        try {
            $this->fetchRange($missing[0], end($missing), $today);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e instanceof CustomException ? $e->getMessage() : 'NBP niedostępny.';
            return false;
        }
    }

    /**
     * Kurs średni NBP z ostatniego dnia roboczego PRZED $date : jour sans table (week-end, jour férié) → jour précédent.
     * $fetch : demander à NBP les jours inconnus. null = pas de kurs (devise absente de la table A, NBP indisponible).
     * @return array{mid: float, date: string, table_no: string}|null
     */
    public function rateBefore(string $currency, string $date, bool $fetch = true, ?string $today = null): ?array
    {
        $currency = strtoupper($currency);
        $from = self::addDays($date, -self::LOOKBACK);
        $to = self::addDays($date, -1);
        if ($fetch) $this->ensure($from, $to, $today);
        $this->load($currency, $from, $to);
        for ($d = $to; $d >= $from; $d = self::addDays($d, -1)) {
            $st = $this->days[$d] ?? null;
            if ($st === null) return null;           // jour inconnu : on ne devine pas
            if (!$st['has_table']) continue;         // week-end, jour férié : jour précédent
            $r = $this->rates[$currency][$d] ?? null;
            return $r ? ['mid' => $r['mid'], 'date' => $d, 'table_no' => $r['table_no']] : null;
        }
        return null;
    }

    /** Dernières tables de la devise jusqu'au $to (panneau « Kursy NBP »). */
    public function latest(string $currency, string $to, int $limit = 4): array
    {
        return $this->repo->latest(strtoupper($currency), $to, $limit);
    }

    /** « 227/A/NBP/2026 » → « A 227/2026 ». */
    public static function tableLabel(?string $no): string
    {
        if ($no === null || $no === '') return '';
        return preg_match('#^(\d+)/([A-C])/NBP/(\d{4})$#', $no, $m) ? $m[2] . ' ' . $m[1] . '/' . $m[3] : $no;
    }

    public static function addDays(string $day, int $n): string
    {
        return (new \DateTimeImmutable($day))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    private function load(string $currency, string $from, string $to): void
    {
        $key = $currency . '|' . $from . '|' . $to;
        if (isset($this->loaded[$key])) return;
        foreach ($this->repo->days($from, $to) as $d => $st) $this->days[$d] = $st;
        foreach ($this->repo->rates($currency, $from, $to) as $d => $r) $this->rates[$currency][$d] = $r;
        $this->loaded[$key] = true;
    }
}
