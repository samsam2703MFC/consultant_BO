<?php
    // Grupowanie pracowników po dziale, posortowanych alfabetycznie (nazwisko, imię)
    $groupedRows = [];
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $section = $row['section_name'] ?? 'Brak działu';
            $groupedRows[$section][] = $row;
        }
        ksort($groupedRows); // działy alfabetycznie
        foreach ($groupedRows as $section => &$employees) {
            usort($employees, function($a, $b) {
                $cmp = strcoll($a['surname'], $b['surname']);
                return $cmp !== 0 ? $cmp : strcoll($a['name'], $b['name']);
            });
        }
        unset($employees);
    }
?>
<?php $this->view("page_components/header"); ?>

<style>
    .sortable th.sortable-col {
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
    }
    .sortable th.sortable-col:hover {
        background-color: rgba(0,0,0,.05);
    }
    .sort-icon {
        display: inline-block;
        margin-left: 4px;
        color: #adb5bd;
        font-size: .75rem;
        vertical-align: middle;
    }
    .sort-icon.asc::after  { content: "▲"; color: #495057; }
    .sort-icon.desc::after { content: "▼"; color: #495057; }
    .sort-icon:not(.asc):not(.desc)::after { content: "⇅"; }
    .section-group-header td {
        background-color: #f0f4ff;
        font-weight: 600;
        font-size: .95rem;
        letter-spacing: .02em;
        color: #3b5bdb;
        border-top: 2px solid #3b5bdb;
        padding: 8px 12px;
    }
    .section-group-header .badge-count {
        font-weight: 400;
        font-size: .8rem;
        background: #3b5bdb;
        color: #fff;
        border-radius: 20px;
        padding: 2px 8px;
        margin-left: 8px;
        vertical-align: middle;
    }
    .section-block-hidden { display: none; }
    #searchInput:focus { box-shadow: 0 0 0 .2rem rgba(59,91,219,.25); border-color: #3b5bdb; }
    .btn-collapse-section { font-size:.75rem; padding:2px 8px; margin-left:8px; vertical-align:middle; }
</style>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Pracownicy produkcji</h3>
                    <p class="text-subtitle text-muted">Pogrupowani per dział, posortowani alfabetycznie.</p>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Pracownicy produkcji</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h4 class="card-title mb-0">Lista pracowników produkcji</h4>
                    <a href="<?=ROOT?>/production-employees/new" class="btn btn-primary" role="button">
                        <i class="bi bi-person-plus-fill me-1"></i> Dodaj nowego pracownika
                    </a>
                </div>
                <div class="card-body">
                    <!-- Filtry -->
                    <div class="row mt-3 mb-4 g-2 align-items-end">
                        <div class="col-12 col-sm-4 col-lg-3">
                            <label class="form-label fw-semibold small text-muted mb-1">Szukaj po nazwisku / imieniu</label>
                            <input id="searchInput" class="form-control" type="text"
                                   placeholder="Wpisz nazwisko lub imię..."
                                   oninput="applyFilters()">
                        </div>
                        <div class="col-12 col-sm-4 col-lg-3">
                            <label class="form-label fw-semibold small text-muted mb-1">Filtruj po dziale</label>
                            <select id="sectionFilter" class="form-select" onchange="applyFilters()">
                                <option value="">Wszystkie działy</option>
                                <?php if(!empty($production_sections)): ?>
                                    <?php foreach($production_sections as $section): ?>
                                        <option value="<?= htmlspecialchars($section['name']) ?>">
                                            <?= htmlspecialchars($section['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-4 col-lg-2">
                            <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                                Resetuj filtry
                            </button>
                        </div>
                        <div class="col-12 col-lg-4 text-end">
                            <span class="text-muted small">
                                Wyświetlono: <strong id="visibleCount">0</strong> z <strong><?= count($rows ?? []) ?></strong> pracowników
                            </span>
                        </div>
                    </div>

                    <!-- Tabela -->
                    <div class="table-responsive">
                        <table class="table table-hover sortable" id="employeeTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="sortable-col" data-col="0">
                                        Nazwisko <span class="sort-icon asc" id="sort-0"></span>
                                    </th>
                                    <th class="sortable-col" data-col="1">
                                        Imię <span class="sort-icon" id="sort-1"></span>
                                    </th>
                                    <th class="sortable-col" data-col="2">
                                        Dział <span class="sort-icon" id="sort-2"></span>
                                    </th>
                                    <th class="sortable-col" data-col="3">
                                        Koszt [zł] <span class="sort-icon" id="sort-3"></span>
                                    </th>
                                    <th style="width:160px">Akcje</th>
                                </tr>
                            </thead>
                            <tbody id="id_production_employee">
                            <?php if (!empty($groupedRows)): ?>
                                <?php foreach ($groupedRows as $sectionName => $employees): ?>
                                    <tr class="section-group-header"
                                        data-section="<?= htmlspecialchars($sectionName) ?>"
                                        data-target="section-<?= md5($sectionName) ?>">
                                        <td colspan="5">
                                            <?= htmlspecialchars($sectionName) ?>
                                            <span class="badge-count section-count" data-section="<?= htmlspecialchars($sectionName) ?>">
                                                <?= count($employees) ?>
                                            </span>
                                            <button class="btn btn-outline-primary btn-sm btn-collapse-section"
                                                    onclick="toggleSection('<?= md5($sectionName) ?>', this)"
                                                    title="Zwiń / Rozwiń dział">
                                                ▲ Zwiń
                                            </button>
                                        </td>
                                    </tr>
                                    <?php foreach ($employees as $row): ?>
                                        <tr class="employee-row section-<?= md5($sectionName) ?>"
                                            data-surname="<?= htmlspecialchars(mb_strtolower($row['surname'])) ?>"
                                            data-name="<?= htmlspecialchars(mb_strtolower($row['name'])) ?>"
                                            data-section="<?= htmlspecialchars($sectionName) ?>">
                                            <td><?= htmlspecialchars($row['surname']) ?></td>
                                            <td><?= htmlspecialchars($row['name']) ?></td>
                                            <td><?= htmlspecialchars($row['section_name']) ?></td>
                                            <td><?= htmlspecialchars($row['cost']) ?></td>
                                            <td>
                                                <a href="<?=ROOT?>/production-employees/<?=$row['id']?>/edit"
                                                   class="btn btn-warning btn-sm">Edytuj</a>
                                                <a href="<?=ROOT?>/production-employees/<?=$row['id']?>/delete"
                                                   class="btn btn-danger btn-sm">Usuń</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Brak pracowników.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Brak wyników po filtrowaniu -->
                    <div id="noResults" class="text-center text-muted py-4" style="display:none;">
                        Brak pracowników pasujących do wybranych filtrów.
                    </div>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>

<script>
(function () {
    // ── Stan sortowania ──────────────────────────────────────────────
    let sortCol = 0;   // domyślnie wg nazwiska
    let sortDir = 'asc';

    // ── Sortowanie kolumn ────────────────────────────────────────────
    document.querySelectorAll('#employeeTable th.sortable-col').forEach(function (th) {
        th.addEventListener('click', function () {
            const col = parseInt(th.dataset.col);
            if (sortCol === col) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortCol = col;
                sortDir = 'asc';
            }
            updateSortIcons();
            sortAndRender();
        });
    });

    function updateSortIcons() {
        for (let i = 0; i <= 3; i++) {
            const icon = document.getElementById('sort-' + i);
            if (!icon) continue;
            icon.className = 'sort-icon' + (i === sortCol ? ' ' + sortDir : '');
        }
    }

    function getCellText(row, col) {
        const cells = row.querySelectorAll('td');
        if (!cells[col]) return '';
        return cells[col].textContent.trim();
    }

    function sortAndRender() {
        const tbody = document.getElementById('id_production_employee');

        // Zbierz bloki: nagłówek sekcji + wiersze pracowników
        const sectionHeaders = Array.from(tbody.querySelectorAll('tr.section-group-header'));

        sectionHeaders.forEach(function (header) {
            const sectionClass = header.dataset.target;
            const employeeRows = Array.from(tbody.querySelectorAll('tr.' + sectionClass));

            employeeRows.sort(function (a, b) {
                const aVal = getCellText(a, sortCol);
                const bVal = getCellText(b, sortCol);
                // Próba sortowania numerycznego
                const aNum = parseFloat(aVal.replace(',', '.'));
                const bNum = parseFloat(bVal.replace(',', '.'));
                let cmp;
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    cmp = aNum - bNum;
                } else {
                    cmp = aVal.localeCompare(bVal, 'pl');
                }
                return sortDir === 'asc' ? cmp : -cmp;
            });

            // Wstaw posortowane wiersze za nagłówkiem sekcji
            employeeRows.forEach(function (row) {
                tbody.insertBefore(row, null); // przenosi na koniec tymczasowo
            });
        });

        // Odbuduj kolejność: nagłówek + wiersze jego sekcji
        sectionHeaders.forEach(function (header) {
            const sectionClass = header.dataset.target;
            const employeeRows = Array.from(tbody.querySelectorAll('tr.' + sectionClass));
            tbody.appendChild(header);
            employeeRows.forEach(function (row) {
                tbody.appendChild(row);
            });
        });

        applyFilters();
    }

    // ── Filtrowanie ──────────────────────────────────────────────────
    window.applyFilters = function () {
        const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
        const sectionVal = document.getElementById('sectionFilter').value;

        const tbody = document.getElementById('id_production_employee');
        const employeeRows = Array.from(tbody.querySelectorAll('tr.employee-row'));
        const sectionHeaders = Array.from(tbody.querySelectorAll('tr.section-group-header'));

        // Zlicz widocznych per sekcja
        const sectionVisible = {};

        employeeRows.forEach(function (row) {
            const surname = row.dataset.surname || '';
            const name    = row.dataset.name    || '';
            const section = row.dataset.section || '';

            const matchSearch  = !searchVal || surname.includes(searchVal) || name.includes(searchVal);
            const matchSection = !sectionVal || section === sectionVal;
            const visible = matchSearch && matchSection;

            row.style.display = visible ? '' : 'none';

            if (!sectionVisible[section]) sectionVisible[section] = 0;
            if (visible) sectionVisible[section]++;
        });

        // Pokaż/ukryj nagłówki sekcji i aktualizuj licznik
        let totalVisible = 0;
        sectionHeaders.forEach(function (header) {
            const section = header.dataset.section;
            const count   = sectionVisible[section] || 0;
            totalVisible += count;

            header.style.display = count > 0 ? '' : 'none';

            // Aktualizuj badge z liczbą widocznych pracowników
            const badge = header.querySelector('.section-count');
            if (badge) badge.textContent = count;
        });

        document.getElementById('visibleCount').textContent = totalVisible;
        document.getElementById('noResults').style.display  = totalVisible === 0 ? '' : 'none';
    };

    window.resetFilters = function () {
        document.getElementById('searchInput').value = '';
        document.getElementById('sectionFilter').value = '';
        applyFilters();
    };

    // ── Zwijanie sekcji ──────────────────────────────────────────────
    window.toggleSection = function (sectionHash, btn) {
        const rows = document.querySelectorAll('tr.section-' + sectionHash);
        const isCollapsed = rows[0] && rows[0].classList.contains('section-block-hidden');
        rows.forEach(function (r) {
            r.classList.toggle('section-block-hidden', !isCollapsed);
        });
        btn.textContent = isCollapsed ? '▲ Zwiń' : '▼ Rozwiń';
    };

    // ── Init ─────────────────────────────────────────────────────────
    updateSortIcons();
    applyFilters();
})();
</script>
