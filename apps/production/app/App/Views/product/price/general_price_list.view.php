<?php $this->view("page_components/header"); ?>

<style>
    .price-zero-row {
        background-color: #fff3cd !important;
    }
    .price-zero-row td {
        border-left: 3px solid #ffc107;
    }
    .hideRow {
        display: none !important;
    }
    .prod-inactive {
        opacity: 0.65;
    }
    .filter-bar .form-label {
        font-weight: 600;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6c757d;
    }
    #id_final_product_table tr td {
        vertical-align: middle;
    }
    .badge-category {
        font-size: 0.75rem;
        font-weight: 500;
    }
    .price-input-group {
        max-width: 160px;
    }
    .stats-bar .stat-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        border-radius: 8px;
        font-size: 0.9rem;
    }
    .table > thead > tr > th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #6c757d;
        border-bottom: 2px solid #dee2e6;
        padding: 10px 14px;
        background: #f8f9fa;
    }
    .btn-action {
        min-width: 80px;
    }
    .section-badge {
        font-size: 0.7rem;
        padding: 2px 7px;
        border-radius: 20px;
    }
    /* Modal export */
    .export-option-card {
        border: 1px solid #dee2e6;
        border-radius: 10px;
        padding: 16px 20px;
        transition: border-color .2s, box-shadow .2s;
        cursor: pointer;
        user-select: none;
    }
    .export-option-card:hover {
        border-color: #0d6efd;
        box-shadow: 0 0 0 3px rgba(13,110,253,.1);
    }
    .export-option-card .form-check-label {
        cursor: pointer;
    }
    .export-preview-badge {
        font-size: 0.7rem;
        padding: 2px 8px;
        border-radius: 20px;
    }
