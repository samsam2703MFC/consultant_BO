<?php
/*
 * Produkcja i Gantt na stronie edycji produktu : wydajność, etapy marszruty z odstępem do następnego
 * etapu (od razu, + minuty, następny dzień), ilość tygodnia z planu, dni z Gantta tygodnia, operacje.
 * Dane : ProductionSummaryService::forFinalProduct() ($production) ; edycja w karcie produkcyjnej.
 */
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$n = fn($v, int $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
$dur = function ($s): string {
    $s = (int) round((float) $s);
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return round($s / 60) . ' min';
    $hours = intdiv($s, 3600);
    $min = (int) round(($s % 3600) / 60);
    return $hours . ' h' . ($min ? ' ' . str_pad((string) $min, 2, '0', STR_PAD_LEFT) . ' min' : '');
};
$offset = function ($s): string {
    $s = (int) round((float) $s);
    $day = intdiv($s, 86400);
    $rest = $s % 86400;
    return ($day ? 'D+' . $day . ' ' : '') . '+' . intdiv($rest, 3600) . ':' . str_pad((string) intdiv($rest % 3600, 60), 2, '0', STR_PAD_LEFT);
};
$weekday = ['', 'pn', 'wt', 'śr', 'cz', 'pt', 'sb', 'nd'];
$day = fn(string $d) => $weekday[(int) date('N', strtotime($d))] . ' ' . date('d.m', strtotime($d));
$types = ['machine' => 'maszyna', 'four' => 'piec', 'robot' => 'robot', 'manuel' => 'ręcznie', 'fermentation' => 'komora fermentacyjna', 'froid' => 'chłodnia', 'surgelation' => 'mroźnia'];
$modes = ['MTO' => 'na zamówienie', 'MTS' => 'na stan'];
$bakes = ['none' => 'bez wypieku', 'par_baked' => 'podpieczony', 'baked' => 'upieczony'];
$refs = ['pallet' => 'paleta', 'batch' => 'partia minimalna', 'thousand' => '1000 szt.'];
$p = $production ?? null;
?>
<div class="card at-prodsum" id="produkcja">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="card-title mb-0">Produkcja i Gantt</h4>
            <span class="at-sub">karta produkcyjna: wydajność, etapy, czasy i plan tygodnia</span>
        </div>
        <?php if ($p): ?>
            <span class="d-flex gap-2 flex-wrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= ROOT ?>/workshop/products#/item/<?= (int) $p['item_id'] ?>/5"><i class="bi bi-pencil"></i> Edytuj etapy i czasy</a>
                <a class="btn btn-sm btn-outline-primary" href="<?= ROOT ?>/workshop/gantt#view=week"><i class="bi bi-calendar-week"></i> Gantt tygodnia</a>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
    <?php if (empty($production_available)): ?>
        <p class="at-empty mb-0">Moduł produkcji (karty produkcyjne) nie jest dostępny w tej bazie.</p>
    <?php elseif (!$p): ?>
        <p class="at-empty mb-2">Ten produkt nie ma jeszcze karty produkcyjnej — bez niej nie ma czasów pracy, etapów ani miejsca w Gantcie.</p>
        <a class="btn btn-sm btn-primary" href="<?= ROOT ?>/workshop/products">Karty produkcyjne</a>
    <?php else: ?>
        <div class="at-prodsum-kpis">
            <div><span>Wydajność</span><strong><?= $n($p['per_hour']) ?> szt./h</strong>
                <small>na 1 osobę<?= $p['per_hour_group'] !== null ? ' · grupa (' . $p['group_people'] . ' os.) ok. ' . $n($p['per_hour_group']) . ' szt./h' : '' ?></small></div>
            <div><span>Czas pracy</span><strong><?= $p['minutes_per_carton'] !== null ? $n($p['minutes_per_carton'], 1) . ' min / karton' : $dur($p['labour_s']) ?></strong>
                <small><?= $n($p['hours_per_1000'], 1) ?> h na 1000 szt.</small></div>
            <div><span>Od startu do końca</span><strong><?= $h($dur($p['lead_time_s'])) ?></strong>
                <small>partia: <?= $h($refs[$p['reference']['kind']]) ?> = <?= $n($p['reference']['quantity']) ?> szt.<?= $p['reference']['cartons'] ? ' (' . $n($p['reference']['cartons']) . ' kart.)' : '' ?><?= $p['next_day'] ? ' · etap następnego dnia' : '' ?></small></div>
            <div><span>Grupa i tryb</span><strong><?= $h($p['group'] ?? 'bez grupy') ?></strong>
                <small><?= $h($modes[$p['mode']] ?? '—') ?> · <?= $h($bakes[$p['bake_state']] ?? 'wypiek nieokreślony') ?> · karta <?= $p['status'] === 'validated' ? 'zatwierdzona' : 'w przygotowaniu' ?></small></div>
        </div>

        <?php $w = $p['week']; ?>
        <div class="at-prodsum-week">
            <strong>Tydzień <?= (int) $w['iso_week'] ?> (<?= date('d.m', strtotime($w['monday'])) ?> – <?= date('d.m', strtotime($w['sunday'])) ?>):</strong>
            <?php if ($w['quantity'] > 0): ?>
                do zrobienia <strong><?= $n($w['quantity']) ?> szt.</strong><?= $p['ppc'] ? ' (' . $n($w['quantity'] / $p['ppc'], 1) . ' kart.)' : '' ?> · <?= $n($w['hours'], 1) ?> h
                · plan v<?= (int) $w['plan']['version'] ?><?= $w['due_dates'] ? ' · termin ' . $h(implode(', ', array_map($day, $w['due_dates']))) : '' ?>
            <?php else: ?>
                brak w zatwierdzonym planie tego tygodnia
            <?php endif; ?>
            <br>
            Dni w Gantcie tygodnia:
            <?php if ($w['slots']): ?>
                <?= $h(implode(' · ', array_map(fn($s) => $day($s['day']) . ': ' . $n($s['quantity']) . ' szt.', $w['slots']))) ?>
            <?php else: ?>
                <span class="at-sub">nie przydzielono (« Rozdziel na dni » w Gantcie tygodnia)</span>
            <?php endif; ?>
            <br>
            Gantt godzinowy:
            <?php if ($w['operations']): ?>
                <?= (int) $w['operations'] ?> operacji, <?= $h($day(substr($w['first_start'], 0, 10)) . ' ' . substr($w['first_start'], 11, 5)) ?> – <?= $h($day(substr($w['last_end'], 0, 10)) . ' ' . substr($w['last_end'], 11, 5)) ?>
                <?= $w['done'] ? ' · zakończone ' . (int) $w['done'] : '' ?><?= $w['running'] ? ' · w toku ' . (int) $w['running'] : '' ?>
            <?php else: ?>
                <span class="at-sub">brak uszeregowanych operacji w tym tygodniu</span>
            <?php endif; ?>
        </div>

        <?php if ($p['steps']): ?>
        <div class="table-responsive">
            <table class="table mb-0 at-prodsum-table">
                <thead>
                    <tr>
                        <th>Etap</th>
                        <th>Stanowisko</th>
                        <th class="text-end">Osoby</th>
                        <th class="text-end" title="Czas stały na przejście (np. nastawienie, załadunek pieca)">Czas / przejście</th>
                        <th class="text-end">Czas / szt.</th>
                        <th class="text-end" title="Sztuk w jednym przejściu (dzieża, blacha, piec)">Na przejście</th>
                        <th class="text-end" title="Dla partii odniesienia">Przejścia · praca</th>
                        <th class="text-end" title="Wydajność etapu dla partii odniesienia (praca + mycie)">Szt./h</th>
                        <th title="Start od początku produkcji partii">Start</th>
                        <th>Następny etap</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($p['steps'] as $i => $s): $last = $i === count($p['steps']) - 1; ?>
                    <tr>
                        <td><strong><?= (int) $s['step_no'] ?>. <?= $h($s['name']) ?></strong><?= $s['temperature_c'] !== null ? '<div class="at-sub">' . $n($s['temperature_c']) . ' °C</div>' : '' ?></td>
                        <td><?= $h($s['workstation']) ?><div class="at-sub"><?= $h($types[$s['workstation_type']] ?? $s['workstation_type']) ?></div></td>
                        <td class="text-end"><?= $n($s['operators'], $s['operators'] == floor($s['operators']) ? 0 : 1) ?></td>
                        <td class="text-end"><?= $s['fixed_s'] ? $h($dur($s['fixed_s'])) : '—' ?></td>
                        <td class="text-end"><?= $s['variable_s'] > 0 ? $n($s['variable_s'], $s['variable_s'] == floor($s['variable_s']) ? 0 : 1) . ' s' : '—' ?></td>
                        <td class="text-end"><?= $n($s['capacity']) ?></td>
                        <td class="text-end"><?= (int) $s['passes'] ?> · <?= $h($dur($s['work_s'])) ?><?= $s['cleaning_s'] ? '<div class="at-sub">+ mycie ' . $h($dur($s['cleaning_s'])) . '</div>' : '' ?></td>
                        <td class="text-end"><?= $n($s['per_hour']) ?></td>
                        <td class="text-nowrap"><?= $h($offset($s['start_s'])) ?></td>
                        <td>
                            <?php if ($s['gap']['kind'] === 'next_day'): ?>
                                <span class="at-pill at-pill-warn">D+1</span> po <?= $h($dur($s['gap']['seconds'])) ?>
                            <?php elseif ($s['gap']['kind'] === 'minutes'): ?>
                                <span class="at-pill">+<?= $h($dur($s['gap']['seconds'])) ?></span>
                            <?php else: ?>
                                <span class="at-sub"><?= $last ? 'koniec' : 'od razu' ?></span>
                            <?php endif; ?>
                            <?= $s['delay_place'] ? '<div class="at-sub">czekanie: ' . $h($s['delay_place']) . '</div>' : '' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="at-profit-note mb-0">Czasy z karty produkcyjnej: czas stały liczony na przejście (dzieża, blacha, piec), czas na sztukę × ilość, osoby × czas = godziny pracy w Gantcie. Następny etap: „od razu”, „+ minuty” (np. garowanie) albo „D+1”, gdy czekanie trwa od 8 h (np. noc w chłodni).
            Partia odniesienia: <?= $h($refs[$p['reference']['kind']]) ?>. Minimalna partia <?= $n($p['batch_min']) ?>, wielokrotność <?= $n($p['batch_multiple']) ?><?= $p['mode'] === 'MTS' ? ', stan min. ' . $n($p['stock_min']) . ' / maks. ' . $n($p['stock_max']) : '' ?>.</p>
        <?php else: ?>
            <p class="at-empty mb-0">Karta bez etapów (marszruty) — dodaj etapy, aby produkt miał czas pracy i miejsce w Gantcie.</p>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
