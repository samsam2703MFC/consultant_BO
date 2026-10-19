<?php $this->view('page_components/header'); ?>
<div class="page-heading">
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-7 order-md-1 order-last">
                <h3>Audyt wag produktów</h3>
                <p class="text-subtitle text-muted mb-0">Widok prezentuje masę netto jednej sztuki produktu oraz wyliczoną masę netto zawartości kartonu.</p>
            </div>
            <div class="col-12 col-md-5 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active">Audyty</li>
                        <li class="breadcrumb-item active">Wagi produktów</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3"><div class="card mb-0"><div class="card-body py-3"><span class="text-muted small">Wszystkie produkty</span><h4 class="mb-0" id="countAll">—</h4></div></div></div>
            <div class="col-6 col-lg-3"><div class="card mb-0 border-danger"><div class="card-body py-3"><span class="text-danger small">Błędy</span><h4 class="mb-0 text-danger" id="countError">—</h4></div></div></div>
            <div class="col-6 col-lg-3"><div class="card mb-0 border-warning"><div class="card-body py-3"><span class="text-warning-emphasis small">Podejrzane</span><h4 class="mb-0 text-warning-emphasis" id="countWarning">—</h4></div></div></div>
            <div class="col-6 col-lg-3"><div class="card mb-0 border-success"><div class="card-body py-3"><span class="text-success small">Poprawne</span><h4 class="mb-0 text-success" id="countOk">—</h4></div></div></div>
        </div>

        <div class="card">
            <div class="card-header"><h4 class="card-title mb-0">Produkty wymagające weryfikacji wag</h4></div>
            <div class="card-body">
                <form id="auditFilters" class="row g-3 align-items-end">
                    <div class="col-12 col-lg-4"><label class="form-label" for="auditSearch">Szukaj nazwy lub ID</label><input type="search" class="form-control" id="auditSearch" placeholder="Nazwa produktu lub ID"></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="auditStatus">Status</label><select class="form-select" id="auditStatus"><option value="attention">Wymagające uwagi</option><option value="all">Wszystkie</option><option value="ERROR">Błędy</option><option value="WARNING">Podejrzane</option><option value="OK">Poprawne</option></select></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="auditActivity">Aktywność</label><select class="form-select" id="auditActivity"><option value="active">Aktywne</option><option value="inactive">Nieaktywne</option><option value="all">Wszystkie</option></select></div>
                    <div class="col-6 col-lg-2"><label class="form-label" for="auditSort">Sortowanie</label><select class="form-select" id="auditSort"><option value="status">Status audytu</option><option value="name">Nazwa</option><option value="id">ID</option><option value="weight">Masa</option></select></div>
                    <div class="col-6 col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-funnel me-1"></i>Filtruj</button><button class="btn btn-light" type="button" id="resetFilters" title="Wyczyść filtry"><i class="bi bi-arrow-counterclockwise"></i></button></div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>ID</th><th>Produkt</th><th>Kategoria</th><th>Aktywność</th><th class="text-end">Masa netto sztuki</th><th class="text-end">Sztuk/karton</th><th class="text-end">Masa netto zawartości kartonu</th><th>Status</th><th>Powód</th><th class="text-end">Akcje</th></tr></thead>
                    <tbody id="auditRows"><tr><td colspan="10" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Ładowanie…</td></tr></tbody>
                </table>
            </div>
            <div class="card-body d-flex justify-content-between align-items-center border-top"><small class="text-muted" id="paginationInfo"></small><div class="btn-group"><button class="btn btn-outline-secondary btn-sm" id="prevPage" type="button">← Poprzednia</button><button class="btn btn-outline-secondary btn-sm" id="nextPage" type="button">Następna →</button></div></div>
        </div>
    </section>
</div>

