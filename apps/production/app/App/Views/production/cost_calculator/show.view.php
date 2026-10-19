<?php $this->view("page_components/header"); ?>

<?php
$month = $month ?? [];
$monthId = (int)($month['id'] ?? 0);
$ym = sprintf('%04d-%02d', (int)$month['year'], (int)$month['month']);
$monthStatus = (string)($month['status'] ?? 'OPEN');
$isLocked = ($monthStatus === 'LOCKED');

$monthSections = $month_sections ?? [];
$fixedCosts = $fixed_costs ?? [];
$sections = $sections ?? [];
$allEmployees = $all_employees ?? [];

$results = $results ?? [];
$summary = $results['summary'] ?? [];
$rows = $results['rows'] ?? [];
$warnings = $results['warnings'] ?? [];

$monthEmployees = $employees ?? [];
$employeesMaster = $employees_master ?? [];

$mediaTypes = $media_types ?? [];
$mediaCosts = $media_costs ?? [];
$mediaAllocations = $media_allocations ?? [];
$logisticsTypes = $logistics_types ?? [];
$logisticsCosts = $logistics_costs ?? [];
?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>
                    Kalkulator kosztów — <?= htmlspecialchars($ym) ?>
                    <?php if ($isLocked): ?>
                        <span class="badge bg-success ms-2" title="Miesiąc zamknięty – dane zatwierdzone">
                            <i class="bi bi-lock-fill me-1"></i>ZAMKNIĘTY
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark ms-2" title="Miesiąc otwarty – dane w trakcie uzupełniania">
                            <i class="bi bi-unlock-fill me-1"></i>OTWARTY
                        </span>
                    <?php endif; ?>
                </h3>
                <p class="text-subtitle text-muted">Snapshot miesięczny: działy, pracownicy, koszty stałe, wyniki.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                    <a class="btn btn-outline-secondary" href="<?= ROOT ?>/cost-calculator">← Wróć</a>
                    <?php if (!$isLocked): ?>
                        <button class="btn btn-secondary" onclick="copyPrev()">Skopiuj z poprzedniego</button>
                        <button class="btn btn-primary" onclick="syncCartons()">Synchronizuj kartony (AUTO)</button>
                        <button class="btn btn-success" onclick="lockMonth()"
                                title="Zatwierdź i zablokuj miesiąc – stanie się podstawą kalkulacji marży">
                            <i class="bi bi-lock-fill me-1"></i>Zamknij miesiąc
                        </button>
                    <?php else: ?>
                        <button class="btn btn-outline-warning" onclick="unlockMonth()"
                                title="Otwórz miesiąc ponownie – możliwa edycja danych">
                            <i class="bi bi-unlock-fill me-1"></i>Otwórz ponownie
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <section class="section">

        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning">
                <?php foreach ($warnings as $w): ?>
                    <div><?= htmlspecialchars($w) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isLocked): ?>
        <div class="alert alert-success d-flex align-items-center gap-3 mb-3">
            <i class="bi bi-lock-fill fs-4"></i>
            <div>
                <strong>Miesiąc zamknięty.</strong>
                Dane są zatwierdzone i stanowią podstawę kalkulacji marży produktów.
                Edycja jest zablokowana. Aby wprowadzić zmiany, kliknij „Otwórz ponownie".
            </div>
        </div>
        <?php endif; ?>

        <!-- SUMMARY (zawsze widocne) -->
        <div class="card mb-3">
            <div class="card-body d-flex gap-4 flex-wrap">
                <div><strong>Kartony:</strong> <?= (int)($summary['cartons_total'] ?? 0) ?></div>
                <div><strong>Wynagrodzenia:</strong> <span id="summary_labor_total"><?= number_format((float)($summary['labor_total'] ?? 0), 2, ',', ' ') ?></span> zł</div>
                <div><strong>Media zakładu:</strong> <?= number_format((float)($summary['media_total'] ?? 0), 2, ',', ' ') ?> zł</div>
                <div><strong>Koszty stałe:</strong> <?= number_format((float)($summary['fixed_total'] ?? 0), 2, ',', ' ') ?> zł</div>
                <div><strong>Logistyka:</strong> <?= number_format((float)($summary['logistics_total'] ?? 0), 2, ',', ' ') ?> zł</div>
                <div><strong>KS/karton (global):</strong> <?= number_format((float)($summary['fixed_per_carton_global'] ?? 0), 4, ',', ' ') ?> zł</div>
            </div>
        </div>

        <!-- HORIZONTAL NAVS (Mazer style: nav-tabs + a.nav-link + tab-content) -->
        <div class="card mb-3">
            <div class="card-body">
                <ul class="nav nav-tabs" id="costTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active"
                           id="tab-sections-link"
                           data-bs-toggle="tab"
                           href="#tab-sections"
                           role="tab"
                           aria-controls="tab-sections"
                           aria-selected="true">
                            Działy
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">
                        <a class="nav-link"
                           id="tab-employees-link"
                           data-bs-toggle="tab"
                           href="#tab-employees"
                           role="tab"
                           aria-controls="tab-employees"
                           aria-selected="false">
                            Pracownicy
                            <?php if (!empty($monthEmployees)): ?>
                                <span class="badge bg-light-secondary ms-1"><?= (int)count($monthEmployees) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">
                        <a class="nav-link"
                           id="tab-fixed-link"
                           data-bs-toggle="tab"
                           href="#tab-fixed"
                           role="tab"
                           aria-controls="tab-fixed"
                           aria-selected="false">
                            Koszty stałe
                            <?php if (!empty($fixedCosts)): ?>
                                <span class="badge bg-light-secondary ms-1"><?= (int)count($fixedCosts) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">
                        <a class="nav-link"
                           id="tab-media-link"
                           data-bs-toggle="tab"
                           href="#tab-media"
                           role="tab"
                           aria-controls="tab-media"
                           aria-selected="false">
                            Koszty zakładu
                            <?php if (!empty($mediaCosts)): ?>
                                <span class="badge bg-light-secondary ms-1"><?= (int)count($mediaCosts) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">
                        <a class="nav-link"
                           id="tab-logistics-link"
                           data-bs-toggle="tab"
                           href="#tab-logistics"
                           role="tab"
                           aria-controls="tab-logistics"
                           aria-selected="false">
                            Logistyka
                            <?php if (!empty($logisticsCosts)): ?>
                                <span class="badge bg-light-secondary ms-1"><?= (int)count($logisticsCosts) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <li class="nav-item" role="presentation">
                        <a class="nav-link"
                           id="tab-results-link"
                           data-bs-toggle="tab"
                           href="#tab-results"
                           role="tab"
                           aria-controls="tab-results"
                           aria-selected="false">
                            Wyniki
                        </a>
                    </li>
                </ul>

                <div class="tab-content" id="costTabContent">

                    <!-- TAB: DZIAŁY -->
                    <div class="tab-pane fade show active"
                         id="tab-sections"
                         role="tabpanel"
                         aria-labelledby="tab-sections-link">

                        <div class="card mb-0 mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h4 class="card-title mb-0">Działy — kartony w miesiącu</h4>
                                <?php if (!$isLocked): ?>
                                <button class="btn btn-sm btn-outline-info" onclick="openWdtImportModal()"
                                        title="Zaczytaj ilości kartonów ze sprzedaży WDT">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Zaczytaj z WDT
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php
                                $msMap = [];
                                foreach ($monthSections as $ms) $msMap[(int)$ms['section_id']] = $ms;
                                ?>

                                <div class="table-responsive">
                                    <table class="table table-striped align-middle">
                                        <thead>
                                        <tr>
                                            <th style="width: 60px">ID</th>
                                            <th>Dział</th>
                                            <th style="width: 160px">Kartony</th>
                                            <th style="width: 170px">Źródło</th>
                                            <th style="width: 150px"></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($sections as $s): ?>
                                            <?php
                                            $sid = (int)$s['id'];
                                            $row = $msMap[$sid] ?? null;
                                            $qty = (int)($row['cartons_qty'] ?? 0);
                                            $src = (string)($row['source'] ?? 'AUTO');
                                            $badgeClass = match($src) {
                                                'MANUAL' => 'bg-light-warning',
                                                'WDT'    => 'bg-light-success',
                                                default  => 'bg-light-info',
                                            };
                                            ?>
                                            <tr id="section_row_<?= $sid ?>">
                                                <td>#<?= $sid ?></td>
                                                <td><?= htmlspecialchars($s['name'] ?? '') ?></td>
                                                <td>
                                                    <input type="number" min="0" class="form-control form-control-sm"
                                                           id="cartons_<?= $sid ?>"
                                                           value="<?= $qty ?>"
                                                           <?= $isLocked ? 'disabled' : '' ?>>
                                                </td>
                                                <td>
                                                    <?php if (!$isLocked): ?>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <span class="badge <?= $badgeClass ?>"
                                                              id="source_badge_<?= $sid ?>">
                                                            <?= htmlspecialchars($src) ?>
                                                        </span>
                                                        <div class="dropdown">
                                                            <button class="btn btn-xs btn-outline-secondary dropdown-toggle py-0 px-1"
                                                                    type="button"
                                                                    data-bs-toggle="dropdown"
                                                                    title="Zmień źródło">
                                                                <i class="bi bi-arrow-left-right"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <li><h6 class="dropdown-header small">Zmień źródło na:</h6></li>
                                                                <?php foreach (['MANUAL', 'AUTO', 'WDT'] as $srcOpt): ?>
                                                                    <li>
                                                                        <a class="dropdown-item small <?= $srcOpt === $src ? 'active disabled' : '' ?>"
                                                                           href="#"
                                                                           onclick="setCartonSource(<?= $sid ?>, '<?= $srcOpt ?>'); return false;">
                                                                            <?php if ($srcOpt === 'MANUAL'): ?>
                                                                                <i class="bi bi-pencil-fill text-warning me-1"></i>
                                                                            <?php elseif ($srcOpt === 'WDT'): ?>
                                                                                <i class="bi bi-file-earmark-fill text-success me-1"></i>
                                                                            <?php else: ?>
                                                                                <i class="bi bi-arrow-repeat text-info me-1"></i>
                                                                            <?php endif; ?>
                                                                            <?= $srcOpt ?>
                                                                            <?= $srcOpt === $src ? ' ✓' : '' ?>
                                                                        </a>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                        <span class="badge <?= $badgeClass ?>"
                                                              id="source_badge_<?= $sid ?>">
                                                            <?= htmlspecialchars($src) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if (!$isLocked): ?>
                                                    <button class="btn btn-sm btn-outline-primary"
                                                            onclick="saveManualCartons(<?= $sid ?>)"
                                                            title="Zapisz wpisaną wartość (zmienia źródło na MANUAL)">
                                                        <i class="bi bi-floppy me-1"></i>Zapisz
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <small class="text-muted">
                                    <i class="bi bi-arrow-repeat text-info me-1"></i><strong>AUTO</strong> – synchronizacja z zamówień &nbsp;|&nbsp;
                                    <i class="bi bi-file-earmark-fill text-success me-1"></i><strong>WDT</strong> – zaczytane z faktur WDT &nbsp;|&nbsp;
                                    <i class="bi bi-pencil-fill text-warning me-1"></i><strong>MANUAL</strong> – ręcznie wprowadzone (chronione przed nadpisaniem).
                                    Użyj <i class="bi bi-arrow-left-right"></i> przy źródle, aby je zmienić.
                                </small>
                            </div>
                        </div>

                    </div>

                    <!-- TAB: PRACOWNICY -->
                    <div class="tab-pane fade"
                         id="tab-employees"
                         role="tabpanel"
                         aria-labelledby="tab-employees-link">

                        <div class="card mb-0 mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h4 class="card-title mb-0">Pracownicy (wynagrodzenia)</h4>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-primary" onclick="openEmployeeModal()" <?= $isLocked ? 'disabled title="Miesiąc zamknięty"' : '' ?>>+ Dodaj</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Dział</th>
                                            <th>Imię</th>
                                            <th>Nazwisko</th>
                                            <th class="text-end">Koszt / miesiąc</th>
                                            <th style="width:170px"></th>
                                        </tr>
                                        </thead>
                                        <tbody id="employees_body">
                                        <?php if (!empty($monthEmployees)): ?>
                                            <?php foreach ($monthEmployees as $e): ?>
                                                <tr id="emp_row_<?= (int)$e['employee_ref_id'] ?>"
                                                    data-emp-ref-id="<?= (int)$e['employee_ref_id'] ?>"
                                                    data-emp-name="<?= htmlspecialchars(($e['name'] ?? '').' '.($e['surname'] ?? '')) ?>"
                                                    data-emp-cost="<?= number_format((float)$e['monthly_cost'], 2, '.', '') ?>">
                                                    <td>#<?= (int)$e['employee_ref_id'] ?></td>
                                                    <td><?= htmlspecialchars($e['section_name'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($e['name'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($e['surname'] ?? '') ?></td>
                                                    <td class="text-end" id="emp_cost_cell_<?= (int)$e['employee_ref_id'] ?>">
                                                        <strong><?= number_format((float)$e['monthly_cost'], 2, ',', ' ') ?> zł</strong>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if (!$isLocked): ?>
                                                        <button class="btn btn-sm btn-outline-primary me-1"
                                                                onclick="openEditEmployeeCostModal(<?= (int)$e['employee_ref_id'] ?>)"
                                                                title="Edytuj wynagrodzenie">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-sm btn-outline-danger"
                                                                onclick="deleteEmployee(<?= (int)$e['employee_ref_id'] ?>)">
                                                            Usuń
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="6" class="text-muted">Brak wpisów</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- TAB: KOSZTY STAŁE -->
                    <div class="tab-pane fade"
                         id="tab-fixed"
                         role="tabpanel"
                         aria-labelledby="tab-fixed-link">

                        <div class="card mb-0 mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h4 class="card-title mb-0">Koszty stałe</h4>
                                <button class="btn btn-sm btn-primary" onclick="openFixedCostModal()" <?= $isLocked ? 'disabled title="Miesiąc zamknięty"' : '' ?>>+ Dodaj</button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nazwa</th>
                                            <th>Kategoria</th>
                                            <th class="text-end">Kwota</th>
                                            <th style="width:120px"></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (!empty($fixedCosts)): ?>
                                            <?php foreach ($fixedCosts as $c): ?>
                                                <tr>
                                                    <td>#<?= (int)$c['id'] ?></td>
                                                    <td><?= htmlspecialchars($c['name'] ?? '') ?></td>
                                                    <td><span class="badge bg-light-secondary"><?= htmlspecialchars($c['category'] ?? 'GENERAL') ?></span></td>
                                                    <td class="text-end"><strong><?= number_format((float)$c['amount'], 2, ',', ' ') ?> zł</strong></td>
                                                    <td class="text-end">
                                                        <button class="btn btn-sm btn-outline-danger"
                                                                onclick="deleteFixedCost(<?= (int)$c['id'] ?>)">
                                                            Usuń
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="5" class="text-muted">Brak wpisów</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- TAB: KOSZTY ZAKŁADU (MEDIA) -->
                    <div class="tab-pane fade"
                         id="tab-media"
                         role="tabpanel"
                         aria-labelledby="tab-media-link">

                        <?php
                        // --- Kalkulacja kosztu per dział (donut data) ---
                        $mediaCostsMap = [];
                        foreach ($mediaCosts as $mc) {
                            $mediaCostsMap[(int)$mc['media_type_id']] = (float)$mc['amount'];
                        }
                        $sectionMediaTotals = []; // [section_id => ['name' => ..., 'total' => float, 'details' => [...]]]
                        foreach ($mediaAllocations as $a) {
                            $sid   = (int)$a['section_id'];
                            $mtId  = (int)$a['media_type_id'];
                            $pct   = (float)$a['allocation_percent'];
                            $base  = $mediaCostsMap[$mtId] ?? 0.0;
                            $alloc = $base * ($pct / 100.0);
                            if (!isset($sectionMediaTotals[$sid])) {
                                $sectionMediaTotals[$sid] = [
                                    'name'    => $a['section_name'] ?? ('Dział #' . $sid),
                                    'total'   => 0.0,
                                    'details' => [],
                                ];
                            }
                            $sectionMediaTotals[$sid]['total'] += $alloc;
                            $sectionMediaTotals[$sid]['details'][] = [
                                'media_name' => $a['media_name'] ?? '',
                                'pct'        => $pct,
                                'amount'     => $alloc,
                            ];
                        }
                        arsort($sectionMediaTotals);
                        $grandMediaTotal = array_sum(array_column($sectionMediaTotals, 'total'));
                        ?>
                        <div class="card mb-0 mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h4 class="card-title mb-0">Koszty zmienne</h4>
                                <div class="d-flex gap-2">
                                    <?php if (!empty($sectionMediaTotals)): ?>
                                    <button class="btn btn-sm btn-outline-info" onclick="openSectionMediaSummaryModal()"
                                            title="Podsumowanie kosztów mediów per dział">
                                        <i class="bi bi-pie-chart-fill me-1"></i>Podsumowanie działów
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-primary" onclick="openMediaCostModal()" <?= $isLocked ? 'disabled title="Miesiąc zamknięty"' : '' ?>>+ Dodaj koszt</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Medium</th>
                                            <th>Jednostka</th>
                                            <th class="text-end">Kwota</th>
                                            <th>Notatka</th>
                                            <th style="width:120px"></th>
                                        </tr>
                                        </thead>
                                        <tbody id="media_costs_body">
                                        <?php if (!empty($mediaCosts)): ?>
                                            <?php foreach ($mediaCosts as $c): ?>
                                                <tr data-media-id="<?= (int)$c['media_type_id'] ?>" data-row-id="<?= (int)$c['id'] ?>">
                                                    <td>#<?= (int)$c['id'] ?></td>
                                                    <td><?= htmlspecialchars($c['media_name'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($c['media_unit'] ?? 'PLN') ?></td>
                                                    <td class="text-end"><strong><?= number_format((float)$c['amount'], 2, ',', ' ') ?> zł</strong></td>
                                                    <td><?= htmlspecialchars($c['note'] ?? '') ?></td>
                                                    <td class="text-end">
                                                        <button class="btn btn-sm btn-outline-danger"
                                                                onclick="deleteMediaCost(<?= (int)$c['media_type_id'] ?>)">
                                                            Usuń
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr data-empty="1"><td colspan="6" class="text-muted">Brak wpisów</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <hr>

                                <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                                    <h6 class="mb-0">Udziały kosztów na działy</h6>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" onclick="openMediaAllocationModal()">
                                            <i class="bi bi-plus-lg me-1"></i>Dodaj udział
                                        </button>
                                    </div>
                                </div>

                                <!-- Podsumowanie udziałów per medium -->
                                <div id="media_allocation_summaries" class="mb-3">
                                    <?php
                                    $allocationsByMedia = [];
                                    foreach ($mediaAllocations as $a) {
                                        $mtId = (int)$a['media_type_id'];
                                        if (!isset($allocationsByMedia[$mtId])) {
                                            $allocationsByMedia[$mtId] = [
                                                'name' => $a['media_name'] ?? 'Medium',
                                                'total' => 0,
                                                'items' => []
                                            ];
                                        }
                                        $allocationsByMedia[$mtId]['total'] += (float)$a['allocation_percent'];
                                        $allocationsByMedia[$mtId]['items'][] = $a;
                                    }
                                    ?>
                                    <?php if (!empty($allocationsByMedia)): ?>
                                        <?php foreach ($allocationsByMedia as $mtId => $info): ?>
                                            <?php
                                            $total = $info['total'];
                                            $statusClass = 'bg-secondary';
                                            $statusText = number_format($total, 1, ',', ' ') . '%';
                                            $alertClass = 'alert-light';
                                            
                                            if ($total > 100.01) {
                                                $statusClass = 'bg-danger';
                                                $alertClass = 'alert-danger';
                                            } elseif (abs($total - 100) < 0.5) {
                                                $statusClass = 'bg-success';
                                                $alertClass = 'alert-success';
                                            } elseif ($total > 90) {
                                                $statusClass = 'bg-warning text-dark';
                                                $alertClass = 'alert-warning';
                                            }
                                            ?>
                                            <div class="alert <?= $alertClass ?> mb-2 py-2">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?= htmlspecialchars($info['name']) ?></strong>
                                                        <small class="text-muted ms-2">(<?= count($info['items']) ?> <?= count($info['items']) == 1 ? 'dział' : 'działów' ?>)</small>
                                                        <?php if ($total > 100.01): ?>
                                                            <br><small class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Przekroczenie! Usuń lub zmniejsz udziały.</small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="badge <?= $statusClass ?>">Suma: <?= $statusText ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-light">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <span class="text-muted">Brak przypisanych udziałów. Dodaj udział dla medium, aby rozdzielić jego koszt na działy.</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-striped align-middle">
                                        <thead>
                                        <tr>
                                            <th>Medium</th>
                                            <th>Dział</th>
                                            <th class="text-end">Udział (%)</th>
                                            <th>Notatka</th>
                                            <th style="width:120px"></th>
                                        </tr>
                                        </thead>
                                        <tbody id="media_allocations_body">
                                        <?php if (!empty($mediaAllocations)): ?>
                                            <?php foreach ($mediaAllocations as $a): ?>
                                                <tr data-media-id="<?= (int)$a['media_type_id'] ?>"
                                                    data-section-id="<?= (int)$a['section_id'] ?>"
                                                    data-row-id="<?= (int)$a['id'] ?>"
                                                    data-media-name="<?= htmlspecialchars($a['media_name'] ?? '') ?>"
                                                    data-section-name="<?= htmlspecialchars($a['section_name'] ?? '') ?>"
                                                    data-percent="<?= (float)$a['allocation_percent'] ?>"
                                                    data-note="<?= htmlspecialchars($a['note'] ?? '') ?>">
                                                    <td><?= htmlspecialchars($a['media_name'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($a['section_name'] ?? '') ?></td>
                                                    <td class="text-end"><strong><?= number_format((float)$a['allocation_percent'], 2, ',', ' ') ?>%</strong></td>
                                                    <td><?= htmlspecialchars($a['note'] ?? '') ?></td>
                                                    <td class="text-end">
                                                        <button class="btn btn-sm btn-outline-primary me-1"
                                                                onclick="editMediaAllocation(<?= (int)$a['id'] ?>)"
                                                                title="Edytuj">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-danger"
                                                                onclick="deleteMediaAllocation(<?= (int)$a['id'] ?>)"
                                                                title="Usuń">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr data-empty="1"><td colspan="5" class="text-muted">Brak wpisów</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <small class="text-muted">Udziały zapisujesz per medium i dział. Wartości procentowe są przechowywane z dokładnością do 4 miejsc.</small>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: LOGISTYKA -->
                    <div class="tab-pane fade"
                         id="tab-logistics"
                         role="tabpanel"
                         aria-labelledby="tab-logistics-link">

                        <div class="card mb-0 mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h4 class="card-title mb-0">Koszty logistyczne</h4>
                                <button class="btn btn-sm btn-primary" onclick="openLogisticsModal()" <?= $isLocked ? 'disabled title="Miesiąc zamknięty"' : '' ?>>+ Dodaj</button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Typ kosztu</th>
                                            <th>Kod</th>
                                            <th class="text-end">Kwota</th>
                                            <th>Notatka</th>
                                            <th style="width:120px"></th>
                                        </tr>
                                        </thead>
                                        <tbody id="logistics_costs_body">
                                        <?php if (!empty($logisticsCosts)): ?>
                                            <?php foreach ($logisticsCosts as $lc): ?>
                                                <tr data-log-id="<?= (int)$lc['id'] ?>">
                                                    <td>#<?= (int)$lc['id'] ?></td>
                                                    <td><?= htmlspecialchars($lc['type_name'] ?? '') ?></td>
                                                    <td><span class="badge bg-light-secondary text-dark"><?= htmlspecialchars($lc['type_code'] ?? '') ?></span></td>
                                                    <td class="text-end"><strong><?= number_format((float)$lc['amount'], 2, ',', ' ') ?> zł</strong></td>
                                                    <td><?= htmlspecialchars($lc['note'] ?? '') ?></td>
                                                    <td class="text-end">
                                                        <button class="btn btn-sm btn-outline-danger"
                                                                onclick="deleteLogisticsCost(<?= (int)$lc['id'] ?>)">
                                                            Usuń
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr data-empty="1"><td colspan="6" class="text-muted text-center py-3">Brak wpisów — dodaj pierwszy koszt logistyczny</td></tr>
                                        <?php endif; ?>
                                        </tbody>
                                        <?php if (!empty($logisticsCosts)): ?>
                                        <tfoot class="table-light">
                                        <tr>
                                            <td colspan="3" class="fw-semibold">Suma</td>
                                            <td class="text-end fw-bold" id="logistics_total_cell">
                                                <?= number_format(array_sum(array_column($logisticsCosts, 'amount')), 2, ',', ' ') ?> zł
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                        </tfoot>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- TAB: WYNIKI -->
                    <div class="tab-pane fade"
                         id="tab-results"
                         role="tabpanel"
                         aria-labelledby="tab-results-link">

                        <div class="card mb-0 mt-3">
                            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <h4 class="card-title mb-0">Wyniki (koszt/karton i koszt/szt)</h4>
                                <button class="btn btn-sm btn-outline-secondary" onclick="refreshResults()">Odśwież</button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped align-middle">
                                        <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Produkt</th>
                                            <th>Dział</th>
                                            <th class="text-end">PPC</th>
                                            <th class="text-end">Surowce/karton</th>
                                            <th class="text-end">Wynag/karton</th>
                                            <th class="text-end">Media/karton</th>
                                            <th class="text-end">KS/karton</th>
                                            <th class="text-end">RAZEM/karton</th>
                                            <th class="text-end">RAZEM/szt</th>
                                        </tr>
                                        </thead>
                                        <tbody id="results_body">
                                        <?php foreach ($rows as $r): ?>
                                            <tr>
                                                <td>#<?= (int)$r['product_id'] ?></td>
                                                <td><?= htmlspecialchars($r['product_name']) ?></td>
                                                <td><?=$r['section_name'] ?></td>
                                                <td class="text-end"><?= (int)$r['ppc'] ?></td>
                                                <td class="text-end"><?= number_format((float)$r['raw_per_carton'], 2, ',', ' ') ?></td>
                                                <td class="text-end"><?= number_format((float)$r['labor_per_carton'], 2, ',', ' ') ?></td>
                                                <td class="text-end"><?= number_format((float)($r['media_per_carton'] ?? 0), 2, ',', ' ') ?></td>
                                                <td class="text-end"><?= number_format((float)$r['fixed_per_carton'], 2, ',', ' ') ?></td>
                                                <td class="text-end"><strong><?= number_format((float)$r['total_per_carton'], 2, ',', ' ') ?></strong></td>
                                                <td class="text-end"><strong><?= number_format((float)$r['total_per_piece'], 4, ',', ' ') ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <small class="text-muted">Etap 1: bez zapisu wyników do tabeli — liczone w locie.</small>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </div>

    </section>
</div>

<!-- MODALE (MVP) -->

<!-- MODAL: PRACOWNIK (TYLKO SELECT Z SYSTEMU) -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Dodaj pracownika</h5></div>
            <div class="modal-body">

                <div class="mb-2">
                    <label class="form-label">Pracownik</label>
                    <select class="form-select" id="emp_employee_id">
                        <option value="">— wybierz —</option>
                        <?php foreach ($employeesMaster as $e): ?>
                            <option value="<?= (int)$e['id'] ?>">
                                #<?= (int)$e['id'] ?> — <?= htmlspecialchars(($e['name'] ?? '').' '.($e['surname'] ?? '')) ?>
                                (<?= htmlspecialchars($e['section_name'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Lista pracowników pochodzi z systemu. Nie da się dodać “z ręki”.</small>
                </div>

                <div class="mb-2">
                    <label class="form-label">Koszt miesięczny (opcjonalny override)</label>
                    <input type="number" step="0.01" class="form-control" id="emp_cost" value="">
                    <small class="text-muted">Jeśli puste — weźmie koszt domyślny z profilu pracownika.</small>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" onclick="addEmployee()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: EDYCJA KOSZTU PRACOWNIKA -->
<div class="modal fade" id="editEmployeeCostModal" tabindex="-1" aria-labelledby="editEmployeeCostModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editEmployeeCostModalLabel">
                    <i class="bi bi-pencil-fill me-2 text-primary"></i>Edycja wynagrodzenia
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="eec_emp_ref_id" value="0">

                <div class="alert alert-light border mb-3 py-2 px-3">
                    <div class="text-muted small mb-1">Edytowany pracownik:</div>
                    <strong id="eec_emp_name" class="fs-6">—</strong>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        Nowy koszt miesięczny (zł)
                        <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                        <input type="number"
                               step="0.01"
                               min="0"
                               class="form-control"
                               id="eec_cost"
                               placeholder="np. 4500.00">
                        <span class="input-group-text">zł</span>
                    </div>
                    <div class="form-text text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Wartość dotyczy tylko tego miesiąca — nie zmienia danych pracownika w systemie.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" id="eec_save_btn" onclick="saveEmployeeCost()">
                    <i class="bi bi-floppy me-1"></i>Zapisz
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: KOSZTY STAŁE -->
<div class="modal fade" id="fixedCostModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Dodaj koszt stały</h5></div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Nazwa</label>
                    <input class="form-control" id="fc_name">
                </div>
                <div class="mb-2">
                    <label class="form-label">Kwota</label>
                    <input type="number" step="0.01" class="form-control" id="fc_amount" value="0">
                </div>
                <div class="mb-2">
                    <select class="form-select" id="fc_category">
                        <?php foreach(($fixed_cost_categories ?? []) as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" onclick="addFixedCost()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: KOSZT MEDIUM -->
<div class="modal fade" id="mediaCostModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Dodaj koszt medium</h5></div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Medium</label>
                    <select class="form-select" id="mc_media_type">
                        <option value="">— wybierz —</option>
                        <?php foreach ($mediaTypes as $mt): ?>
                            <option value="<?= (int)$mt['id'] ?>"
                                    data-name="<?= htmlspecialchars($mt['name'] ?? '') ?>"
                                    data-unit="<?= htmlspecialchars($mt['unit'] ?? 'PLN') ?>">
                                <?= htmlspecialchars($mt['name'] ?? '') ?> (<?= htmlspecialchars($mt['unit'] ?? 'PLN') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Kwota</label>
                    <input type="number" step="0.01" class="form-control" id="mc_amount" value="0">
                </div>
                <div class="mb-2">
                    <label class="form-label">Notatka</label>
                    <input class="form-control" id="mc_note">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" onclick="addMediaCost()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ALOKACJA NA DZIAŁY -->
<div class="modal fade" id="mediaAllocationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal_title">Udział kosztu medium dla działu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ma_edit_id" value="0">

                <div class="mb-3">
                    <label class="form-label fw-bold">Medium <span class="text-danger">*</span></label>
                    <select class="form-select" id="ma_media_type" onchange="updateAllocationSummary()">
                        <option value="">— wybierz medium —</option>
                        <?php foreach ($mediaTypes as $mt): ?>
                            <option value="<?= (int)$mt['id'] ?>"
                                    data-name="<?= htmlspecialchars($mt['name'] ?? '') ?>">
                                <?= htmlspecialchars($mt['name'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Proste podsumowanie -->
                <div id="allocation_summary_box" class="alert alert-light mb-3" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">Przypisane działy dla tego medium:</small>
                        <span id="allocation_total_display" class="badge bg-secondary">Suma: 0%</span>
                    </div>
                    <div id="allocation_breakdown" class="small mt-2"></div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Dział <span class="text-danger">*</span></label>
                            <select class="form-select" id="ma_section">
                                <option value="">— wybierz dział —</option>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"
                                            data-name="<?= htmlspecialchars($s['name'] ?? '') ?>">
                                        <?= htmlspecialchars($s['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Udział (%) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-lg">
                                <input type="number"
                                       step="0.01"
                                       min="0"
                                       max="100"
                                       class="form-control"
                                       id="ma_percent"
                                       value="0"
                                       placeholder="0.00">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Notatka (opcjonalnie)</label>
                    <input type="text" class="form-control" id="ma_note" placeholder="Opcjonalny opis...">
                </div>

                <!-- Szybkie przyciski -->
                <div class="d-flex gap-2 flex-wrap">
                    <small class="text-muted me-auto">Szybki wybór:</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setQuickPercent(25)">25%</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setQuickPercent(33.33)">33%</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setQuickPercent(50)">50%</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setQuickPercent(100)">100%</button>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" onclick="saveMediaAllocation()">
                    <i class="bi bi-check-lg me-1"></i><span id="modal_save_btn_text">Zapisz</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ZACZYTAJ KARTONY Z WDT -->
<div class="modal fade" id="wdtImportModal" tabindex="-1" aria-labelledby="wdtImportModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="wdtImportModalLabel">
                    <i class="bi bi-file-earmark-arrow-down me-2"></i>Zaczytaj kartony z faktur WDT
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">

                <p class="text-muted small mb-3">
                    Wybierz zakres dat wystawienia faktur WDT. System zsumuje sprzedane kartony
                    per dział i wczyta je jako wolumen dla tego miesiąca kosztowego.<br>
                    <strong>Wpisy ze źródłem MANUAL nie zostaną nadpisane.</strong>
                </p>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label fw-semibold" for="wdt_date_from">Data od <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="wdt_date_from"
                               value="<?= sprintf('%04d-%02d-01', (int)$month['year'], (int)$month['month']) ?>">
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label fw-semibold" for="wdt_date_to">Data do <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="wdt_date_to"
                               value="<?= date('Y-m-t', mktime(0, 0, 0, (int)$month['month'], 1, (int)$month['year'])) ?>">
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <button class="btn btn-outline-secondary w-100" onclick="previewWdtCartons()">
                            <i class="bi bi-eye me-1"></i>Podgląd
                        </button>
                    </div>
                </div>

                <!-- Tabela podglądu -->
                <div id="wdt_preview_area" style="display:none;">
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Podgląd wyników z WDT</h6>
                        <span class="badge bg-secondary" id="wdt_preview_period"></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Dział</th>
                                    <th class="text-end">Kartony z WDT</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody id="wdt_preview_body">
                            </tbody>
                            <tfoot class="table-light fw-semibold">
                                <tr>
                                    <td>Łącznie</td>
                                    <td class="text-end" id="wdt_preview_total">—</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div id="wdt_no_data" class="alert alert-warning" style="display:none;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Brak faktur WDT w wybranym zakresie dat.
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" id="wdt_sync_btn" onclick="syncWdtCartons()" disabled>
                    <i class="bi bi-check-lg me-1"></i>Zapisz do miesiąca
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: KOSZT LOGISTYCZNY -->
<div class="modal fade" id="logisticsModal" tabindex="-1" aria-labelledby="logisticsModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logisticsModalLabel">Dodaj koszt logistyczny</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="log_type">Typ kosztu <span class="text-danger">*</span></label>
                    <select class="form-select" id="log_type">
                        <option value="">— wybierz typ —</option>
                        <?php foreach ($logisticsTypes as $lt): ?>
                            <option value="<?= (int)$lt['id'] ?>"
                                    data-code="<?= htmlspecialchars($lt['code']) ?>"
                                    data-name="<?= htmlspecialchars($lt['name']) ?>">
                                <?= htmlspecialchars($lt['name']) ?>
                                <span class="text-muted">(<?= htmlspecialchars($lt['code']) ?>)</span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="log_amount">Kwota (zł) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" class="form-control" id="log_amount" value="0" placeholder="0,00">
                        <span class="input-group-text">zł</span>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="log_note">Notatka (opcjonalnie)</label>
                    <input type="text" class="form-control" id="log_note" placeholder="np. faktura nr, przewoźnik...">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button class="btn btn-primary" onclick="addLogisticsCost()">
                    <i class="bi bi-check-lg me-1"></i>Zapisz
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: PODSUMOWANIE KOSZTÓW ZAKŁADU PER DZIAŁ -->
<div class="modal fade" id="sectionMediaSummaryModal" tabindex="-1" aria-labelledby="sectionMediaSummaryLabel">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sectionMediaSummaryLabel">
                    <i class="bi bi-pie-chart-fill me-2 text-info"></i>Koszty zakładu — podział na działy
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">

                <?php if (!empty($sectionMediaTotals)): ?>

                <div class="row align-items-start g-4">
                    <!-- Donut chart -->
                    <div class="col-md-5 text-center">
                        <div style="position:relative; max-width:280px; margin:0 auto;">
                            <canvas id="sectionMediaDonut" width="280" height="280"></canvas>
                            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;">
                                <div class="fw-bold fs-5"><?= number_format($grandMediaTotal, 2, ',', ' ') ?></div>
                                <div class="text-muted small">zł łącznie</div>
                            </div>
                        </div>
                        <!-- legenda -->
                        <div id="sectionMediaLegend" class="mt-3 text-start d-flex flex-column gap-1" style="font-size:0.82rem;"></div>
                    </div>

                    <!-- Tabela szczegółowa -->
                    <div class="col-md-7">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Dział</th>
                                <th class="text-end">Kwota (zł)</th>
                                <th class="text-end">Udział</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sectionMediaTotals as $sid => $sec): ?>
                                <?php $pctOfTotal = $grandMediaTotal > 0 ? ($sec['total'] / $grandMediaTotal * 100) : 0; ?>
                                <tr data-bs-toggle="collapse"
                                    data-bs-target="#sec_detail_<?= $sid ?>"
                                    style="cursor:pointer;"
                                    title="Kliknij, aby rozwinąć szczegóły">
                                    <td>
                                        <i class="bi bi-chevron-right me-1 text-muted small sec-chevron-<?= $sid ?>"></i>
                                        <strong><?= htmlspecialchars($sec['name']) ?></strong>
                                    </td>
                                    <td class="text-end fw-semibold"><?= number_format($sec['total'], 2, ',', ' ') ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-light-secondary text-dark"><?= number_format($pctOfTotal, 1, ',', ' ') ?>%</span>
                                    </td>
                                </tr>
                                <tr class="collapse" id="sec_detail_<?= $sid ?>">
                                    <td colspan="3" class="p-0">
                                        <table class="table table-sm mb-0 bg-light">
                                            <tbody>
                                            <?php foreach ($sec['details'] as $d): ?>
                                                <tr>
                                                    <td class="ps-4 text-muted small">
                                                        <i class="bi bi-lightning-charge me-1"></i><?= htmlspecialchars($d['media_name']) ?>
                                                        <span class="text-muted">(<?= number_format($d['pct'], 1, ',', ' ') ?>%)</span>
                                                    </td>
                                                    <td class="text-end small"><?= number_format($d['amount'], 2, ',', ' ') ?> zł</td>
                                                    <td></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                            <tr>
                                <td>Łącznie</td>
                                <td class="text-end"><?= number_format($grandMediaTotal, 2, ',', ' ') ?> zł</td>
                                <td class="text-end">100%</td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    Brak danych — dodaj koszty mediów i przypisz udziały dla działów.
                </div>
                <?php endif; ?>

            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Dane dla donut (generowane po stronie PHP)
    const sectionMediaChartData = {
        labels: <?= json_encode(array_values(array_map(fn($s) => $s['name'], $sectionMediaTotals))) ?>,
        values: <?= json_encode(array_values(array_map(fn($s) => round($s['total'], 2), $sectionMediaTotals))) ?>
    };
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
    // Paleta kolorów dla donut
    const DONUT_COLORS = [
        '#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b',
        '#858796','#5a5c69','#fd7e14','#6f42c1','#20c9a6',
        '#17a2b8','#e83e8c','#28a745','#ffc107','#dc3545',
    ];

    let sectionDonutChart = null;

    function openSectionMediaSummaryModal() {
        const modal = new bootstrap.Modal(document.getElementById('sectionMediaSummaryModal'));
        modal.show();

        // Inicjalizacja wykresu po otwarciu modalu (canvas musi być widoczny)
        document.getElementById('sectionMediaSummaryModal').addEventListener('shown.bs.modal', function handler() {
            this.removeEventListener('shown.bs.modal', handler);
            initSectionDonut();
        });
    }

    function initSectionDonut() {
        const canvas = document.getElementById('sectionMediaDonut');
        if (!canvas) return;

        if (sectionDonutChart) {
            sectionDonutChart.destroy();
            sectionDonutChart = null;
        }

        const labels = sectionMediaChartData.labels;
        const values = sectionMediaChartData.values;
        if (!labels.length) return;

        const colors = labels.map((_, i) => DONUT_COLORS[i % DONUT_COLORS.length]);

        sectionDonutChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.parsed;
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? (val / total * 100).toFixed(1) : '0.0';
                                return ` ${val.toLocaleString('pl-PL', {minimumFractionDigits:2})} zł (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Własna legenda
        const legend = document.getElementById('sectionMediaLegend');
        if (legend) {
            legend.innerHTML = labels.map((lbl, i) => {
                const val = values[i];
                const total = values.reduce((a, b) => a + b, 0);
                const pct = total > 0 ? (val / total * 100).toFixed(1) : '0.0';
                return `<div class="d-flex align-items-center gap-2">
                    <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${colors[i]};flex-shrink:0;"></span>
                    <span class="text-truncate" style="max-width:160px;" title="${lbl}">${lbl}</span>
                    <span class="ms-auto text-muted fw-semibold text-nowrap">${pct}%</span>
                </div>`;
            }).join('');
        }

        // Toggle ikony chevron przy rozwijaniu wierszy
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(row => {
            row.addEventListener('click', function () {
                const target = document.querySelector(this.dataset.bsTarget);
                const chevrons = this.querySelectorAll('[class*="sec-chevron"]');
                if (!target) return;
                const isExpanded = target.classList.contains('show');
                chevrons.forEach(c => {
                    c.classList.toggle('bi-chevron-right', isExpanded);
                    c.classList.toggle('bi-chevron-down', !isExpanded);
                });
            });
        });
    }
</script>

<script>
    const MONTH_ID = <?= (int)$monthId ?>;

    function toast(msg){ alert(msg); }

    async function postJson(url, payload){
        const r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload || {})
        });
        const data = await r.json().catch(()=> ({}));
        if(!r.ok) throw data;
        return data;
    }

    // --- Tabs helpers (hash persistence) ---
    function showTabByHash(hash){
        if(!hash) return false;
        const link = document.querySelector(`a.nav-link[href="${hash}"]`);
        if(!link) return false;
        const tab = new bootstrap.Tab(link);
        tab.show();
        return true;
    }

    document.addEventListener('DOMContentLoaded', () => {
        // on load -> restore hash tab
        showTabByHash(window.location.hash);

        // on tab change -> update hash
        const tabLinks = document.querySelectorAll('#costTab a[data-bs-toggle="tab"]');
        tabLinks.forEach(a => {
            a.addEventListener('shown.bs.tab', (e) => {
                const href = e.target.getAttribute('href');
                if(href && href.startsWith('#')){
                    history.replaceState(null, '', href);
                }
            });
        });
    });

    function activateResultsTab(){
        // uses Mazer-style <a href="#tab-results">
        const link = document.querySelector('a.nav-link[href="#tab-results"]');
        if(!link) return;
        new bootstrap.Tab(link).show();
    }

    function copyPrev(){
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/copy-prev", {})
            .then(()=> location.reload())
            .catch(()=> toast("Nie udało się skopiować"));
    }

    function syncCartons(){
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/sync-cartons", {})
            .then(()=> location.reload())
            .catch(()=> toast("Nie udało się zsynchronizować"));
    }

    function saveManualCartons(sectionId){
        const v = document.getElementById('cartons_'+sectionId).value;
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/set-manual-cartons", {
            section_id: sectionId,
            cartons_qty: parseInt(v||'0',10)
        }).then(()=> location.reload())
            .catch(()=> toast("Błąd zapisu"));
    }

    function openEmployeeModal(){
        const m = new bootstrap.Modal(document.getElementById('employeeModal'));
        m.show();
    }
    function onEmployeePick(){
        const sel = document.getElementById('emp_select');
        const opt = sel.options[sel.selectedIndex];
        if(!opt || !opt.value){
            document.getElementById('emp_section_name').value = '';
            document.getElementById('emp_cost').value = '0';
            return;
        }

        const sectionName = opt.getAttribute('data-section-name') || '';
        const cost = opt.getAttribute('data-cost') || '0';

        document.getElementById('emp_section_name').value = sectionName;
        document.getElementById('emp_cost').value = Number(cost || 0).toFixed(2);
    }

    function addEmployee(){
        const empId = parseInt(document.getElementById('emp_employee_id').value || '0', 10);
        if(!empId){
            toast("Wybierz pracownika");
            return;
        }

        const costVal = document.getElementById('emp_cost').value;
        const payload = { employee_id: empId };

        // tylko jeśli podane
        if(costVal !== '' && !isNaN(Number(costVal))){
            payload.monthly_cost = Number(costVal);
        }
console.log(payload); return;
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/employees/add", payload)
            .then(()=> location.reload())
            .catch(()=> toast("Błąd dodania pracownika"));
    }

    function deleteEmployee(id){
        if(!confirm("Usunąć pracownika?")) return;
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/employees/" + id + "/delete", {})
            .then(()=> location.reload())
            .catch(()=> toast("Błąd usuwania"));
    }

    /**
     * Przelicza sumę wynagrodzeń z aktualnych wartości w tabeli
     * i aktualizuje blok SUMMARY bez przeładowania strony.
     */
    function refreshLaborSummary(){
        const body = document.getElementById('employees_body');
        if(!body) return;

        let total = 0;
        body.querySelectorAll('tr[data-emp-cost]').forEach(tr => {
            const v = parseFloat(tr.getAttribute('data-emp-cost') || '0');
            if(isFinite(v)) total += v;
        });

        const el = document.getElementById('summary_labor_total');
        if(el){
            el.textContent = total.toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }

    function openEditEmployeeCostModal(empRefId){
        const row = document.getElementById('emp_row_' + empRefId);
        if(!row) return;

        const name = row.getAttribute('data-emp-name') || ('Pracownik #' + empRefId);
        const cost = row.getAttribute('data-emp-cost') || '0';

        document.getElementById('eec_emp_ref_id').value = empRefId;
        document.getElementById('eec_emp_name').textContent  = name;
        document.getElementById('eec_cost').value = Number(cost).toFixed(2);

        new bootstrap.Modal(document.getElementById('editEmployeeCostModal')).show();
    }

    function saveEmployeeCost(){
        const empRefId = parseInt(document.getElementById('eec_emp_ref_id').value || '0', 10);
        const costRaw  = document.getElementById('eec_cost').value.toString().replace(',', '.');
        const cost     = parseFloat(costRaw);

        if(!empRefId){
            toast("Brak ID pracownika – odśwież stronę.");
            return;
        }
        if(isNaN(cost) || cost < 0){
            toast("Podaj poprawną kwotę (≥ 0).");
            return;
        }

        const btn = document.getElementById('eec_save_btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zapisuję…';

        postJson(
            "<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/employees/" + empRefId + "/update-cost",
            { monthly_cost: cost }
        ).then(res => {
            // Zaktualizuj komórkę w tabeli bez przeładowania
            const cell = document.getElementById('emp_cost_cell_' + empRefId);
            if(cell){
                cell.innerHTML = '<strong>' + cost.toLocaleString('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' zł</strong>';
            }
            // Zaktualizuj data-atrybut wiersza
            const row = document.getElementById('emp_row_' + empRefId);
            if(row) row.setAttribute('data-emp-cost', cost.toFixed(2));

            // Odśwież sumę wynagrodzeń w bloku SUMMARY
            refreshLaborSummary();

            // Zamknij modal
            const modalEl = document.getElementById('editEmployeeCostModal');
            bootstrap.Modal.getInstance(modalEl)?.hide();

            // Subtelne podświetlenie wiersza
            if(row){
                row.classList.add('table-success');
                setTimeout(() => row.classList.remove('table-success'), 1800);
            }
        }).catch(err => {
            const msg = err?.message || '';
            if(msg === 'MONTH_LOCKED'){
                toast("Miesiąc jest zamknięty – otwórz go przed edycją.");
            } else if(msg === 'INVALID_COST'){
                toast("Nieprawidłowa kwota.");
            } else {
                toast("Błąd zapisu wynagrodzenia.");
            }
        }).finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Zapisz';
        });
    }

    function openFixedCostModal(){
        const m = new bootstrap.Modal(document.getElementById('fixedCostModal'));
        m.show();
    }
    function addFixedCost(){
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/fixed-costs/add", {
            name: document.getElementById('fc_name').value,
            amount: document.getElementById('fc_amount').value,
            category: document.getElementById('fc_category').value,
            service_month: "<?= htmlspecialchars($ym) ?>"
        }).then(()=> location.reload())
            .catch(()=> toast("Błąd dodania kosztu"));
    }
    function deleteFixedCost(id){
        if(!confirm("Usunąć koszt?")) return;
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/fixed-costs/" + id + "/delete", {})
            .then(()=> location.reload())
            .catch(()=> toast("Błąd usuwania"));
    }

    function openMediaCostModal(){
        const m = new bootstrap.Modal(document.getElementById('mediaCostModal'));
        m.show();
    }

    function addMediaCost(){
        const mediaTypeId = parseInt(document.getElementById('mc_media_type').value || '0', 10);
        if(!mediaTypeId){
            toast("Wybierz medium");
            return;
        }
        const amount = document.getElementById('mc_amount').value;
        const note = document.getElementById('mc_note').value;

        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/media-costs/add", {
            media_type_id: mediaTypeId,
            amount: amount,
            note: note
        }).then((res)=> {
            appendMediaCostRow({
                id: res.id || 0,
                media_type_id: mediaTypeId,
                amount: Number(amount || 0),
                note: note || ''
            });
            resetMediaCostForm();
            const modalEl = document.getElementById('mediaCostModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if(modal) modal.hide();
        }).catch(()=> toast("Błąd dodania kosztu"));
    }

    function resetMediaCostForm(){
        document.getElementById('mc_media_type').value = '';
        document.getElementById('mc_amount').value = '0';
        document.getElementById('mc_note').value = '';
    }

    function appendMediaCostRow(row){
        const body = document.querySelector('tbody#media_costs_body');
        if(!body) return;

        const opt = document.querySelector(`#mc_media_type option[value="${row.media_type_id}"]`);
        const mediaName = opt ? (opt.getAttribute('data-name') || opt.textContent.trim()) : '';
        const unit = opt ? (opt.getAttribute('data-unit') || 'PLN') : 'PLN';

        // update existing (upsert)
        const existing = body.querySelector(`tr[data-media-id="${row.media_type_id}"]`);
        if(existing){
            existing.setAttribute('data-row-id', String(row.id || 0));
            existing.innerHTML = `
                <td>#${row.id}</td>
                <td>${escapeHtml(mediaName)}</td>
                <td>${escapeHtml(unit || 'PLN')}</td>
                <td class="text-end"><strong>${fmt2(row.amount)}</strong></td>
                <td>${escapeHtml(row.note || '')}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteMediaCost(${row.media_type_id})">Usuń</button>
                </td>
            `;
            return;
        }

        // usuń placeholder "Brak wpisów"
        const emptyRow = body.querySelector('tr[data-empty="1"]');
        if(emptyRow) emptyRow.remove();

        const tr = document.createElement('tr');
        tr.setAttribute('data-media-id', String(row.media_type_id));
        tr.setAttribute('data-row-id', String(row.id || 0));
        tr.innerHTML = `
            <td>#${row.id}</td>
            <td>${escapeHtml(mediaName)}</td>
            <td>${escapeHtml(unit || 'PLN')}</td>
            <td class="text-end"><strong>${fmt2(row.amount)}</strong></td>
            <td>${escapeHtml(row.note || '')}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-danger" onclick="deleteMediaCost(${row.media_type_id})">Usuń</button>
            </td>
        `;
        body.appendChild(tr);
    }

    function deleteMediaCost(id){
        if(!confirm("Usunąć koszt medium?")) return;
        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/media-costs/" + id + "/delete", {})
            .then(()=> {
                const body = document.querySelector('tbody#media_costs_body');
                if(body){
                    const row = body.querySelector(`tr[data-row-id="${id}"]`);
                    if(row) row.remove();
                    if(!body.querySelector('tr[data-media-id]')){
                        const tr = document.createElement('tr');
                        tr.setAttribute('data-empty','1');
                        tr.innerHTML = '<td colspan="6" class="text-muted">Brak wpisów</td>';
                        body.appendChild(tr);
                    }
                }
            })
            .catch(()=> toast("Błąd usuwania"));
    }

    function deleteMediaAllocation(id){
        if(!confirm("Usunąć koszt medium?")) return;
        api(`/ajax/cost-calculator/${MONTH_ID}/media-allocations/${id}`, {method: "DELETE"})
            .then(()=> {
                const body = document.querySelector('tbody#media_allocations_body');
                if(body){
                    const row = body.querySelector(`tr[data-row-id="${id}"]`);
                    if(row) row.remove();
                    if(!body.querySelector('tr')){
                        const tr = document.createElement('tr');
                        tr.setAttribute('data-empty','1');
                        tr.innerHTML = '<td colspan="6" class="text-muted">Brak wpisów</td>';
                        body.appendChild(tr);
                    }
                }
            })
            .catch(()=> toast("Błąd usuwania"));
    }

    function saveMediaAllocation(){
        const mediaTypeId = parseInt(document.getElementById('ma_media_type').value || '0', 10);
        const sectionId = parseInt(document.getElementById('ma_section').value || '0', 10);
        const percentRaw = document.getElementById('ma_percent').value;
        const note = document.getElementById('ma_note').value;
        const editId = parseInt(document.getElementById('ma_edit_id').value || '0', 10);

        if(!mediaTypeId || !sectionId){
            toast("Wybierz medium i dział");
            return;
        }

        const percent = Number((percentRaw || '0').toString().replace(',', '.'));
        if(!isFinite(percent) || percent < 0 || percent > 100){
            toast("Udział musi być w zakresie 0-100%");
            return;
        }

        // Walidacja sumy <= 100%
        const body = document.querySelector('tbody#media_allocations_body');
        let sumOther = 0;
        if(body){
            body.querySelectorAll('tr[data-media-id]').forEach(tr => {
                const mt = parseInt(tr.getAttribute('data-media-id') || '0', 10);
                const rowId = parseInt(tr.getAttribute('data-row-id') || '0', 10);
                if(mt !== mediaTypeId) return;
                if(rowId === editId) return; // pomijamy edytowany wpis
                
                const cell = tr.querySelector('td:nth-child(3) strong');
                const raw = cell ? cell.textContent : '0';
                const val = Number(raw.replace(/\s/g,'').replace(',', '.').replace('%', ''));
                if(isFinite(val)) sumOther += val;
            });
        }

        const totalSum = sumOther + percent;
        if(totalSum > 100.01){
            const maxAllowed = Math.max(0, 100 - sumOther);
            toast(`Przekroczenie 100%! Aktualna suma dla tego medium: ${fmt2(sumOther)}%. Maksymalnie możesz przypisać: ${fmt2(maxAllowed)}%`);
            return;
        }

        postJson("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/media-allocations/upsert", {
            media_type_id: mediaTypeId,
            section_id: sectionId,
            allocation_percent: percent,
            note: note
        }).then((res)=> {
            appendOrUpdateMediaAllocationRow({
                id: res.id || 0,
                media_type_id: mediaTypeId,
                section_id: sectionId,
                allocation_percent: Number(percent || 0),
                note: note || ''
            });
            resetMediaAllocationForm();
            const modalEl = document.getElementById('mediaAllocationModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if(modal) modal.hide();
        }).catch((err)=> {
            const msg = err?.message || '';
            if (msg === 'ALLOCATION_OUT_OF_RANGE') {
                toast("Udział musi być w zakresie 0-100%");
                return;
            }
            if (msg === 'ALLOCATION_SUM_EXCEEDED') {
                const maxAllowed = err?.max_allowed || 0;
                const currentSum = err?.current_sum || 0;
                toast(`Przekroczenie 100%! Aktualna suma: ${fmt2(currentSum)}%. Możesz przypisać max: ${fmt2(maxAllowed)}%`);
                return;
            }
            toast("Błąd zapisu udziału");
        });
    }

    function resetMediaAllocationForm(){
        document.getElementById('ma_edit_id').value = '0';
        document.getElementById('ma_media_type').value = '';
        document.getElementById('ma_section').value = '';
        document.getElementById('ma_percent').value = '0';
        document.getElementById('ma_note').value = '';
        document.getElementById('allocation_summary_box').style.display = 'none';
        document.getElementById('modal_title').textContent = 'Udział kosztu medium dla działu';
        document.getElementById('modal_save_btn_text').textContent = 'Zapisz';
    }

    function editMediaAllocation(id){
        const row = document.querySelector(`tr[data-row-id="${id}"]`);
        if(!row) return;

        const mediaId = parseInt(row.getAttribute('data-media-id') || '0', 10);
        const sectionId = parseInt(row.getAttribute('data-section-id') || '0', 10);
        const percent = parseFloat(row.getAttribute('data-percent') || '0');
        const note = row.getAttribute('data-note') || '';

        document.getElementById('ma_edit_id').value = id;
        document.getElementById('ma_media_type').value = mediaId;
        document.getElementById('ma_section').value = sectionId;
        document.getElementById('ma_percent').value = percent;
        document.getElementById('ma_note').value = note;

        updateAllocationSummary();

        document.getElementById('modal_title').textContent = 'Edycja udziału';
        document.getElementById('modal_save_btn_text').textContent = 'Zapisz zmiany';

        const m = new bootstrap.Modal(document.getElementById('mediaAllocationModal'));
        m.show();
    }

    function openMediaAllocationModal(){
        resetMediaAllocationForm();
        const m = new bootstrap.Modal(document.getElementById('mediaAllocationModal'));
        m.show();
    }

    function updateAllocationSummary(){
        const mediaTypeId = parseInt(document.getElementById('ma_media_type').value || '0', 10);
        const editId = parseInt(document.getElementById('ma_edit_id').value || '0', 10);

        if(!mediaTypeId){
            document.getElementById('allocation_summary_box').style.display = 'none';
            return;
        }

        const body = document.querySelector('tbody#media_allocations_body');
        let sum = 0;
        let breakdownHtml = '';
        let count = 0;

        if(body){
            body.querySelectorAll('tr[data-media-id]').forEach(tr => {
                const mt = parseInt(tr.getAttribute('data-media-id') || '0', 10);
                const rowId = parseInt(tr.getAttribute('data-row-id') || '0', 10);
                if(mt !== mediaTypeId) return;
                if(rowId === editId) return; // pomijamy edytowany wpis

                const sectionNameCell = tr.querySelector('td:nth-child(2)');
                const sectionName = sectionNameCell ? sectionNameCell.textContent.trim() : 'Dział';

                const percentCell = tr.querySelector('td:nth-child(3) strong');
                const raw = percentCell ? percentCell.textContent : '0';
                const val = Number(raw.replace(/\s/g,'').replace(',', '.').replace('%', ''));

                if(isFinite(val) && val > 0){
                    sum += val;
                    count++;
                    breakdownHtml += `<div class="d-flex justify-content-between border-bottom py-1">
                        <span>${escapeHtml(sectionName)}</span>
                        <strong>${fmt2(val)}%</strong>
                    </div>`;
                }
            });
        }

        if(count > 0){
            document.getElementById('allocation_summary_box').style.display = 'block';
            document.getElementById('allocation_breakdown').innerHTML = breakdownHtml;
            document.getElementById('allocation_total_display').textContent = `Suma: ${fmt2(sum)}%`;
        } else {
            document.getElementById('allocation_summary_box').style.display = 'block';
            document.getElementById('allocation_breakdown').innerHTML = '<div class="text-muted small">Brak przypisanych działów</div>';
            document.getElementById('allocation_total_display').textContent = 'Suma: 0%';
        }
    }

    function setQuickPercent(value){
        document.getElementById('ma_percent').value = value;
    }

    function openMediaAllocationModal(){
        resetMediaAllocationForm();
        const m = new bootstrap.Modal(document.getElementById('mediaAllocationModal'));
        m.show();
    }

    function appendOrUpdateMediaAllocationRow(row){
        const body = document.querySelector('tbody#media_allocations_body');
        if(!body) return;

        const mtOpt = document.querySelector(`#ma_media_type option[value="${row.media_type_id}"]`);
        const secOpt = document.querySelector(`#ma_section option[value="${row.section_id}"]`);
        const mediaName = mtOpt ? (mtOpt.getAttribute('data-name') || mtOpt.textContent.trim()) : '';
        const sectionName = secOpt ? (secOpt.getAttribute('data-name') || secOpt.textContent.trim()) : '';

        const existing = body.querySelector(`tr[data-media-id="${row.media_type_id}"][data-section-id="${row.section_id}"]`);
        const html = `
            <td>${escapeHtml(mediaName)}</td>
            <td>${escapeHtml(sectionName)}</td>
            <td class="text-end"><strong>${fmt2(row.allocation_percent)}%</strong></td>
            <td>${escapeHtml(row.note || '')}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary me-1"
                        onclick="editMediaAllocation(${row.id})"
                        title="Edytuj">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger"
                        onclick="deleteMediaAllocation(${row.id})"
                        title="Usuń">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        `;

        if(existing){
            existing.setAttribute('data-row-id', String(row.id || 0));
            existing.setAttribute('data-percent', String(row.allocation_percent || 0));
            existing.setAttribute('data-note', String(row.note || ''));
            existing.innerHTML = html;
            existing.classList.add('table-success');
            setTimeout(() => existing.classList.remove('table-success'), 1500);
            refreshMediaAllocationSummaries();
            return;
        }

        const emptyRow = body.querySelector('tr[data-empty="1"]');
        if(emptyRow) emptyRow.remove();

        const tr = document.createElement('tr');
        tr.setAttribute('data-media-id', String(row.media_type_id));
        tr.setAttribute('data-section-id', String(row.section_id));
        tr.setAttribute('data-row-id', String(row.id || 0));
        tr.setAttribute('data-media-name', escapeHtml(mediaName));
        tr.setAttribute('data-section-name', escapeHtml(sectionName));
        tr.setAttribute('data-percent', String(row.allocation_percent || 0));
        tr.setAttribute('data-note', String(row.note || ''));
        tr.innerHTML = html;
        tr.classList.add('table-success');
        body.appendChild(tr);
        setTimeout(() => tr.classList.remove('table-success'), 1500);
        refreshMediaAllocationSummaries();
    }

    function deleteMediaAllocation(id){
        if(!confirm("Usunąć alokację kosztu medium?")) return;

        // Używamy fetch z metodą DELETE bo jest obsługiwana w routingu
        fetch("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/media-allocations/" + id, {
            method: 'DELETE',
            headers: {'Content-Type': 'application/json'}
        })
            .then(r => {
                if(!r.ok) throw new Error('Request failed');
                return r.json().catch(() => ({}));
            })
            .then(()=> {
                const body = document.querySelector('tbody#media_allocations_body');
                if(body){
                    const row = body.querySelector(`tr[data-row-id="${id}"]`);
                    if(row) {
                        row.classList.add('table-danger');
                        setTimeout(() => {
                            row.remove();
                            if(!body.querySelector('tr[data-media-id]')){
                                const tr = document.createElement('tr');
                                tr.setAttribute('data-empty','1');
                                tr.innerHTML = '<td colspan="5" class="text-muted">Brak wpisów</td>';
                                body.appendChild(tr);
                            }
                            refreshMediaAllocationSummaries();
                        }, 300);
                    }
                }
            })
            .catch(()=> toast("Błąd usuwania alokacji"));
    }

    function refreshMediaAllocationSummaries(){
        const body = document.querySelector('tbody#media_allocations_body');
        if(!body) return;

        const summaries = {};
        
        body.querySelectorAll('tr[data-media-id]').forEach(tr => {
            const mtId = parseInt(tr.getAttribute('data-media-id') || '0', 10);
            if(!mtId) return;

            const mediaNameCell = tr.querySelector('td:nth-child(1)');
            const mediaName = mediaNameCell ? mediaNameCell.textContent.trim() : `Medium #${mtId}`;

            const percentCell = tr.querySelector('td:nth-child(3) strong');
            const raw = percentCell ? percentCell.textContent : '0';
            const val = Number(raw.replace(/\s/g,'').replace(',', '.').replace('%', ''));

            if(!summaries[mtId]){
                summaries[mtId] = { name: mediaName, total: 0, count: 0 };
            }
            if(isFinite(val)){
                summaries[mtId].total += val;
                summaries[mtId].count++;
            }
        });

        const container = document.getElementById('media_allocation_summaries');
        if(!container) return;

        let html = '';
        const keys = Object.keys(summaries);
        if(keys.length === 0){
            html = `<div class="alert alert-light">
                <i class="bi bi-info-circle me-2"></i>
                <span class="text-muted">Brak przypisanych udziałów. Dodaj udział dla medium, aby rozdzielić jego koszt na działy.</span>
            </div>`;
        } else {
            keys.forEach(mtId => {
                const info = summaries[mtId];
                const total = info.total;
                const count = info.count;
                
                let statusClass = 'bg-secondary';
                let alertClass = 'alert-light';
                let warningHtml = '';
                
                if (total > 100.01) {
                    statusClass = 'bg-danger';
                    alertClass = 'alert-danger';
                    warningHtml = '<br><small class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Przekroczenie! Usuń lub zmniejsz udziały.</small>';
                } else if (Math.abs(total - 100) < 0.5) {
                    statusClass = 'bg-success';
                    alertClass = 'alert-success';
                } else if (total > 90) {
                    statusClass = 'bg-warning text-dark';
                    alertClass = 'alert-warning';
                }

                const dzialTxt = count == 1 ? 'dział' : 'działów';

                html += `<div class="alert ${alertClass} mb-2 py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${escapeHtml(info.name)}</strong>
                            <small class="text-muted ms-2">(${count} ${dzialTxt})</small>
                            ${warningHtml}
                        </div>
                        <span class="badge ${statusClass}">Suma: ${fmt1(total)}%</span>
                    </div>
                </div>`;
            });
        }

        container.innerHTML = html;
    }

    function refreshResults(){
        fetch("<?= ROOT ?>/ajax/cost-calculator/" + MONTH_ID + "/results")
            .then(r => r.json())
            .then(data => {
                const body = document.getElementById('results_body');
                body.innerHTML = '';
                (data.rows || []).forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                      <td>#${r.product_id}</td>
                      <td>${escapeHtml(r.product_name)}</td>
                      <td>${r.section_name}</td>
                      <td class="text-end">${r.ppc}</td>
                      <td class="text-end">${fmt2(r.raw_per_carton)}</td>
                      <td class="text-end">${fmt2(r.labor_per_carton)}</td>
                      <td class="text-end">${fmt2(r.media_per_carton || 0)}</td>
                      <td class="text-end">${fmt2(r.fixed_per_carton)}</td>
                      <td class="text-end"><strong>${fmt2(r.total_per_carton)}</strong></td>
                      <td class="text-end"><strong>${fmt4(r.total_per_piece)}</strong></td>
                    `;
                    body.appendChild(tr);
                });

                // UX: po odświeżeniu wyników pokaż zakładkę Wyniki
                activateResultsTab();
            })
            .catch(()=> toast("Nie udało się odświeżyć wyników"));
    }

    function fmt1(v){ return (Number(v)||0).toLocaleString('pl-PL',{minimumFractionDigits:1, maximumFractionDigits:1}); }
    function fmt2(v){ return (Number(v)||0).toLocaleString('pl-PL',{minimumFractionDigits:2, maximumFractionDigits:2}); }
    function fmt4(v){ return (Number(v)||0).toLocaleString('pl-PL',{minimumFractionDigits:4, maximumFractionDigits:4}); }
    function escapeHtml(str){
        return String(str||'').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
    }

    // ── LOGISTYKA ────────────────────────────────────────────────────────────

    function openLogisticsModal(){
        document.getElementById('log_type').value   = '';
        document.getElementById('log_amount').value = '0';
        document.getElementById('log_note').value   = '';
        new bootstrap.Modal(document.getElementById('logisticsModal')).show();
    }

    function addLogisticsCost(){
        const typeId = parseInt(document.getElementById('log_type').value || '0', 10);
        if (!typeId) { toast("Wybierz typ kosztu logistycznego"); return; }

        const amount = parseFloat(document.getElementById('log_amount').value || '0');
        if (isNaN(amount) || amount < 0) { toast("Podaj poprawną kwotę"); return; }

        const note = document.getElementById('log_note').value.trim();

        postJson(`<?= ROOT ?>/ajax/cost-calculator/${MONTH_ID}/logistics/add`, {
            logistics_type_id: typeId,
            amount: amount,
            note: note || null
        }).then(res => {
            const opt = document.querySelector(`#log_type option[value="${typeId}"]`);
            appendLogisticsCostRow({
                id:        res.id || 0,
                type_name: opt ? opt.getAttribute('data-name') : '',
                type_code: opt ? opt.getAttribute('data-code') : '',
                amount:    amount,
                note:      note
            });
            bootstrap.Modal.getInstance(document.getElementById('logisticsModal')).hide();
        }).catch(() => toast("Błąd dodania kosztu logistycznego"));
    }

    function appendLogisticsCostRow(row){
        const body = document.getElementById('logistics_costs_body');
        if (!body) return;

        // usuń placeholder
        const empty = body.querySelector('tr[data-empty="1"]');
        if (empty) empty.remove();

        const tr = document.createElement('tr');
        tr.setAttribute('data-log-id', String(row.id));
        tr.innerHTML = `
            <td>#${row.id}</td>
            <td>${escapeHtml(row.type_name)}</td>
            <td><span class="badge bg-light-secondary text-dark">${escapeHtml(row.type_code)}</span></td>
            <td class="text-end"><strong>${fmt2(row.amount)} zł</strong></td>
            <td>${escapeHtml(row.note || '')}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-danger" onclick="deleteLogisticsCost(${row.id})">Usuń</button>
            </td>
        `;
        body.appendChild(tr);
        refreshLogisticsTotal();
    }

    function deleteLogisticsCost(id){
        if (!confirm("Usunąć koszt logistyczny?")) return;
        postJson(`<?= ROOT ?>/ajax/cost-calculator/${MONTH_ID}/logistics/${id}/delete`, {})
            .then(() => {
                const body = document.getElementById('logistics_costs_body');
                const row  = body ? body.querySelector(`tr[data-log-id="${id}"]`) : null;
                if (row) { row.classList.add('table-danger'); setTimeout(() => { row.remove(); refreshLogisticsTotal(); }, 300); }
            })
            .catch(() => toast("Błąd usuwania kosztu logistycznego"));
    }

    function refreshLogisticsTotal(){
        const body = document.getElementById('logistics_costs_body');
        if (!body) return;
        let sum = 0;
        body.querySelectorAll('tr[data-log-id]').forEach(tr => {
            const cell = tr.querySelector('td:nth-child(4) strong');
            if (cell) {
                const v = parseFloat(cell.textContent.replace(/\s/g,'').replace(',','.').replace('zł',''));
                if (isFinite(v)) sum += v;
            }
        });
        const totalCell = document.getElementById('logistics_total_cell');
        if (totalCell) totalCell.textContent = fmt2(sum) + ' zł';

        if (!body.querySelector('tr[data-log-id]')) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-empty','1');
            tr.innerHTML = '<td colspan="6" class="text-muted text-center py-3">Brak wpisów — dodaj pierwszy koszt logistyczny</td>';
            body.appendChild(tr);
        }
    }

    // ── ZMIANA ŹRÓDŁA KARTONÓW ───────────────────────────────────────────────

    function setCartonSource(sectionId, newSource) {
        const badgeEl = document.getElementById('source_badge_' + sectionId);
        const sources = { MANUAL: 'bg-light-warning', AUTO: 'bg-light-info', WDT: 'bg-light-success' };

        postJson(`<?= ROOT ?>/ajax/cost-calculator/${MONTH_ID}/set-carton-source`, {
            section_id: sectionId,
            source:     newSource,
        }).then(res => {
            if (!res.success) { toast('Nie udało się zmienić źródła.'); return; }

            // Zaktualizuj badge w DOM
            if (badgeEl) {
                // usuń wszystkie klasy bg-light-*
                Object.values(sources).forEach(cls => badgeEl.classList.remove(cls));
                badgeEl.classList.add(sources[newSource] || 'bg-light-info');
                badgeEl.textContent = newSource;
            }

            // Odśwież aktywny/disabled w dropdown
            const row = document.getElementById('section_row_' + sectionId);
            if (row) {
                row.querySelectorAll('.dropdown-menu .dropdown-item').forEach(item => {
                    const txt = item.textContent.trim().replace(' ✓', '');
                    if (txt === newSource) {
                        item.classList.add('active', 'disabled');
                        if (!item.textContent.includes('✓')) item.textContent += ' ✓';
                    } else {
                        item.classList.remove('active', 'disabled');
                        item.textContent = item.textContent.replace(' ✓', '');
                    }
                });
            }

            toast(`Źródło zmienione na ${newSource}.`);
        }).catch(err => {
            const msg = err?.message || '';
            if (msg === 'MONTH_LOCKED') {
                toast('Miesiąc jest zamknięty.');
            } else {
                toast('Błąd zmiany źródła: ' + msg);
            }
        });
    }

    // ── ZACZYTYWANIE KARTONÓW Z WDT ─────────────────────────────────────────

    let _wdtPreviewRows = [];

    function openWdtImportModal(){
        _wdtPreviewRows = [];
        document.getElementById('wdt_preview_area').style.display  = 'none';
        document.getElementById('wdt_no_data').style.display        = 'none';
        document.getElementById('wdt_sync_btn').disabled            = true;
        document.getElementById('wdt_preview_body').innerHTML       = '';
        document.getElementById('wdt_preview_total').textContent    = '—';
        document.getElementById('wdt_preview_period').textContent   = '';
        new bootstrap.Modal(document.getElementById('wdtImportModal')).show();
    }

    function previewWdtCartons(){
        const dateFrom = document.getElementById('wdt_date_from').value;
        const dateTo   = document.getElementById('wdt_date_to').value;

        if (!dateFrom || !dateTo) { toast('Podaj zakres dat.'); return; }
        if (dateFrom > dateTo)    { toast('Data od musi być ≤ data do.'); return; }

        document.getElementById('wdt_preview_area').style.display  = 'none';
        document.getElementById('wdt_no_data').style.display        = 'none';
        document.getElementById('wdt_sync_btn').disabled            = true;
        document.getElementById('wdt_preview_body').innerHTML       = '<tr><td colspan="3" class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Wczytuję…</td></tr>';
        document.getElementById('wdt_preview_area').style.display  = 'block';

        postJson(`<?= ROOT ?>/ajax/cost-calculator/${MONTH_ID}/wdt-cartons/preview`, {
            date_from: dateFrom,
            date_to:   dateTo,
        }).then(res => {
            _wdtPreviewRows = res.rows || [];
            const body = document.getElementById('wdt_preview_body');
            body.innerHTML = '';

            document.getElementById('wdt_preview_period').textContent = `${dateFrom} – ${dateTo}`;

            if (!_wdtPreviewRows.length) {
                document.getElementById('wdt_no_data').style.display = 'block';
                document.getElementById('wdt_preview_total').textContent = '0';
                document.getElementById('wdt_sync_btn').disabled = true;
                return;
            }

            _wdtPreviewRows.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtml(row.section_name || `Dział #${row.section_id}`)}</td>
                    <td class="text-end fw-semibold">${Number(row.cartons_qty).toLocaleString('pl-PL')}</td>
                    <td class="text-center"><span class="badge bg-light-success text-dark">WDT</span></td>
                `;
                body.appendChild(tr);
            });

            document.getElementById('wdt_preview_total').textContent =
                Number(res.total || 0).toLocaleString('pl-PL');
            document.getElementById('wdt_sync_btn').disabled = false;

        }).catch(err => {
            document.getElementById('wdt_preview_body').innerHTML =
                '<tr><td colspan="3" class="text-danger text-center">Błąd pobierania danych.</td></tr>';
            toast('Błąd podglądu WDT: ' + (err?.message || ''));
        });
    }

    function syncWdtCartons(){
        if (!_wdtPreviewRows.length) { toast('Najpierw wykonaj podgląd.'); return; }

        const dateFrom = document.getElementById('wdt_date_from').value;
        const dateTo   = document.getElementById('wdt_date_to').value;

        if (!confirm(
            `Zapisać kartony z WDT do miesiąca <?= htmlspecialchars($ym) ?>?\n\n` +
            `Zakres: ${dateFrom} – ${dateTo}\n` +
            `Łącznie działów: ${_wdtPreviewRows.length}\n\n` +
            `Wpisy ze źródłem MANUAL nie zostaną nadpisane.`
        )) return;

        document.getElementById('wdt_sync_btn').disabled = true;

        postJson(`<?= ROOT ?>/ajax/cost-calculator/${MONTH_ID}/wdt-cartons/sync`, {
            date_from: dateFrom,
            date_to:   dateTo,
        }).then(res => {
            bootstrap.Modal.getInstance(document.getElementById('wdtImportModal')).hide();

            let msg = `✓ Zapisano kartony dla ${res.saved} działów (źródło: WDT).`;
            if (res.skipped_manual > 0) {
                msg += `\n⚠ Pominięto ${res.skipped_manual} działów z wpisem MANUAL.`;
            }
            alert(msg);
            location.reload();
        }).catch(err => {
            document.getElementById('wdt_sync_btn').disabled = false;
            const msg = err?.message || '';
            if (msg === 'MONTH_LOCKED') {
                toast('Miesiąc jest zamknięty – otwórz go przed edycją.');
            } else {
                toast('Błąd zapisu danych WDT: ' + msg);
            }
        });
    }

    // ── ZAMYKANIE / OTWIERANIE MIESIĄCA ──────────────────────────────────────

    function lockMonth(){
        if (!confirm(
            'Czy na pewno chcesz zamknąć miesiąc <?= htmlspecialchars($ym) ?>?\n\n' +
            'Po zamknięciu:\n' +
            '• Dane zostaną zatwierdzone i staną się podstawą kalkulacji marży produktów.\n' +
            '• Edycja kosztów zostanie zablokowana.\n' +
            '• Miesiąc można ponownie otworzyć w razie potrzeby.'
        )) return;

        postJson('<?= ROOT ?>/ajax/cost-calculator/<?= $monthId ?>/lock', {})
            .then(res => {
                if (res.warnings && res.warnings.length > 0) {
                    alert('Miesiąc zamknięty.\n\nUwagi:\n• ' + res.warnings.join('\n• '));
                }
                location.reload();
            })
            .catch(err => toast('Błąd zamykania miesiąca: ' + (err?.message || '')));
    }

    function unlockMonth(){
        if (!confirm(
            'Czy na pewno chcesz ponownie otworzyć miesiąc <?= htmlspecialchars($ym) ?>?\n\n' +
            'Miesiąc zostanie odblokowany – będzie można edytować koszty.\n' +
            'Nie będzie wliczany do kalkulacji marży produktów do czasu ponownego zamknięcia.'
        )) return;

        postJson('<?= ROOT ?>/ajax/cost-calculator/<?= $monthId ?>/unlock', {})
            .then(() => location.reload())
            .catch(err => toast('Błąd otwierania miesiąca: ' + (err?.message || '')));
    }
</script>

<?php $this->view("page_components/footer"); ?>
