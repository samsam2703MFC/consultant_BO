<?php
declare(strict_types=1);

namespace App\Services\Organization;

use App\Core\Web\PageTabs;
use App\Core\Web\Rail;

/**
 * [422] Feuille de fonction : un bloc par tâche du poste pour la karta stanowiska (écran et PDF) — titre, rythme · durée,
 * Kiedy (fréquence, jour de la semaine, jours, jour ouvré du mois, heure limite), Gdzie (lien de la tâche → chemin du
 * menu de l'ERP, sinon l'adresse ; tablettes : TaskHowTo), Jak (étapes : TaskHowTo pour les assistants, how_pl / how_fr
 * pour les tâches manuelles, sinon les étapes de l'assistant générique wizard_steps), Gotowe, gdy (règle de validation
 * automatique de l'assistant, sinon « odhaczone w Moje zadania ») et Problem ?. Pur calcul, sans base : chaque texte en
 * [PL, FR].
 */
final class TaskSheet
{
    public const FREQUENCIES = [
        'daily' => ['codziennie', 'chaque jour'], 'weekly' => ['co tydzień', 'chaque semaine'],
        'monthly' => ['co miesiąc', 'chaque mois'], 'quarterly' => ['co kwartał', 'chaque trimestre'],
    ];
    private const DAY_ON = [
        1 => ['w poniedziałek', 'le lundi'], 2 => ['we wtorek', 'le mardi'], 3 => ['w środę', 'le mercredi'], 4 => ['w czwartek', 'le jeudi'],
        5 => ['w piątek', 'le vendredi'], 6 => ['w sobotę', 'le samedi'], 7 => ['w niedzielę', 'le dimanche'],
    ];
    private const DAY_ABBR = [
        1 => ['pon.', 'lun.'], 2 => ['wt.', 'mar.'], 3 => ['śr.', 'mer.'], 4 => ['czw.', 'jeu.'], 5 => ['pt.', 'ven.'], 6 => ['sob.', 'sam.'], 7 => ['niedz.', 'dim.'],
    ];
    /** Ligne « Jak » d'une tâche sans étapes écrites. */
    public const NO_STEPS = ['Kroki nie są jeszcze opisane — otwórz stronę z « Gdzie » albo zapytaj przełożonego.',
        'Étapes pas encore décrites — ouvrez la page indiquée dans « Gdzie » ou demandez à votre responsable.'];

    /**
     * @param list<array> $tasks lignes n_position_task (actives)
     * @param ?array{0:string,1:string} $boss nom du poste supérieur [PL, FR] (ligne « Problem ? » par défaut)
     * @param bool $atelier poste de l'atelier (personnes sans compte ERP : tâche manuelle faite au poste, vue par le chef)
     * @return list<array>
     */
    public static function blocks(array $tasks, ?array $boss = null, bool $atelier = false): array
    {
        return array_values(array_map(fn(array $t) => self::block($t, $boss, $atelier), $tasks));
    }

