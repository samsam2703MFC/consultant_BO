<?php $this->view("page_components/header"); ?>
<?php
$selectedOrderIds = $selected_order_ids ?? [];

function stock_fmt($value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float)$value, $decimals, ',', ' ');
}
?>
<div class="page-heading stock-monitor-page">
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-7 order-md-1 order-last">
                <h3>Magazyn</h3>
                <p class="text-subtitle text-muted mb-0">Zamówienia klientów zestawione ze stanem magazynu, paletami i historią WDT.</p>
            </div>
            <div class="col-12 col-md-5 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Magazyn</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div class="small text-muted">
                Zakres zamówień: <strong id="summaryDateRange"><?= !empty($summary['date_from']) ? htmlspecialchars($summary['date_from']) : '-' ?><?= !empty($summary['date_to']) && $summary['date_to'] !== $summary['date_from'] ? ' - ' . htmlspecialchars($summary['date_to']) : '' ?></strong>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ordersModal">
                    <i class="bi bi-check2-square me-1"></i> Zamówienia
                    <span class="badge bg-primary ms-1" id="selectedOrdersBadge"><?= (int)($summary['selected_orders_count'] ?? 0) ?></span>
                </button>
                <button class="btn btn-dark" type="button" onclick="downloadCurrentStockPdf()">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Stan magazynowy na bieżący dzień [PDF]
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div>
                    <h4 class="card-title mb-0">Produkty</h4>
                    <span class="text-muted small">Produkty pogrupowane według działów produkcyjnych i kategorii.</span>
                </div>
                <div class="input-group stock-search">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" id="stockSearch" placeholder="Szukaj produktu, działu lub kategorii">
                </div>
            </div>
            <div class="stock-filter-panel border-top">
                <div class="stock-table-toolbar">
                    <span class="stock-table-toolbar-label">Widok tabeli</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleAllGroups">
                        <i class="bi bi-arrows-expand me-1"></i><span>Rozwiń wszystkie</span>
                    </button>
                </div>
                <div class="stock-filter-line">
                    <span class="stock-filter-label">Działy</span>
                    <div class="stock-filter-badges" id="sectionFilterBadges"></div>
                </div>
                <div class="stock-filter-line">
                    <span class="stock-filter-label">Kategorie</span>
                    <div class="stock-filter-badges" id="categoryFilterBadges"></div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive stock-monitor-table-wrapper">
                    <table class="table table-hover align-middle mb-0 stock-monitor-table">
                        <thead class="sticky-header">
                        <tr>
                            <th><button type="button" class="stock-sort" data-sort-key="name" data-sort-type="text">Produkt</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="ordered_quantity" data-sort-type="number">Zamówione</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="in_stock" data-sort-type="number">W magazynie</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="palletised" data-sort-type="number">Na palecie</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="remaining_to_produce" data-sort-type="number">Do produkcji</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="avg_sold_last_4_weeks" data-sort-type="number">Śr. sprzedaż WDT (4 tyg.)</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="coverage_weeks" data-sort-type="number">Pokrycie</button></th>
                            <th class="text-end"><button type="button" class="stock-sort" data-sort-key="ordered_quantity_yoy" data-sort-type="number">Zamów. rok temu</button></th>
                            <th><button type="button" class="stock-sort" data-sort-key="state_label" data-sort-type="text">Status</button></th>
                        </tr>
                        </thead>
                        <tbody id="stockRows">
                        <?php foreach (($rows ?? []) as $row): ?>
                            <tr data-search="<?= htmlspecialchars(mb_strtolower(($row['name'] ?? '') . ' ' . ($row['section_name'] ?? '') . ' ' . ($row['category_name'] ?? ''))) ?>"
                                data-name="<?= htmlspecialchars(mb_strtolower($row['name'] ?? '')) ?>"
                                data-section_name="<?= htmlspecialchars(mb_strtolower($row['section_name'] ?? '')) ?>"
                                data-category_name="<?= htmlspecialchars(mb_strtolower($row['category_name'] ?? '')) ?>"
                                data-ordered_quantity="<?= (float)($row['ordered_quantity'] ?? 0) ?>"
                                data-in_stock="<?= (float)($row['in_stock'] ?? 0) ?>"
                                data-palletised="<?= (float)($row['palletised'] ?? 0) ?>"
                                data-remaining_to_produce="<?= (float)($row['remaining_to_produce'] ?? 0) ?>"
                                data-avg_sold_last_4_weeks="<?= (float)($row['avg_sold_last_4_weeks'] ?? 0) ?>"
                                data-coverage_weeks="<?= (float)($row['coverage_weeks'] ?? 0) ?>"
                                data-ordered_quantity_yoy="<?= (float)($row['ordered_quantity_yoy'] ?? 0) ?>"
                                data-state_label="<?= htmlspecialchars(mb_strtolower($row['state_label'] ?? '')) ?>">
                                <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                <td class="text-end"><?= stock_fmt($row['ordered_quantity']) ?></td>
                                <td class="text-end"><?= stock_fmt($row['in_stock']) ?></td>
                                <td class="text-end"><?= stock_fmt($row['palletised']) ?></td>
                                <td class="text-end fw-bold <?= ((float)$row['remaining_to_produce'] > 0) ? 'text-danger' : 'text-success' ?>"><?= stock_fmt($row['remaining_to_produce']) ?></td>
                                <td class="text-end"><?= stock_fmt($row['avg_sold_last_4_weeks'], 2) ?></td>
                                <td class="text-end"><?= $row['coverage_weeks'] !== null ? stock_fmt($row['coverage_weeks'], 1) . ' tyg.' : '-' ?></td>
                                <td class="text-end"><?= stock_fmt($row['ordered_quantity_yoy']) ?></td>
                                <td><span class="badge bg-<?= htmlspecialchars($row['state']) ?>"><?= htmlspecialchars($row['state_label']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 text-center text-muted <?= !empty($rows) ? 'd-none' : '' ?>" id="emptyRows">Brak produktów dla wybranego zestawu zamówień.</div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="ordersModal" tabindex="-1" aria-labelledby="ordersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="ordersModalLabel">Zamówienia uwzględnione w panelu</h5>
                    <span class="text-muted small">Domyślnie zaznaczone są wszystkie zamówienia ze statusem 1.</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body p-0">
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center p-3 border-bottom">
                    <div class="input-group stock-modal-search">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="orderSearch" placeholder="Szukaj klienta, referencji lub daty">
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="selectAllOrders">Zaznacz wszystkie</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clearOrders">Odznacz wszystkie</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th style="width: 48px"></th>
                            <th>Klient</th>
                            <th>Referencja</th>
                            <th>Wysyłka</th>
                            <th class="text-end">Kartony</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($orders ?? []) as $order): ?>
                            <?php $checked = in_array((int)$order['id'], array_map('intval', $selectedOrderIds), true); ?>
                            <tr class="order-row" data-search="<?= htmlspecialchars(mb_strtolower(($order['customer_name'] ?? '') . ' ' . ($order['reference'] ?? '') . ' ' . ($order['expected_shipping_date'] ?? ''))) ?>">
                                <td>
                                    <input class="form-check-input order-checkbox" type="checkbox" value="<?= (int)$order['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                </td>
                                <td><?= htmlspecialchars($order['customer_name'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($order['reference'] ?: ('#' . $order['id'])) ?></td>
                                <td><?= htmlspecialchars($order['expected_shipping_date'] ?? '-') ?></td>
                                <td class="text-end"><?= stock_fmt($order['total_cartons'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
                <button type="button" class="btn btn-primary" id="applyOrders">
                    <i class="bi bi-arrow-clockwise me-1"></i> Przelicz panel
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .stock-monitor-page .card { border-radius: .5rem; }
    .stock-search { max-width: 340px; }
    .stock-modal-search { max-width: 420px; }
    .stock-filter-panel { padding: .75rem 1rem; }
    .stock-table-toolbar { align-items: center; display: flex; flex-wrap: wrap; gap: .5rem; justify-content: flex-end; margin-bottom: .65rem; }
    .stock-table-toolbar-label { color: #607080; font-size: .78rem; font-weight: 700; text-transform: uppercase; }
    .stock-filter-line { align-items: flex-start; display: flex; gap: .75rem; margin-bottom: .55rem; }
    .stock-filter-line:last-child { margin-bottom: 0; }
    .stock-filter-label { color: #607080; flex: 0 0 72px; font-size: .78rem; font-weight: 700; line-height: 1.9; text-transform: uppercase; }
    .stock-filter-badges { display: flex; flex-wrap: wrap; gap: .35rem; min-width: 0; }
    .stock-filter-badge { border-radius: 999px; line-height: 1.2; }
    .stock-filter-badge.is-active { box-shadow: 0 0 0 .12rem rgba(67, 94, 190, .18); }
    .stock-monitor-table-wrapper { overflow: visible; }
    .stock-monitor-table { border-collapse: separate; border-spacing: 0; }
    .stock-monitor-table .sticky-header th {
        background: #fff;
        box-shadow: inset 0 -1px 0 #dee2e6, 0 2px 5px rgba(37, 57, 111, .08);
        position: sticky;
        top: 0;
        z-index: 5;
    }
    .stock-monitor-table th { white-space: nowrap; font-size: .82rem; color: #607080; }
    .stock-sort {
        align-items: center;
        background: transparent;
        border: 0;
        color: inherit;
        display: inline-flex;
        font: inherit;
        gap: .35rem;
        padding: 0;
        text-align: inherit;
        white-space: normal;
    }
    .stock-sort::after { color: #98a2b3; content: "↕"; font-size: .72rem; }
    .stock-sort.is-asc::after { content: "↑"; color: #435ebe; }
    .stock-sort.is-desc::after { content: "↓"; color: #435ebe; }
    th.text-end .stock-sort { justify-content: flex-end; margin-left: auto; }
    .stock-monitor-table td { white-space: nowrap; }
    .stock-monitor-table td:first-child { min-width: 260px; white-space: normal; }
    .stock-group-row td { background: #dfe7ff; color: #25396f; font-weight: 700; }
    .stock-category-row td { background: #fbfcff; color: #607080; font-size: .84rem; font-weight: 700; }
    .stock-group-toggle {
        align-items: center;
        background: transparent;
        border: 0;
        color: inherit;
        display: inline-flex;
        font: inherit;
        gap: .5rem;
        padding: .15rem 0;
        text-align: left;
        width: 100%;
    }
    .stock-group-row .stock-group-toggle { padding-left: 1.5rem; }
    .stock-category-row .stock-group-toggle { padding-left: 3rem; }
    .stock-toggle-icon { font-size: .82rem; transition: transform .15s ease; }
    .stock-group-toggle[aria-expanded="false"] .stock-toggle-icon { transform: rotate(-90deg); }
    .stock-group-symbol { font-size: .95rem; }
    @media (max-width: 767px) {
        .stock-search, .stock-modal-search { max-width: 100%; width: 100%; }
        .stock-monitor-table-wrapper { overflow-x: auto; }
        .stock-monitor-table td:first-child { min-width: 220px; }
    }
</style>

<script>
    function fmt(value, decimals = 0) {
        if (value === null || value === undefined || value === '') return '-';
        return new Intl.NumberFormat('pl-PL', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(Number(value));
    }

    function selectedOrderIds() {
        return [...document.querySelectorAll('.order-checkbox:checked')].map(el => Number(el.value));
    }

    let stockRowsData = <?= json_encode($rows ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let stockSortState = { key: 'remaining_to_produce', type: 'number', direction: 'desc' };
    const collapsedSections = new Set();
    const collapsedCategories = new Set();
    const stockFilters = { sectionKeys: new Set(), categoryKeys: new Set() };

    function collapseAllGroups(rows) {
        collapsedSections.clear();
        collapsedCategories.clear();

        rows.forEach(row => {
            const sectionName = row.section_name || 'Bez działu';
            const categoryName = row.category_name || 'Bez kategorii';
            collapsedSections.add(groupKey(sectionName));
            collapsedCategories.add(groupKey(sectionName, categoryName));
        });

        updateToggleAllButton();
    }

    function expandAllGroups() {
        collapsedSections.clear();
        collapsedCategories.clear();
        updateToggleAllButton();
    }

    function allGroupsCollapsed() {
        const groupKeys = getGroupFilterOptions(stockRowsData);
        if (groupKeys.sections.length === 0 && groupKeys.categories.length === 0) return true;

        return groupKeys.sections.every(section => collapsedSections.has(section.key))
            && groupKeys.categories.every(category => collapsedCategories.has(category.key));
    }

    function updateToggleAllButton() {
        const button = document.getElementById('toggleAllGroups');
        if (!button) return;

        const collapsed = allGroupsCollapsed();
        button.querySelector('i').className = `bi ${collapsed ? 'bi-arrows-expand' : 'bi-arrows-collapse'} me-1`;
        button.querySelector('span').textContent = collapsed ? 'Rozwiń wszystkie' : 'Zwiń wszystkie';
    }

    collapseAllGroups(stockRowsData);

    function updateSummary(summary) {
        document.getElementById('selectedOrdersBadge').textContent = summary.selected_orders_count;

        const dateText = summary.date_from
            ? `${summary.date_from}${summary.date_to && summary.date_to !== summary.date_from ? ' - ' + summary.date_to : ''}`
            : '-';
        document.getElementById('summaryDateRange').textContent = dateText;
    }

    function renderRows(rows) {
        const visibleRows = filterRowsByBadges(rows);
        const tbody = document.getElementById('stockRows');
        tbody.innerHTML = buildGroupedRows(visibleRows).map(row => {
            if (row.__groupType === 'section') {
                const expanded = !collapsedSections.has(row.sectionKey);
                return `<tr class="stock-group-row" data-group-row="section" data-section_key="${escapeHtml(row.sectionKey)}">
                    <td colspan="9"><button type="button" class="stock-group-toggle" aria-expanded="${expanded}"><i class="bi bi-chevron-down stock-toggle-icon"></i><i class="bi bi-diagram-3 stock-group-symbol"></i>${escapeHtml(row.label)}</button></td>
                </tr>`;
            }

            if (row.__groupType === 'category') {
                const expanded = !collapsedCategories.has(row.categoryKey);
                return `<tr class="stock-category-row" data-group-row="category" data-section_key="${escapeHtml(row.sectionKey)}" data-category_key="${escapeHtml(row.categoryKey)}">
                    <td colspan="9"><button type="button" class="stock-group-toggle" aria-expanded="${expanded}"><i class="bi bi-chevron-down stock-toggle-icon"></i><i class="bi bi-folder2 stock-group-symbol"></i>${escapeHtml(row.label)}</button></td>
                </tr>`;
            }

            const remainingClass = Number(row.remaining_to_produce) > 0 ? 'text-danger' : 'text-success';
            const search = `${row.name || ''} ${row.section_name || ''} ${row.category_name || ''}`.toLowerCase();

            return `<tr class="stock-product-row" data-search="${escapeHtml(search)}"
                data-name="${escapeHtml(String(row.name || '').toLowerCase())}"
                data-section_name="${escapeHtml(String(row.section_name || '').toLowerCase())}"
                data-category_name="${escapeHtml(String(row.category_name || '').toLowerCase())}"
                data-section_key="${escapeHtml(groupKey(row.section_name || 'Bez działu'))}"
                data-category_key="${escapeHtml(groupKey(row.section_name || 'Bez działu', row.category_name || 'Bez kategorii'))}"
                data-ordered_quantity="${Number(row.ordered_quantity || 0)}"
                data-in_stock="${Number(row.in_stock || 0)}"
                data-palletised="${Number(row.palletised || 0)}"
                data-remaining_to_produce="${Number(row.remaining_to_produce || 0)}"
                data-avg_sold_last_4_weeks="${Number(row.avg_sold_last_4_weeks || 0)}"
                data-coverage_weeks="${Number(row.coverage_weeks || 0)}"
                data-ordered_quantity_yoy="${Number(row.ordered_quantity_yoy || 0)}"
                data-state_label="${escapeHtml(String(row.state_label || '').toLowerCase())}">
                <td><strong>${escapeHtml(row.name)}</strong></td>
                <td class="text-end">${fmt(row.ordered_quantity)}</td>
                <td class="text-end">${fmt(row.in_stock)}</td>
                <td class="text-end">${fmt(row.palletised)}</td>
                <td class="text-end fw-bold ${remainingClass}">${fmt(row.remaining_to_produce)}</td>
                <td class="text-end">${fmt(row.avg_sold_last_4_weeks, 2)}</td>
                <td class="text-end">${row.coverage_weeks !== null ? fmt(row.coverage_weeks, 1) + ' tyg.' : '-'}</td>
                <td class="text-end">${fmt(row.ordered_quantity_yoy)}</td>
                <td><span class="badge bg-${escapeHtml(row.state)}">${escapeHtml(row.state_label)}</span></td>
            </tr>`;
        }).join('');

        document.getElementById('emptyRows').classList.toggle('d-none', visibleRows.length > 0);
        renderFilterBadges();
        bindGroupToggles();
        applyTableVisibility();
        updateSortButtons();
        updateToggleAllButton();
    }

    function filterRowsByBadges(rows) {
        return rows.filter(row => {
            const sectionKey = groupKey(row.section_name || 'Bez działu');
            const categoryKey = groupKey(row.section_name || 'Bez działu', row.category_name || 'Bez kategorii');

            return (stockFilters.sectionKeys.size === 0 || stockFilters.sectionKeys.has(sectionKey))
                && (stockFilters.categoryKeys.size === 0 || stockFilters.categoryKeys.has(categoryKey));
        });
    }

    function getGroupFilterOptions(rows) {
        const sections = new Map();
        const categories = new Map();

        rows.forEach(row => {
            const sectionName = row.section_name || 'Bez działu';
            const categoryName = row.category_name || 'Bez kategorii';
            const sectionKey = groupKey(sectionName);
            const categoryKey = groupKey(sectionName, categoryName);

            sections.set(sectionKey, { key: sectionKey, label: sectionName });

            if (stockFilters.sectionKeys.size === 0 || stockFilters.sectionKeys.has(sectionKey)) {
                categories.set(categoryKey, { key: categoryKey, label: categoryName, sectionKey });
            }
        });

        const sortByLabel = (a, b) => a.label.localeCompare(b.label, 'pl', { sensitivity: 'base' });

        return {
            sections: [...sections.values()].sort(sortByLabel),
            categories: [...categories.values()].sort(sortByLabel)
        };
    }

    function renderFilterBadges() {
        const options = getGroupFilterOptions(stockRowsData);
        renderBadgeGroup('sectionFilterBadges', options.sections, stockFilters.sectionKeys, sectionKey => {
            toggleFilterValue(stockFilters.sectionKeys, sectionKey);
            removeHiddenCategoryFilters();
            sortStockRows(stockSortState.key, stockSortState.type, stockSortState.direction);
        });
        renderBadgeGroup('categoryFilterBadges', options.categories, stockFilters.categoryKeys, categoryKey => {
            toggleFilterValue(stockFilters.categoryKeys, categoryKey);
            sortStockRows(stockSortState.key, stockSortState.type, stockSortState.direction);
        });
    }

    function toggleFilterValue(collection, key) {
        if (!key) {
            collection.clear();
            return;
        }

        if (collection.has(key)) {
            collection.delete(key);
        } else {
            collection.add(key);
        }
    }

    function removeHiddenCategoryFilters() {
        const visibleCategoryKeys = new Set(getGroupFilterOptions(stockRowsData).categories.map(category => category.key));
        [...stockFilters.categoryKeys].forEach(categoryKey => {
            if (!visibleCategoryKeys.has(categoryKey)) {
                stockFilters.categoryKeys.delete(categoryKey);
            }
        });
    }

    function renderBadgeGroup(containerId, options, activeKeys, onSelect) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const allActive = activeKeys.size === 0;
        const buttons = [`<button type="button" class="badge stock-filter-badge ${allActive ? 'bg-primary is-active' : 'bg-light text-dark border'}" data-filter-key="">Wszystkie</button>`];
        options.forEach(option => {
            const active = activeKeys.has(option.key);
            buttons.push(`<button type="button" class="badge stock-filter-badge ${active ? 'bg-primary is-active' : 'bg-light text-dark border'}" data-filter-key="${escapeHtml(option.key)}">${escapeHtml(option.label)}</button>`);
        });

        container.innerHTML = buttons.join('');
        container.querySelectorAll('.stock-filter-badge').forEach(button => {
            button.addEventListener('click', () => onSelect(button.dataset.filterKey || ''));
        });
    }

    function buildGroupedRows(rows) {
        const groupedRows = [];
        let lastSectionKey = null;
        let lastCategoryKey = null;

        rows.forEach(row => {
            const sectionName = row.section_name || 'Bez działu';
            const categoryName = row.category_name || 'Bez kategorii';
            const sectionKey = groupKey(sectionName);
            const categoryKey = groupKey(sectionName, categoryName);

            if (sectionKey !== lastSectionKey) {
                groupedRows.push({ __groupType: 'section', label: `Dział: ${sectionName}`, sectionKey });
                lastSectionKey = sectionKey;
                lastCategoryKey = null;
            }

            if (categoryKey !== lastCategoryKey) {
                groupedRows.push({ __groupType: 'category', label: `Kategoria: ${categoryName}`, sectionKey, categoryKey });
                lastCategoryKey = categoryKey;
            }

            groupedRows.push(row);
        });

        return groupedRows;
    }

    function groupKey(...parts) {
        return parts.map(part => String(part || '').toLowerCase()).join('||');
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[char]));
    }

    async function refreshMonitor() {
        const btn = document.getElementById('applyOrders');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Przeliczam';

        const response = await api('/ajax/stock/supply-monitor', {
            method: 'POST',
            data: { order_ids: selectedOrderIds() }
        });

        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Przelicz panel';

        if (!response.ok) {
            Swal.fire({ icon: 'error', title: 'Błąd', text: 'Nie udało się przeliczyć panelu.' });
            return;
        }

        updateSummary(response.summary);
        stockRowsData = response.rows || [];
        stockFilters.sectionKeys.clear();
        stockFilters.categoryKeys.clear();
        collapseAllGroups(stockRowsData);
        sortStockRows(stockSortState.key, stockSortState.type, stockSortState.direction);
        bootstrap.Modal.getInstance(document.getElementById('ordersModal'))?.hide();
    }

    function filterStockRows() {
        applyTableVisibility();
    }

    function bindGroupToggles() {
        document.querySelectorAll('#stockRows [data-group-row]').forEach(row => {
            row.querySelector('.stock-group-toggle')?.addEventListener('click', () => {
                if (row.dataset.groupRow === 'section') {
                    toggleCollapsed(collapsedSections, row.dataset.section_key);
                } else {
                    toggleCollapsed(collapsedCategories, row.dataset.category_key);
                }

                applyTableVisibility();
                updateToggleAllButton();
            });
        });
    }

    function toggleCollapsed(collection, key) {
        if (collection.has(key)) {
            collection.delete(key);
        } else {
            collection.add(key);
        }
    }

    function applyTableVisibility() {
        const query = document.getElementById('stockSearch').value.trim().toLowerCase();
        const productRows = [...document.querySelectorAll('#stockRows .stock-product-row')];

        productRows.forEach(row => {
            const matchesSearch = query === '' || row.dataset.search.includes(query);
            const collapsedBySection = collapsedSections.has(row.dataset.section_key);
            const collapsedByCategory = collapsedCategories.has(row.dataset.category_key);

            row.classList.toggle('d-none', !matchesSearch || collapsedBySection || collapsedByCategory);
        });

        updateGroupRowsVisibility(query, productRows);
    }

    function updateGroupRowsVisibility(query, productRows) {
        document.querySelectorAll('#stockRows [data-group-row]').forEach(row => {
            const isSection = row.dataset.groupRow === 'section';
            const sectionKey = row.dataset.section_key;
            const categoryKey = row.dataset.category_key;

            const hasMatchingProduct = productRows.some(productRow => {
                if (productRow.dataset.section_key !== sectionKey) return false;
                if (!isSection && productRow.dataset.category_key !== categoryKey) return false;

                return query === '' || productRow.dataset.search.includes(query);
            });

            const hiddenByParent = !isSection && collapsedSections.has(sectionKey);
            row.classList.toggle('d-none', !hasMatchingProduct || hiddenByParent);

            const button = row.querySelector('.stock-group-toggle');
            if (!button) return;

            const expanded = isSection
                ? !collapsedSections.has(sectionKey)
                : !collapsedCategories.has(categoryKey);
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    function sortStockRows(key, type, direction) {
        const sortedRows = [...stockRowsData].sort((a, b) => {
            const sectionCompare = String(a.section_name || '').localeCompare(String(b.section_name || ''), 'pl', { sensitivity: 'base' });
            if (sectionCompare !== 0) return sectionCompare;

            const categoryCompare = String(a.category_name || '').localeCompare(String(b.category_name || ''), 'pl', { sensitivity: 'base' });
            if (categoryCompare !== 0) return categoryCompare;

            const rawA = a[key] ?? '';
            const rawB = b[key] ?? '';
            let rowCompare;

            if (type === 'number') {
                rowCompare = (Number(rawA) || 0) - (Number(rawB) || 0);
            } else {
                rowCompare = String(rawA).localeCompare(String(rawB), 'pl', { sensitivity: 'base' });
            }

            return direction === 'desc' ? -rowCompare : rowCompare;
        });

        renderRows(sortedRows);
    }

    function updateSortButtons() {
        document.querySelectorAll('.stock-sort').forEach(button => {
            button.classList.toggle('is-asc', button.dataset.sortKey === stockSortState.key && stockSortState.direction === 'asc');
            button.classList.toggle('is-desc', button.dataset.sortKey === stockSortState.key && stockSortState.direction === 'desc');
        });
    }


    document.querySelectorAll('.stock-sort').forEach(button => {
        button.addEventListener('click', () => {
            const key = button.dataset.sortKey;
            const type = button.dataset.sortType;
            const direction = stockSortState.key === key && stockSortState.direction === 'asc' ? 'desc' : 'asc';

            stockSortState = { key, type, direction };
            sortStockRows(key, type, direction);
        });
    });

    document.getElementById('toggleAllGroups').addEventListener('click', () => {
        if (allGroupsCollapsed()) {
            expandAllGroups();
        } else {
            collapseAllGroups(stockRowsData);
        }

        applyTableVisibility();
        updateToggleAllButton();
    });

    sortStockRows(stockSortState.key, stockSortState.type, stockSortState.direction);

    document.getElementById('applyOrders').addEventListener('click', refreshMonitor);
    document.getElementById('selectAllOrders').addEventListener('click', () => document.querySelectorAll('.order-checkbox').forEach(el => el.checked = true));
    document.getElementById('clearOrders').addEventListener('click', () => document.querySelectorAll('.order-checkbox').forEach(el => el.checked = false));
    document.getElementById('stockSearch').addEventListener('input', filterStockRows);
    document.getElementById('orderSearch').addEventListener('input', event => {
        const q = event.target.value.trim().toLowerCase();
        document.querySelectorAll('.order-row').forEach(row => {
            row.classList.toggle('d-none', q !== '' && !row.dataset.search.includes(q));
        });
    });

    function downloadCurrentStockPdf() {
        downloadPDF(`<?= ROOT ?>/ajax/stock/stock-state`);
    }
</script>
<?php $this->view("page_components/footer"); ?>
