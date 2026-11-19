<?php
namespace App\Employee\app\Services\Schedule;



use App\Employee\app\Models\Schedule\ScheduleModel;
use App\Employee\app\Services\Cockpit\CockpitClient;

/**
 * Le planning de la personne (31/10/2026, Sam : « /employee/schedule est faux, il ne relaie pas via les API planning ») :
 * lu au cockpit (GET /ventes/moi/planning), qui le lit à la route du planning du panel, GET /shops/{id}/schedule — la
 * même source que le planning du cockpit. L'app ne lit plus GET /employees/{id}/schedule. Sans réponse du cockpit, la
 * page le dit (« indisponible ») plutôt que de montrer une semaine vide.
 */
class ScheduleService {

    /** La journée que montre la barre de chaque service : de 6 h à 22 h, élargie si un service en sort. */
    private const AXE_DEBUT = 6 * 60;
    private const AXE_FIN = 22 * 60;

    /** Vrai si le cockpit n'a pas répondu à la dernière lecture. */
    private bool $panne = false;

    public function __construct(private CockpitClient $cockpit) {
    }

    /** Les services de la personne connectée, du … au … (aujourd'hui et deux semaines par défaut). @return ScheduleModel[] */
    public function getForMe($dateFrom = null, $dateTo = null): array
    {
        $du = is_string($dateFrom) && $dateFrom !== '' ? $dateFrom : date('Y-m-d');
        $au = is_string($dateTo) && $dateTo !== '' ? $dateTo : date('Y-m-d', strtotime($du . ' +14 days'));
        $r = $this->cockpit->appel('GET', '/ventes/moi/planning?' . http_build_query(['du' => $du, 'au' => $au]));
        if (!$r['ok'] || !is_array($r['data']['services'] ?? null)) { $this->panne = true; return []; }
        return array_map(static fn ($s) => new ScheduleModel((array) $s), $r['data']['services']);
    }

    public function indisponible(): bool
    {
        return $this->panne;
    }

    /**
     * La semaine du planning (24/10/2026, Sam : « améliore la vue du planning ») : du lundi au dimanche de la date
     * donnée, chaque jour et ses services (heures, durée, place sur la journée), le total de la semaine et le prochain
     * service — celui en cours, ou le suivant, cherché jusqu'à deux semaines plus loin si la semaine n'en a plus.
     */
    public function semaine(?string $date = null, ?int $maintenant = null): array
    {
        $maintenant ??= time();
        $t = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? strtotime($date) : false;
        $t = $t ?: $maintenant;
        $lundi = date('Y-m-d', strtotime('-' . ((int) date('N', $t) - 1) . ' days', $t));
        $dimanche = date('Y-m-d', strtotime($lundi . ' +6 days'));
        // La fin de la plage n'est pas sûre d'être incluse par l'API : un jour de plus, filtré ensuite.
        $entrees = $this->getForMe($lundi, date('Y-m-d', strtotime($dimanche . ' +1 day')));
        $semaine = self::construire($entrees, $lundi, $maintenant);
        $aujourdhui = date('Y-m-d', $maintenant);
        if ($semaine['prochain'] === null && $dimanche >= $aujourdhui) {
            $suite = $this->getForMe(max($aujourdhui, date('Y-m-d', strtotime($dimanche . ' +1 day'))), date('Y-m-d', strtotime($aujourdhui . ' +15 days')));
            $semaine['prochain'] = self::prochain(self::services($suite), $maintenant);
        }
        $semaine['indisponible'] = $this->panne;
        return $semaine;
    }