    public static function block(array $t, ?array $boss = null, bool $atelier = false): array
    {
        $code = ($t['auto_code'] ?? null) !== null && isset(TaskWizards::CATALOG[$t['auto_code']]) ? (string) $t['auto_code'] : null;
        $howTo = TaskHowTo::for($code);
        $titlePl = (string) $t['title_pl'];
        $titleFr = (string) (($t['title_fr'] ?? '') !== '' ? $t['title_fr'] : $titlePl);

        if ($howTo !== null) {
            $steps = ['pl' => $howTo['pl'], 'fr' => $howTo['fr']];
            $source = 'assistant';
        } else {
            $pl = PositionSheetService::lines((string) ($t['how_pl'] ?? ''));
            $fr = PositionSheetService::lines((string) ($t['how_fr'] ?? ''));
            $source = $pl || $fr ? 'manual' : 'none';
            if ($source === 'none') {
                // Assistant générique déjà configuré (wizard_steps, [280]) : ses titres d'étapes servent de « Jak ».
                $wiz = json_decode((string) ($t['wizard_steps'] ?? ''), true);
                $pl = is_array($wiz) ? array_values(array_filter(array_map(fn($s) => is_array($s) ? trim((string) ($s['title'] ?? '')) : '', $wiz))) : [];
                if ($pl) $source = 'wizard_steps';
            }
            $steps = ['pl' => $pl ?: $fr, 'fr' => $fr ?: $pl];
        }

        $done = self::done($t, $code, $howTo, $atelier);
        $problemPl = trim((string) ($t['problem_pl'] ?? ''));
        $problemFr = trim((string) ($t['problem_fr'] ?? ''));
        if ($problemPl !== '' || $problemFr !== '') {
            $problem = [$problemPl ?: $problemFr, $problemFr ?: $problemPl];
        } elseif ($howTo !== null && $howTo['problem'] !== null) {
            $problem = $howTo['problem'];
        } else {
            $problem = $boss
                ? ['Nie wiesz, co zrobić : zapytaj przełożonego (' . $boss[0] . ').', 'En cas de doute : demandez à votre responsable (' . $boss[1] . ').']
                : ['Nie wiesz, co zrobić : zapytaj przełożonego.', 'En cas de doute : demandez à votre responsable.'];
        }

        $when = self::when($t);
        if ($howTo !== null && $howTo['when'] !== null) {
            $when = [rtrim($when[0], '.') . ' — ' . $howTo['when'][0] . '.', rtrim($when[1], '.') . ' — ' . $howTo['when'][1] . '.'];
        }

        return [
            'id' => isset($t['id']) ? (int) $t['id'] : null,
            'title' => [$titlePl, $titleFr],
            // compatibilité : anciennes clés de sheet()['tasks']
            'title_pl' => $titlePl,
            'title_fr' => $titleFr,
            'frequency' => self::rhythm($t),
            'rhythm' => self::rhythm($t),
            'duration' => self::duration(isset($t['minutes']) ? (int) $t['minutes'] : null),
            'when' => $when,
            'where' => self::whereFor($t, $code !== null, $howTo, $atelier),
            'steps' => $steps,
            'source' => $source,
            'done' => $done,
            'problem' => $problem,
            'auto' => $code !== null,
            'link' => ($t['link_path'] ?? '') !== '' ? (string) $t['link_path'] : null,
        ];
    }

    /** Rythme court de l'en-tête du bloc : « codziennie », « pon., śr. », « co tydzień »… */
    public static function rhythm(array $t): array
    {
        $days = self::days($t['weekdays'] ?? null);
        if (($t['frequency'] ?? '') === 'daily' && $days) {
            return [implode(', ', array_map(fn($d) => self::DAY_ABBR[$d][0], $days)), implode(', ', array_map(fn($d) => self::DAY_ABBR[$d][1], $days))];
        }
        return self::FREQUENCIES[$t['frequency'] ?? ''] ?? [(string) ($t['frequency'] ?? ''), (string) ($t['frequency'] ?? '')];
    }

    /** « 10 min », « 1 h 30 min » ; sans durée : null. */
    public static function duration(?int $minutes): ?string
    {
        if (!$minutes) return null;
        if ($minutes < 60) return $minutes . ' min';
        return intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
    }