<div class="modal fade" id="weightModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="weightForm"><div class="modal-header"><h5 class="modal-title">Popraw masę netto produktu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><strong id="modalProductName"></strong><div class="text-muted small" id="modalProductId"></div></div><div class="mb-3"><label class="form-label" for="weightInput">Nowa masa netto</label><div class="input-group"><input class="form-control" type="number" min="0.0001" step="any" id="weightInput" required><span class="input-group-text">g</span></div><div class="form-text">Dotychczasowa masa: <span id="modalCurrentWeight"></span></div><div class="invalid-feedback d-block d-none" id="weightError"></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary" type="submit" id="saveWeight"><span class="button-label"><i class="bi bi-save me-1"></i>Zapisz masę</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button></div></form></div></div>

<?php $this->view('page_components/footer'); ?>
<script>
const auditState = { page: 1, per_page: 25, status: 'attention', activity: 'active', sort: 'status', direction: 'asc', search: '' };
let auditMeta = null;
let selectedProduct = null;
const weightModal = new bootstrap.Modal(document.getElementById('weightModal'));

async function auditApi(endpoint, { method = 'GET', data = null } = {}) {
    let url = endpoint;
    const options = { method, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (method === 'GET' && data) {
        url += '?' + new URLSearchParams(data).toString();
    } else if (data) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }
    const response = await fetch(url, options);
    let payload = {};
    try { payload = await response.json(); } catch (_) {}
    return { ok: response.ok, status: response.status, ...payload };
}

function formatGrams(value) {
    if (value === null || value === undefined) return '—';
    const grams = Number(value);
    const formatted = grams.toLocaleString('pl-PL', { maximumFractionDigits: 4 });
    return grams >= 1000 ? `${formatted} g (${(grams / 1000).toLocaleString('pl-PL', { maximumFractionDigits: 4 })} kg)` : `${formatted} g`;
}
function statusBadge(status) { return status === 'ERROR' ? '<span class="badge bg-danger">Błąd</span>' : status === 'WARNING' ? '<span class="badge bg-warning text-dark">Podejrzane</span>' : '<span class="badge bg-success">Poprawne</span>'; }
function escapeHtml(value) { const node = document.createElement('div'); node.textContent = value ?? ''; return node.innerHTML; }

async function loadAudit() {
    const body = document.getElementById('auditRows');
    body.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Ładowanie…</td></tr>';
    const response = await auditApi('<?= ROOT ?>/ajax/audits/product-weights', { data: auditState });
    if (!response.ok) { body.innerHTML = `<tr><td colspan="10" class="text-center text-danger py-4">${escapeHtml(response.message || 'Nie udało się pobrać audytu.')}</td></tr>`; return; }
    renderAudit(response.data, response.meta);
}

