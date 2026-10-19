<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= ROOT ?>/clients" class="btn btn-sm btn-light border" title="Wróć do listy klientów">
                        <i class="bi bi-arrow-left"></i>
                    </a>
                    <div>
                        <h3 class="mb-0">Podsumowanie sprzedaży klienta</h3>
                        <p class="text-subtitle text-muted mb-0"><?= htmlspecialchars($customer['company'] ?? '') ?></p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/clients">Klienci</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Sprzedaż WDT</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <!-- Formularz filtrów -->
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Filtry</h4>
            </div>
            <div class="card-body">
                <form id="summary-filter-form" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">Data od</label>
                        <input type="date" id="date_from" name="date_from" class="form-control"
                               value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">Data do</label>
                        <input type="date" id="date_to" name="date_to" class="form-control"
                               value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="sort" class="form-label">Sortowanie</label>
                        <select id="sort" name="sort" class="form-select">
                            <option value="name_asc"  <?= $sort === 'name_asc'  ? 'selected' : '' ?>>Nazwa A–Z</option>
                            <option value="qty_desc"  <?= $sort === 'qty_desc'  ? 'selected' : '' ?>>Kartony malejąco</option>
                            <option value="qty_asc"   <?= $sort === 'qty_asc'   ? 'selected' : '' ?>>Kartony rosnąco</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Generuj
                        </button>
                    </div>
                    <div class="col-12 mt-2 d-flex gap-2" id="export-buttons" style="display:none!important;">
                        <button type="button" class="btn btn-outline-danger" id="btn-export-pdf" onclick="exportFile('pdf')" disabled>
                            <i class="bi bi-file-earmark-pdf"></i> Eksport PDF
                        </button>
                        <button type="button" class="btn btn-outline-success" id="btn-export-xls" onclick="exportFile('xls')" disabled>
                            <i class="bi bi-file-earmark-spreadsheet"></i> Eksport CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Wyniki -->
        <div class="card mt-3" id="results-card" style="display:none;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">Wyniki</h4>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small" id="months-label"></span>
                    <span class="badge bg-info text-dark" id="avg-badge" style="display:none;" title="Średnia łączna kartonów na miesiąc">
                        <i class="bi bi-bar-chart-line me-1"></i>Śr. <span id="avg-badge-value"></span> krt/mies.
                    </span>
                    <span class="badge bg-secondary" id="total-badge"></span>
                </div>
            </div>
            <div class="card-body">
                <div id="loading-spinner" class="text-center py-4" style="display:none;">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
                <div id="results-wrapper">
                    <table class="table table-hover" id="results-table">
                        <thead>
                            <tr>
                                <th style="width:4%">#</th>
                                <th style="width:8%" class="text-muted">ID</th>
                                <th style="width:45%">
                                    Nazwa produktu
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="sortTable('name_asc')" title="A–Z">↑</button>
                                </th>
                                <th style="width:20%" class="text-end">
                                    Ilość kartonów
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="sortTable('qty_desc')" title="Malejąco">↓</button>
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="sortTable('qty_asc')"  title="Rosnąco">↑</button>
                                </th>
                                <th style="width:23%" class="text-end text-muted">
                                    Śr./mies.
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="sortTable('avg_desc')" title="Malejąco">↓</button>
                                    <button class="btn btn-sm btn-link p-0 ms-1" onclick="sortTable('avg_asc')"  title="Rosnąco">↑</button>
                                </th>
                            </tr>
                        </thead>
                        <tbody id="results-tbody"></tbody>
                        <tfoot>
                            <tr class="table-secondary fw-bold">
                                <td colspan="3">Łącznie</td>
                                <td class="text-end" id="total-cartons-footer">—</td>
                                <td class="text-end text-muted" id="avg-cartons-footer">—</td>
                            </tr>
                        </tfoot>
                    </table>
                    <p id="empty-message" class="text-center text-muted py-4" style="display:none;">
                        Brak danych sprzedaży dla wybranego okresu.
                    </p>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
    const CLIENT_ID = <?= (int)$customer['id'] ?>;

    // Bieżące dane – umożliwia lokalny resort bez roundtrip
    let currentProducts          = [];
    let currentTotalCartons      = 0;
    let currentAvgCartonsPerMonth = 0;
    let currentMonthsInPeriod    = 0;
    let currentDateFrom          = document.getElementById('date_from')?.value ?? '';
    let currentDateTo            = document.getElementById('date_to')?.value   ?? '';

    document.getElementById('summary-filter-form').addEventListener('submit', function (e) {
        e.preventDefault();
        fetchSummary(
            document.getElementById('date_from').value,
            document.getElementById('date_to').value,
            document.getElementById('sort').value
        );
    });

    function fetchSummary(dateFrom, dateTo, sort) {
        const card    = document.getElementById('results-card');
        const spinner = document.getElementById('loading-spinner');
        const wrapper = document.getElementById('results-wrapper');

        card.style.display    = 'block';
        spinner.style.display = 'block';
        wrapper.style.display = 'none';

        currentDateFrom = dateFrom;
        currentDateTo   = dateTo;

        const url = `/ajax/clients/${CLIENT_ID}/sales-summary?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}&sort=${encodeURIComponent(sort)}`;

        api(url, { method: 'GET' })
            .then(function (resp) {
                if (!resp.ok) {
                    alert('Błąd pobierania danych.');
                    return;
                }
                currentProducts           = resp.data.products;
                currentTotalCartons       = resp.data.total_cartons;
                currentAvgCartonsPerMonth = resp.data.avg_cartons_per_month;
                currentMonthsInPeriod     = resp.data.period.months_in_period;

                renderTable(currentProducts, currentTotalCartons, currentAvgCartonsPerMonth, currentMonthsInPeriod);
                setExportButtonsEnabled(currentProducts.length > 0);
            })
            .catch(() => alert('Błąd połączenia z serwerem.'))
            .finally(() => {
                spinner.style.display = 'none';
                wrapper.style.display = 'block';
            });
    }

    function setExportButtonsEnabled(enabled) {
        const exportRow = document.getElementById('export-buttons');
        exportRow.style.removeProperty('display');
        document.getElementById('btn-export-pdf').disabled = !enabled;
        document.getElementById('btn-export-xls').disabled = !enabled;
    }

    function exportFile(type) {
        const sort = document.getElementById('sort').value;
        const url  = `<?=ROOT?>/ajax/clients/${CLIENT_ID}/sales-summary/export/${type}`
            + `?date_from=${encodeURIComponent(currentDateFrom)}`
            + `&date_to=${encodeURIComponent(currentDateTo)}`
            + `&sort=${encodeURIComponent(sort)}`;
        window.location.href = url;
    }

    function sortTable(sort) {
        if (currentProducts.length === 0) return;

        const sorted = [...currentProducts];
        if (sort === 'qty_desc') {
            sorted.sort((a, b) => b.total_cartons - a.total_cartons);
        } else if (sort === 'qty_asc') {
            sorted.sort((a, b) => a.total_cartons - b.total_cartons);
        } else if (sort === 'avg_desc') {
            sorted.sort((a, b) => b.avg_cartons_per_month - a.avg_cartons_per_month);
        } else if (sort === 'avg_asc') {
            sorted.sort((a, b) => a.avg_cartons_per_month - b.avg_cartons_per_month);
        } else {
            sorted.sort((a, b) => a.name.localeCompare(b.name, 'pl'));
        }

        document.getElementById('sort').value = sort;

        renderTable(sorted, currentTotalCartons, currentAvgCartonsPerMonth, currentMonthsInPeriod);
    }

    function renderTable(products, totalCartons, avgPerMonth, months) {
        const tbody         = document.getElementById('results-tbody');
        const emptyMsg      = document.getElementById('empty-message');
        const table         = document.getElementById('results-table');
        const totalBadge    = document.getElementById('total-badge');
        const totalFooter   = document.getElementById('total-cartons-footer');
        const avgFooter     = document.getElementById('avg-cartons-footer');
        const avgBadge      = document.getElementById('avg-badge');
        const avgBadgeValue = document.getElementById('avg-badge-value');
        const monthsLabel   = document.getElementById('months-label');

        tbody.innerHTML = '';

        if (products.length === 0) {
            table.style.display     = 'none';
            emptyMsg.style.display  = 'block';
            totalBadge.textContent  = '0 produktów';
            totalFooter.textContent = '0';
            avgFooter.textContent   = '—';
            avgBadge.style.display  = 'none';
            monthsLabel.textContent = '';
            return;
        }

        table.style.display    = '';
        emptyMsg.style.display = 'none';

        products.forEach(function (p, idx) {
            const avg = parseFloat(p.avg_cartons_per_month).toFixed(2).replace('.', ',');
            const tr  = document.createElement('tr');
            tr.innerHTML = `
                <td>${idx + 1}</td>
                <td class="text-muted small">${parseInt(p.id_product)}</td>
                <td>${escHtml(p.name)}</td>
                <td class="text-end fw-semibold">${parseInt(p.total_cartons).toLocaleString('pl-PL')}</td>
                <td class="text-end text-muted">${avg}</td>
            `;
            tbody.appendChild(tr);
        });

        const avgFormatted       = parseFloat(avgPerMonth).toFixed(2).replace('.', ',');
        totalBadge.textContent   = products.length + ' produktów';
        totalFooter.textContent  = parseInt(totalCartons).toLocaleString('pl-PL') + ' krt';
        avgFooter.textContent    = avgFormatted + ' krt';
        avgBadgeValue.textContent = avgFormatted;
        avgBadge.style.display   = '';
        monthsLabel.textContent  = months + ' mies.';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.addEventListener('DOMContentLoaded', function () {
        document.getElementById('summary-filter-form').dispatchEvent(new Event('submit'));
    });
</script>

<?php $this->view("page_components/footer"); ?>