    /**
     * Kiedy, depuis le calendrier de la tâche (mêmes règles que PositionTaskService::period) : quotidien = jours ouvrés
     * (ou les jours choisis), hebdomadaire = jour du terme (vendredi par défaut), mensuel / trimestriel = wd3 (3e jour
     * ouvré), d5 (5e jour), sinon dernier jour ; + heure limite.
     */
    public static function when(array $t): array
    {
        $time = !empty($t['due_time']) ? substr((string) $t['due_time'], 0, 5) : null;
        $rule = (string) ($t['month_day'] ?? '');
        $n = preg_match('/^(wd|d)(\d{1,2})$/', $rule, $m) ? (int) $m[2] : null;
        $kind = $n !== null ? $m[1] : null;
        switch ($t['frequency'] ?? '') {
            case 'daily':
                $days = self::days($t['weekdays'] ?? null);
                $out = $days
                    ? ['W dni : ' . implode(', ', array_map(fn($d) => self::DAY_ABBR[$d][0], $days)), 'Les jours : ' . implode(', ', array_map(fn($d) => self::DAY_ABBR[$d][1], $days))]
                    : ['Codziennie, w dni robocze', 'Chaque jour ouvré'];
                break;
            case 'weekly':
                $wd = isset($t['due_weekday']) && (int) $t['due_weekday'] >= 1 && (int) $t['due_weekday'] <= 7 ? (int) $t['due_weekday'] : 5;
                $out = ['Co tydzień ' . self::DAY_ON[$wd][0], 'Chaque semaine, ' . self::DAY_ON[$wd][1]];
                break;
            case 'monthly':
                $out = match ($kind) {
                    'wd' => ['Co miesiąc, ' . $n . '. dzień roboczy', 'Chaque mois, ' . self::frOrd($n) . ' jour ouvré'],
                    'd' => ['Co miesiąc, ' . $n . '. dzień miesiąca', 'Chaque mois, le ' . self::frDay($n)],
                    default => ['Co miesiąc, ostatni dzień miesiąca', 'Chaque mois, le dernier jour'],
                };
                break;
            case 'quarterly':
                $out = match ($kind) {
                    'wd' => ['Co kwartał, ' . $n . '. dzień roboczy pierwszego miesiąca kwartału', 'Chaque trimestre, ' . self::frOrd($n) . ' jour ouvré du premier mois'],
                    'd' => ['Co kwartał, ' . $n . '. dzień pierwszego miesiąca kwartału', 'Chaque trimestre, le ' . self::frDay($n) . ' du premier mois'],
                    default => ['Co kwartał, ostatni dzień kwartału', 'Chaque trimestre, le dernier jour'],
                };
                break;
            default:
                $out = self::rhythm($t);
        }
        if ($time !== null) $out = [$out[0] . ', do ' . $time, $out[1] . ', avant ' . $time];
        return [$out[0] . '.', $out[1] . '.'];
    }

    private static function whereFor(array $t, bool $assistant, ?array $howTo, bool $atelier): array
    {
        if ($howTo !== null && $howTo['where'] !== null) return $howTo['where'];
        if ($howTo !== null && $howTo['page'] !== null && ($menu = self::menuPath($howTo['page'])) !== null) return [$menu, $menu];
        if ($atelier && !$assistant && ($t['link_path'] ?? '') === '') return ['Na stanowisku pracy', 'Au poste de travail'];
        return self::where($t, $assistant);
    }

    /**
     * Gdzie : tâche d'assistant = la carte de la tâche dans « Dzisiaj i zadania » (elle ouvre l'assistant), plus la page
     * du menu quand l'assistant en est une ; tâche manuelle = chemin du menu de son lien, sinon l'adresse ; sans lien =
     * « Dzisiaj i zadania ».
     */
    public static function where(array $t, bool $assistant): array
    {
        $today = 'ERP › ' . Rail::ITEMS['today'][0];
        $link = ($t['link_path'] ?? '') !== '' ? (string) $t['link_path'] : null;
        if ($assistant) {
            $menu = self::menuPath($link, true);
            $menu = $menu !== null ? substr($menu, strlen('ERP › ')) : null;
            return [$today . ' › kliknij zadanie' . ($menu ? ' · w menu : ' . $menu : ''), $today . ' › cliquer la tâche' . ($menu ? ' · menu : ' . $menu : '')];
        }
        if ($link === null) return [$today, $today];
        $menu = self::menuPath($link) ?? ('ERP › ' . (strtok($link, '?#') ?: $link));
        return [$menu, $menu];
    }