function renderAudit(rows, meta) {
    auditMeta = meta;
    const counts = meta.counts;
    document.getElementById('countAll').textContent = counts.all;
    document.getElementById('countError').textContent = counts.error;
    document.getElementById('countWarning').textContent = counts.warning;
    document.getElementById('countOk').textContent = counts.ok;
    const body = document.getElementById('auditRows');
    if (!rows.length) { body.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">Brak produktów dla wybranych filtrów.</td></tr>'; }
    else body.innerHTML = rows.map(row => `<tr><td><code>${row.id_product}</code></td><td><strong>${escapeHtml(row.name)}</strong></td><td>${escapeHtml(row.category_name)}</td><td>${row.is_active ? '<span class="badge bg-success">Aktywny</span>' : '<span class="badge bg-secondary">Nieaktywny</span>'}</td><td class="text-end">${formatGrams(row.unit_weight_g)}</td><td class="text-end">${row.units_per_carton === null ? '—' : Number(row.units_per_carton).toLocaleString('pl-PL')}</td><td class="text-end small">${row.carton_net_content_weight_g === null ? `<span class="text-muted">${escapeHtml(row.carton_weight_reason)}</span>` : formatGrams(row.carton_net_content_weight_g)}</td><td>${statusBadge(row.audit_status)}</td><td class="small">${row.audit_reason ? escapeHtml(row.audit_reason) : '—'}</td><td class="text-end"><button class="btn btn-outline-primary btn-sm weight-edit" type="button" data-id="${row.id_product}" data-name="${escapeHtml(row.name)}" data-weight="${row.unit_weight_g ?? ''}"><i class="bi bi-pencil me-1"></i>Popraw wagę</button></td></tr>`).join('');
    document.getElementById('paginationInfo').textContent = `Strona ${meta.page} z ${meta.pages} · ${meta.total} produktów`;
    document.getElementById('prevPage').disabled = meta.page <= 1;
    document.getElementById('nextPage').disabled = meta.page >= meta.pages;
}

function openWeightModal(button) {
    selectedProduct = Number(button.dataset.id);
    const currentWeight = button.dataset.weight === '' ? null : Number(button.dataset.weight);
    document.getElementById('modalProductName').textContent = button.dataset.name;
    document.getElementById('modalProductId').textContent = `ID / SKU: ${selectedProduct}`;
    document.getElementById('modalCurrentWeight').textContent = formatGrams(currentWeight);
    document.getElementById('weightInput').value = currentWeight ?? '';
    document.getElementById('weightError').classList.add('d-none');
    weightModal.show();
}

document.getElementById('auditRows').addEventListener('click', event => {
    const button = event.target.closest('.weight-edit');
    if (button) openWeightModal(button);
});

document.getElementById('auditFilters').addEventListener('submit', event => { event.preventDefault(); auditState.search = document.getElementById('auditSearch').value.trim(); auditState.status = document.getElementById('auditStatus').value; auditState.activity = document.getElementById('auditActivity').value; auditState.sort = document.getElementById('auditSort').value; auditState.page = 1; loadAudit(); });
document.getElementById('resetFilters').addEventListener('click', () => { document.getElementById('auditSearch').value = ''; document.getElementById('auditStatus').value = 'attention'; document.getElementById('auditActivity').value = 'active'; document.getElementById('auditSort').value = 'status'; Object.assign(auditState, {page: 1, status: 'attention', activity: 'active', sort: 'status', search: ''}); loadAudit(); });
document.getElementById('prevPage').addEventListener('click', () => { if (auditMeta?.page > 1) { auditState.page--; loadAudit(); } });
document.getElementById('nextPage').addEventListener('click', () => { if (auditMeta && auditMeta.page < auditMeta.pages) { auditState.page++; loadAudit(); } });
document.getElementById('weightForm').addEventListener('submit', async event => { event.preventDefault(); const input = document.getElementById('weightInput'); const error = document.getElementById('weightError'); const value = input.value.trim(); if (!/^\d+(?:[.,]\d+)?$/.test(value) || Number(value.replace(',', '.')) <= 0) { error.textContent = 'Podaj liczbę większą od 0 g.'; error.classList.remove('d-none'); return; } const button = document.getElementById('saveWeight'); button.disabled = true; button.querySelector('.button-label').classList.add('d-none'); button.querySelector('.spinner-border').classList.remove('d-none'); error.classList.add('d-none'); try { const response = await auditApi(`<?= ROOT ?>/ajax/audits/product-weights/${selectedProduct}`, { method: 'PATCH', data: {weight_g: value.replace(',', '.')} }); if (!response.ok) { error.textContent = response.message || 'Nie udało się zapisać masy.'; error.classList.remove('d-none'); return; } weightModal.hide(); await Swal.fire({ icon: 'success', title: 'Zapisano', text: response.message || 'Masa produktu została zaktualizowana.', timer: 1600, showConfirmButton: false }); await loadAudit(); } catch (e) { error.textContent = 'Nie udało się połączyć z serwerem.'; error.classList.remove('d-none'); } finally { button.disabled = false; button.querySelector('.button-label').classList.remove('d-none'); button.querySelector('.spinner-border').classList.add('d-none'); } });
loadAudit();
</script>