</style>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Cennik ogólny produktów</h3>
                <p class="text-subtitle text-muted">Zarządzaj cenami standardowymi produktów gotowych.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="#">Produkty</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Cennik ogólny</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between py-3">
                <div>
                    <h4 class="card-title mb-0">
                        <i class="bi bi-tags-fill me-2 text-primary"></i>Edycja cen standardowych
                    </h4>
                </div>
                <div id="zero-price-alert" class="alert alert-warning mb-0 py-2 px-3 d-none" role="alert" style="font-size:0.88rem;">
                </div>
            </div>

            <div class="card-body">

                <!-- PRZYCISK EKSPORTU -->
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-outline-success"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#exportPriceListModal">
                        <i class="bi bi-file-earmark-arrow-down me-2"></i>Pobierz cennik
                    </button>
                </div>

                <!-- FILTRY -->
                <div class="filter-bar row g-3 align-items-end mb-4 pb-3 border-bottom">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Szukaj po nazwie</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                            <input class="form-control border-start-0" type="text"
                                   id="search-input"
                                   placeholder="Wpisz nazwę produktu..."
                                   oninput="applyFilters()">
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Filtruj po kategorii</label>
                        <select class="form-select" id="category-filter" onchange="applyFilters()">
                            <option value="">— Wszystkie kategorie —</option>
                            <?php
                            $categories = [];
                            if (!empty($rows)) {
                                foreach ($rows as $row) {
                                    $key = $row['id_category'] ?? '';
                                    if ($key && !isset($categories[$key])) {
                                        $categories[$key] = htmlspecialchars($row['category_name'] ?? 'Bez kategorii');
                                    }
                                }
                                asort($categories);
                            }
                            foreach ($categories as $catId => $catName): ?>
                                <option value="<?= htmlspecialchars($catId) ?>"><?= $catName ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Filtruj po sekcji</label>
                        <select class="form-select" id="section-filter" onchange="applyFilters()">
                            <option value="">— Wszystkie sekcje —</option>
                            <?php
                            $sections = [];
                            if (!empty($rows)) {
                                foreach ($rows as $row) {
                                    $key = $row['id_section'] ?? '';
                                    if ($key && !isset($sections[$key])) {
                                        $sections[$key] = htmlspecialchars($row['section_name'] ?? 'Brak sekcji');
                                    }
                                }
                                asort($sections);
                            }
                            foreach ($sections as $secId => $secName): ?>
                                <option value="<?= htmlspecialchars($secId) ?>"><?= $secName ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="show-inactive"
                                       onchange="applyFilters()">
                                <label class="form-check-label small" for="show-inactive">Pokaż nieaktywne</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="show-zero-only"
                                       onchange="applyFilters()">
                                <label class="form-check-label small text-warning fw-semibold" for="show-zero-only">Tylko cena = 0</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STATYSTYKI -->
                <div class="stats-bar row g-3 mb-4" id="stats-bar">
                    <div class="col-auto">
                        <div class="stat-item bg-light rounded">
                            <i class="bi bi-box-seam text-primary fs-5"></i>
                            <div>
                                <div class="fw-bold" id="stat-total">0</div>
                                <div class="text-muted" style="font-size:0.75rem;">Produktów</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="stat-item bg-light rounded">
                            <i class="bi bi-check-circle text-success fs-5"></i>
                            <div>
                                <div class="fw-bold" id="stat-active">0</div>
                                <div class="text-muted" style="font-size:0.75rem;">Aktywnych</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="stat-item bg-light rounded">
                            <i class="bi bi-exclamation-triangle text-warning fs-5"></i>
                            <div>
                                <div class="fw-bold" id="stat-zero">0</div>
                                <div class="text-muted" style="font-size:0.75rem;">Cena = 0</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <div class="stat-item bg-light rounded">
                            <i class="bi bi-eye-slash text-secondary fs-5"></i>
                            <div>
                                <div class="fw-bold" id="stat-inactive">0</div>
                                <div class="text-muted" style="font-size:0.75rem;">Nieaktywnych</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TABELA -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th style="width:2%"></th>
                            <th style="width:4%">#</th>
                            <th style="width:28%">Nazwa produktu</th>
                            <th style="width:18%">Kategoria</th>
                            <th style="width:14%">Sekcja</th>
                            <th style="width:10%">Aktywny</th>
                            <th style="width:14%">Cena standardowa (€)</th>
                            <th style="width:10%">Akcja</th>
                        </tr>
                        </thead>
                        <tbody id="id_final_product_table">
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $isActive   = (int)($row['active'] ?? 1);
                                $price      = (float)($row['standard_price'] ?? 0);
                                $isZero     = ($price == 0);
                                $rowClass   = 'data-row';
                                $rowClass  .= $isActive ? '' : ' prod-inactive';
                                $rowClass  .= $isZero ? ' price-zero-row' : '';
                                ?>
                                <tr class="<?= $rowClass ?>"
                                    data-name="<?= htmlspecialchars(strtolower($row['name'] ?? ''), ENT_QUOTES) ?>"
                                    data-category="<?= htmlspecialchars($row['id_category'] ?? '', ENT_QUOTES) ?>"
                                    data-section="<?= htmlspecialchars($row['id_section'] ?? '', ENT_QUOTES) ?>"
                                    data-active="<?= $isActive ?>"
                                    data-price="<?= $price ?>">
                                    <td>
                                        <?php if ($isZero): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-warning"
                                               title="Cena produktu wynosi 0"
                                               data-bs-toggle="tooltip"
                                               data-bs-placement="top"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= (int)$row['id'] ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($row['name'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark badge-category border">
                                            <?= htmlspecialchars($row['category_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="section-badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                            <?= htmlspecialchars($row['section_name'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="bi bi-check-circle me-1"></i>Tak
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                                <i class="bi bi-x-circle me-1"></i>Nie
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="input-group price-input-group">
                                            <span class="input-group-text bg-white text-muted">€</span>
                                            <input type="number" class="form-control text-end"
                                                   min="0" step="0.01"
                                                   value="<?= number_format($price, 2, '.', '') ?>"
                                                   disabled>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-warning btn-action"
                                                type="button"
                                                onclick="enableUpdate(this)">
                                            <i class="bi bi-pencil me-1"></i>Zmień
                                        </button>
                                        <button class="btn btn-sm btn-success btn-action"
                                                type="button"
                                                onclick="saveStandardPrice('<?= (int)$row['id'] ?>', this)"
                                                style="display:none">
                                            <i class="bi bi-check-lg me-1"></i>Zapisz
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Brak produktów do wyświetlenia
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- WYNIK FILTROWANIA - BRAK WYNIKÓW -->
                <div id="no-results" class="text-center text-muted py-4 d-none">
                    <i class="bi bi-search fs-2 d-block mb-2"></i>
                    <p>Brak wyników dla podanych filtrów.</p>
                </div>

            </div><!-- /.card-body -->
        </div><!-- /.card -->
    </section>
</div>

<!-- ====================================================
     MODAL: Pobierz cennik ogólny
===================================================== -->
<div class="modal fade" id="exportPriceListModal" tabindex="-1" aria-labelledby="exportPriceListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="exportPriceListModalLabel">
                        <i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i>Pobierz cennik ogólny
                    </h5>
                    <p class="text-muted small mt-1 mb-0">Wybierz opcje eksportu, a następnie pobierz plik CSV.</p>
                </div>
                <button type="button" class="btn-close ms-3 mt-1" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body pt-3">

                <!-- Opcje eksportu -->
                <p class="text-uppercase fw-semibold" style="font-size:0.75rem;letter-spacing:.06em;color:#6c757d;">
                    Zawartość pliku
                </p>

                <div class="d-flex flex-column gap-3">

                    <!-- Opcja 1: produkty nieaktywne -->
                    <label class="export-option-card d-flex align-items-center gap-3" for="export-include-inactive">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox"
                                   id="export-include-inactive"
                                   onchange="updateExportPreview()">
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Uwzględnij produkty nieaktywne</div>
                            <div class="text-muted small">Produkty wyłączone ze sprzedaży (aktywny = Nie) zostaną dodane do pliku.</div>
                        </div>
                        <span class="export-preview-badge bg-secondary-subtle text-secondary border border-secondary-subtle" id="badge-inactive">
                            Wył.
                        </span>
                    </label>

                    <!-- Opcja 2: produkty z ceną 0 -->
                    <label class="export-option-card d-flex align-items-center gap-3" for="export-include-zero">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox"
                                   id="export-include-zero"
                                   onchange="updateExportPreview()">
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Uwzględnij produkty z ceną = 0</div>
                            <div class="text-muted small">Produkty bez ustalonej ceny standardowej zostaną dodane do pliku.</div>
                        </div>
                        <span class="export-preview-badge bg-warning-subtle text-warning border border-warning-subtle" id="badge-zero">
                            Wył.
                        </span>
                    </label>

                </div>


                <!-- Podgląd liczby produktów -->
                <div class="mt-4 p-3 bg-light rounded d-flex align-items-center gap-3" id="export-preview-box">
                    <i class="bi bi-info-circle text-primary fs-5 flex-shrink-0"></i>
                    <div>
                        <div class="small text-muted">Liczba produktów w pliku:</div>
                        <div class="fw-bold fs-5" id="export-preview-count">—</div>
                    </div>
                </div>

            </div>
            <div class="modal-footer border-top-0 pt-0 gap-2">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Anuluj
                </button>
                <button type="button" class="btn btn-outline-danger" id="btn-export-pdf"
                        onclick="exportGeneralPriceListPDF(this)">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Pobierz PDF
                </button>
                <button type="button" class="btn btn-outline-primary" id="btn-export-json"
                        onclick="exportGeneralPriceListJSON(this)">
                    <i class="bi bi-filetype-json me-2"></i>Pobierz JSON
                </button>
                <button type="button" class="btn btn-success" id="btn-export-csv"
                        onclick="exportGeneralPriceListCSV(this)">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Pobierz CSV
                </button>
            </div>
        </div>
    </div>
</div>


<?php $this->view("page_components/footer"); ?>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        // inicjalizuj tooltips Bootstrap
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            new bootstrap.Tooltip(el);
        });

        // domyślnie nieaktywne są ukryte – nie odznaczamy checkboxa
        applyFilters();
        updateStats();

        // inicjalizuj podgląd w modalu
        document.getElementById('exportPriceListModal').addEventListener('show.bs.modal', function () {
            updateExportPreview();
        });
    });

    /* ===== FILTRY ===== */
    function applyFilters() {
        const searchVal   = document.getElementById('search-input').value.toLowerCase().trim();
        const catVal      = document.getElementById('category-filter').value;
        const secVal      = document.getElementById('section-filter').value;
        const showInact   = document.getElementById('show-inactive').checked;
        const zeroOnly    = document.getElementById('show-zero-only').checked;

        const rows = document.querySelectorAll('#id_final_product_table tr.data-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const name     = row.dataset.name || '';
            const category = row.dataset.category || '';
            const section  = row.dataset.section || '';
            const active   = parseInt(row.dataset.active || '1');
            const price    = parseFloat(row.dataset.price || '0');

            let visible = true;

            if (searchVal && name.indexOf(searchVal) === -1) visible = false;
            if (catVal && category !== catVal) visible = false;
            if (secVal && section !== secVal) visible = false;
            if (!showInact && active === 0) visible = false;
            if (zeroOnly && price !== 0) visible = false;

            if (visible) {
                row.classList.remove('hideRow');
                visibleCount++;
            } else {
                row.classList.add('hideRow');
            }
        });

        document.getElementById('no-results').classList.toggle('d-none', visibleCount > 0);
        updateStats();
    }

    /* ===== STATYSTYKI ===== */
    function updateStats() {
        const rows = document.querySelectorAll('#id_final_product_table tr.data-row');
        let total = 0, active = 0, zero = 0, inactive = 0;

        rows.forEach(row => {
            if (row.classList.contains('hideRow')) return;
            total++;
            const isActive = parseInt(row.dataset.active || '1');
            const price    = parseFloat(row.dataset.price || '0');
            if (isActive) active++; else inactive++;
            if (price === 0) zero++;
        });

        document.getElementById('stat-total').textContent    = total;
        document.getElementById('stat-active').textContent   = active;
        document.getElementById('stat-zero').textContent     = zero;
        document.getElementById('stat-inactive').textContent = inactive;

        const alertBox = document.getElementById('zero-price-alert');
        const activeZeroRows = [...rows].filter(r =>
            !r.classList.contains('hideRow') &&
            parseInt(r.dataset.active || '1') === 1 &&
            parseFloat(r.dataset.price || '0') === 0
        );

        if (activeZeroRows.length > 0) {
            alertBox.classList.remove('d-none');
            alertBox.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>${activeZeroRows.length}</strong>
                ${activeZeroRows.length === 1 ? 'aktywny produkt ma' : 'aktywnych produktów ma'} cenę równą 0.`;
        } else {
            alertBox.classList.add('d-none');
            alertBox.innerHTML = '';
        }
    }

    /* ===== EDYCJA CENY ===== */
    function enableUpdate(btn) {
        const row        = btn.closest('tr');
        const priceInput = row.querySelector('input[type="number"]');
        const saveBtn    = btn.nextElementSibling;

        btn.style.display     = 'none';
        saveBtn.style.display = 'inline-block';
        priceInput.disabled   = false;
        priceInput.focus();
        priceInput.select();
    }

    function disableUpdate(btn) {
        const row        = btn.closest('tr');
        const priceInput = row.querySelector('input[type="number"]');
        const editBtn    = btn.previousElementSibling;

        btn.style.display     = 'none';
        editBtn.style.display = 'inline-block';
        priceInput.disabled   = true;
    }

    function saveStandardPrice(id_product, btn) {
        const row        = btn.closest('tr');
        const priceInput = row.querySelector('input[type="number"]');
        const newPrice   = parseFloat(priceInput.value);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zapisywanie...';

        api(`/ajax/products/${id_product}/standard-price`, {
            method: 'PATCH',
            data: { standard_price: newPrice }
        }).then(function (res) {
            showSnack(res);
            if (res) {
                row.dataset.price = newPrice;

                if (newPrice === 0) {
                    row.classList.add('price-zero-row');
                    const warnCell = row.cells[0];
                    if (!warnCell.querySelector('.bi-exclamation-triangle-fill')) {
                        warnCell.innerHTML = `<i class="bi bi-exclamation-triangle-fill text-warning"
                            title="Cena produktu wynosi 0"
                            data-bs-toggle="tooltip"
                            data-bs-placement="top"></i>`;
                        new bootstrap.Tooltip(warnCell.querySelector('[data-bs-toggle="tooltip"]'));
                    }
                } else {
                    row.classList.remove('price-zero-row');
                    row.cells[0].innerHTML = '';
                }

                disableUpdate(btn);
                updateStats();
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Zapisz';
            }
        });
    }

    /* ===== MODAL EKSPORTU ===== */

    /**
     * Aktualizuje podgląd liczby produktów i badge-y opcji w modalu.
     */
    function updateExportPreview() {
        const includeInactive = document.getElementById('export-include-inactive').checked;
        const includeZero     = document.getElementById('export-include-zero').checked;

        // Aktualizuj badge-y
        const badgeInactive = document.getElementById('badge-inactive');
        badgeInactive.textContent = includeInactive ? 'Wł.' : 'Wył.';
        badgeInactive.className = includeInactive
            ? 'export-preview-badge bg-success-subtle text-success border border-success-subtle'
            : 'export-preview-badge bg-secondary-subtle text-secondary border border-secondary-subtle';

        const badgeZero = document.getElementById('badge-zero');
        badgeZero.textContent = includeZero ? 'Wł.' : 'Wył.';
        badgeZero.className = includeZero
            ? 'export-preview-badge bg-success-subtle text-success border border-success-subtle'
            : 'export-preview-badge bg-warning-subtle text-warning border border-warning-subtle';

        // Policz produkty z DOM (szybki podgląd offline)
        const allRows = document.querySelectorAll('#id_final_product_table tr.data-row');
        let count = 0;
        allRows.forEach(row => {
            const active = parseInt(row.dataset.active || '1');
            const price  = parseFloat(row.dataset.price || '0');

            if (active === 0 && !includeInactive) return;
            if (price === 0 && !includeZero) return;
            count++;
        });

        document.getElementById('export-preview-count').textContent = count + ' produktów';
    }

    /**
     * Pobiera plik CSV z cenníkiem ogólnym na podstawie wybranych opcji.
     */
    async function exportGeneralPriceListCSV(btn) {
        const includeInactive = document.getElementById('export-include-inactive').checked ? 1 : 0;
        const includeZero     = document.getElementById('export-include-zero').checked ? 1 : 0;

        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Pobieranie...';

        try {
            const params = new URLSearchParams({
                include_inactive:   includeInactive,
                include_zero_price: includeZero
            });

            const response = await fetch(`<?= ROOT ?>/ajax/products/price-list/csv?${params.toString()}`);

            if (!response.ok) {
                const err = await response.json().catch(() => ({}));
                throw new Error(err.message || 'Błąd pobierania pliku');
            }

            const blob = await response.blob();
            const url  = window.URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;

            // Pobierz nazwę pliku z nagłówka lub ustaw domyślną
            const disposition = response.headers.get('Content-Disposition') || '';
            const match       = disposition.match(/filename=([^;]+)/);
            a.download = match ? match[1].trim() : `cennik_ogolny_${new Date().toISOString().slice(0,10)}.csv`;

            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            showSnack(true);

            // Zamknij modal po chwili
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('exportPriceListModal')).hide();
            }, 400);

        } catch (err) {
            console.error(err);
            showSnack(false, err.message || 'Wystąpił błąd podczas pobierania pliku');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = originalHtml;
        }
    }
    /**
     * Pobiera plik JSON z cenníkiem ogólnym na podstawie wybranych opcji.
     */
    async function exportGeneralPriceListJSON(btn) {
        const includeInactive = document.getElementById('export-include-inactive').checked ? 1 : 0;
        const includeZero     = document.getElementById('export-include-zero').checked ? 1 : 0;

        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generowanie...';

        try {
            const params = new URLSearchParams({
                include_inactive:   includeInactive,
                include_zero_price: includeZero
            });

            const response = await fetch(`<?= ROOT ?>/ajax/products/price-list/json?${params.toString()}`);

            if (!response.ok) {
                const err = await response.json().catch(() => ({}));
                throw new Error(err.message || 'Błąd generowania pliku JSON');
            }

            const blob = await response.blob();
            const url  = window.URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;

            const disposition = response.headers.get('Content-Disposition') || '';
            const match       = disposition.match(/filename=([^;]+)/);
            a.download = match ? match[1].trim() : `cennik_ogolny_${new Date().toISOString().slice(0,10)}.json`;

            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            showSnack(true);

            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('exportPriceListModal')).hide();
            }, 400);

        } catch (err) {
            console.error(err);
            showSnack(false, err.message || 'Wystąpił błąd podczas generowania pliku JSON');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = originalHtml;
        }
    }
    /**
     * Pobiera plik PDF z cenníkiem ogólnym na podstawie wybranych opcji.
     */
    async function exportGeneralPriceListPDF(btn) {
        const includeInactive = document.getElementById('export-include-inactive').checked ? 1 : 0;
        const includeZero     = document.getElementById('export-include-zero').checked ? 1 : 0;

        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generowanie...';

        try {
            const params = new URLSearchParams({
                include_inactive:   includeInactive,
                include_zero_price: includeZero
            });

            const response = await fetch(`<?= ROOT ?>/ajax/products/price-list/pdf?${params.toString()}`);

            if (!response.ok) {
                const err = await response.json().catch(() => ({}));
                throw new Error(err.message || 'Błąd generowania pliku PDF');
            }

            const blob = await response.blob();
            const url  = window.URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;

            const disposition = response.headers.get('Content-Disposition') || '';
            const match       = disposition.match(/filename=([^;]+)/);
            a.download = match ? match[1].trim() : `cennik_ogolny_${new Date().toISOString().slice(0,10)}.pdf`;

            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            showSnack(true);

            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('exportPriceListModal')).hide();
            }, 400);

        } catch (err) {
            console.error(err);
            showSnack(false, err.message || 'Wystąpił błąd podczas generowania pliku PDF');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = originalHtml;
        }
    }
</script>