    /**
     * Chemin du menu d'une page de l'ERP : onglet (PageTabs, préfixe le plus long) → « Sekcja › Pozycja › Zakładka »,
     * sinon la position du rail (Rail::itemFor) ; libellés répétés fusionnés. $exact : seulement une page du menu
     * elle-même (pas une sous-page). Null : page hors menu.
     */
    public static function menuPath(?string $link, bool $exact = false): ?string
    {
        if ($link === null || $link === '') return null;
        $path = '/' . trim((string) (strtok($link, '?#') ?: '/'), '/');
        $best = null;
        $len = -1;
        foreach (PageTabs::GROUPS as $key => $group) {
            foreach ($group['tabs'] as $tab) {
                if (($tab[4] ?? '') === 'blank') continue;
                $url = $tab[0];
                $hit = $path === $url || (!$exact && empty($tab[3]) && str_starts_with($path, $url . '/'));
                if ($hit && strlen($url) > $len) {
                    $best = [$key, $group, $tab];
                    $len = strlen($url);
                }
            }
        }
        if ($best !== null) {
            [$key, $group, $tab] = $best;
            $parent = Rail::GROUP_PARENT[$key] ?? null;
            $parts = $parent !== null && isset(Rail::ITEMS[$parent])
                ? [Rail::ITEMS[$parent][2], Rail::ITEMS[$parent][0], $group['label'], $tab[1]]
                : [$group['section'], $group['label'], $tab[1]];
        } else {
            $item = Rail::itemFor($path);
            if ($item === null || ($exact && Rail::ITEMS[$item][3] !== $path && !in_array($path, Rail::ITEMS[$item][4], true))) return null;
            $parts = [Rail::ITEMS[$item][2], Rail::ITEMS[$item][0]];
        }
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === 'Start' || end($out) === $p) continue;
            $out[] = $p;
        }
        return $out ? 'ERP › ' . implode(' › ', $out) : null;
    }

    /**
     * Gotowe, gdy : règle de l'assistant (se valide seule), protocole, automatique, sinon coché dans Moje zadania ; poste
     * de l'atelier (sans compte ERP) : fait et vu par le responsable.
     */
    private static function done(array $t, ?string $code, ?array $howTo, bool $atelier = false): array
    {
        if ($code !== null) {
            $pl = self::ucfirst(TaskWizards::CATALOG[$code][2]);
            $fr = self::ucfirst($howTo['done_fr'] ?? TaskWizards::CATALOG[$code][2]);
            return [$pl . ' — zadanie odhacza się samo.', $fr . ' — la tâche se valide seule.'];
        }
        if (($t['form_code'] ?? null) !== null && $t['form_code'] !== '') {
            return ['Protokół zakończony w « Moje zadania ».', 'Compte rendu terminé dans « Moje zadania ».'];
        }
        if ((int) ($t['cron_on'] ?? 0) === 1 && ($t['cron_then'] ?? 'wait') === 'done') {
            return ['Robi się samo (automatycznie) — zadanie odhacza się samo.', 'Se fait tout seul (automatique) — la tâche se valide seule.'];
        }
        if ($atelier) return ['Zrobione i sprawdzone przez przełożonego.', 'Fait et vérifié par le responsable.'];
        return ['Zadanie odhaczone w « Moje zadania ».', 'Tâche cochée dans « Moje zadania ».'];
    }

    /** @return list<int> jours 1–7 de « weekdays » (vide = tous les jours ouvrés). */
    private static function days(?string $weekdays): array
    {
        if ($weekdays === null || trim($weekdays) === '') return [];
        $days = array_values(array_unique(array_filter(array_map('intval', explode(',', $weekdays)), fn($d) => $d >= 1 && $d <= 7)));
        sort($days);
        return $days;
    }

    private static function frOrd(int $n): string
    {
        return $n === 1 ? '1er' : $n . 'e';
    }

    private static function frDay(int $n): string
    {
        return $n === 1 ? '1er' : (string) $n;
    }

    private static function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