    /** La semaine, sans réseau : ce que la vue montre, à partir des services lus. */
    public static function construire(array $entrees, string $lundi, int $maintenant): array
    {
        $aujourdhui = date('Y-m-d', $maintenant);
        $dimanche = date('Y-m-d', strtotime($lundi . ' +6 days'));
        $services = array_values(array_filter(self::services($entrees), static fn ($s) => $s['date'] >= $lundi && $s['date'] <= $dimanche));
        $bas = self::AXE_DEBUT; $haut = self::AXE_FIN;
        foreach ($services as $s) { $bas = min($bas, $s['de']); $haut = max($haut, $s['de'] + $s['duree']); }
        // Un service de nuit déborde après minuit : la barre s'arrête à minuit, au bord.
        $bas = (int) (floor($bas / 60) * 60); $haut = (int) min(1440, ceil($haut / 60) * 60);
        $shops = array_count_values(array_map(static fn ($s) => (string) $s['shop'], $services));
        arsort($shops);
        $shopPrincipal = (string) (array_key_first($shops) ?? '');
        $jours = [];
        $minutes = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($lundi . ' +' . $i . ' days'));
            $sv = array_values(array_filter($services, static fn ($s) => $s['date'] === $d));
            $jour = ['date' => $d, 'aujourdhui' => $d === $aujourdhui, 'passe' => $d < $aujourdhui, 'services' => [], 'maintenant' => null];
            foreach ($sv as $s) {
                $minutes += $s['duree'];
                $jour['services'][] = $s + [
                    'gauche' => round(100 * ($s['de'] - $bas) / ($haut - $bas), 2),
                    'largeur' => round(min(100 * $s['duree'] / ($haut - $bas), 100 - 100 * ($s['de'] - $bas) / ($haut - $bas)), 2),
                    'autreShop' => count($shops) > 1 && (string) $s['shop'] !== $shopPrincipal,
                ];
            }
            if ($jour['aujourdhui']) {
                $m = (int) date('G', $maintenant) * 60 + (int) date('i', $maintenant);
                $jour['maintenant'] = ($m >= $bas && $m <= $haut) ? round(100 * ($m - $bas) / ($haut - $bas), 2) : null;
            }
            $jours[] = $jour;
        }
        $n = (int) date('N', $maintenant);
        return ['lundi' => $lundi, 'dimanche' => $dimanche, 'numero' => (int) date('W', strtotime($lundi)),
            'courante' => $lundi === date('Y-m-d', strtotime('-' . ($n - 1) . ' days', $maintenant)),
            'prec' => date('Y-m-d', strtotime($lundi . ' -7 days')), 'suiv' => date('Y-m-d', strtotime($lundi . ' +7 days')),
            'jours' => $jours, 'minutes' => $minutes, 'nbServices' => count($services),
            'repos' => count(array_filter($jours, static fn ($j) => $j['services'] === [])),
            'axe' => ['de' => intdiv($bas, 60), 'a' => intdiv($haut, 60)],
            'prochain' => self::prochain($services, $maintenant)];
    }

    /** Les services lus, triés : {date, debut « 07:00 », fin, de (minutes depuis minuit), duree (minutes), shop, shopNom}. */
    private static function services(array $entrees): array
    {
        $out = [];
        foreach ($entrees as $e) {
            $date = substr((string) $e->getWorkDate(), 0, 10);
            $de = self::minutes((string) $e->getStartHour());
            $a = self::minutes((string) $e->getEndHour());
            if ($de === null || $a === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
            // Un service qui passe minuit finit le lendemain.
            $duree = ($a - $de + 1440) % 1440;
            $out[] = ['date' => $date, 'debut' => substr((string) $e->getStartHour(), 0, 5), 'fin' => substr((string) $e->getEndHour(), 0, 5),
                'de' => $de, 'duree' => $duree, 'shop' => $e->getIdShop(), 'shopNom' => (string) ($e->getShopName() ?? '')];
        }
        usort($out, static fn ($x, $y) => [$x['date'], $x['de']] <=> [$y['date'], $y['de']]);
        return $out;
    }

    /** Le service en cours, sinon le prochain : {date, debut, fin, duree, enCours, dans (minutes avant le début), reste (minutes avant la fin)}. */
    private static function prochain(array $services, int $maintenant): ?array
    {
        foreach ($services as $s) {
            $debut = strtotime($s['date'] . ' 00:00') + 60 * $s['de'];
            $fin = $debut + 60 * $s['duree'];
            if ($fin <= $maintenant) { continue; }
            $enCours = $debut <= $maintenant;
            return ['date' => $s['date'], 'debut' => $s['debut'], 'fin' => $s['fin'], 'duree' => $s['duree'], 'enCours' => $enCours,
                'dans' => $enCours ? 0 : (int) ceil(($debut - $maintenant) / 60), 'reste' => (int) ceil(($fin - $maintenant) / 60),
                'fait' => $enCours && $s['duree'] > 0 ? round(100 * ($maintenant - $debut) / (60 * $s['duree']), 1) : 0];
        }
        return null;
    }

    /** « 07:30:00 » → 450 ; null si l'heure est illisible. */
    private static function minutes(string $h): ?int
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', $h, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

}
