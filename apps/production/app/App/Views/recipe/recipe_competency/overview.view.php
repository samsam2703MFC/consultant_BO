<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Kompetencje recepturowe</h3>
                <p class="text-subtitle text-muted">Zarządzaj wymaganymi kompetencjami do wykonywania receptur</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="#">HR</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Kompetencje recepturowe</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">Lista kompetencji recepturowych</h4>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <i class="bi bi-plus-lg me-1"></i> Dodaj kompetencję
                </button>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-4">
                        <input class="form-control" type="text" id="searchInput"
                               placeholder="Szukaj po nazwie..."
                               onkeyup="filterTable(this.value)">
                    </div>
                </div>
                <table class="table table-hover" id="competencyTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:35%">Nazwa</th>
                            <th style="width:50%">Opis</th>
                            <th style="width:15%" class="text-end">Akcje</th>
                        </tr>
                    </thead>
                    <tbody id="competencyTableBody">
                    <?php foreach ($competencies as $c): ?>
                        <tr id="row-<?= $c['id'] ?>">
                            <td class="fw-semibold"><?= htmlspecialchars($c['name']) ?></td>
                            <td class="text-muted"><?= nl2br(htmlspecialchars($c['description'])) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-warning me-1"
                                        onclick="openEditModal(<?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['name'])) ?>, <?= htmlspecialchars(json_encode($c['description'])) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger"
                                        onclick="openDeleteModal(<?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['name'])) ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($competencies)): ?>
                        <tr id="emptyRow">
                            <td colspan="3" class="text-center text-muted py-4">Brak zdefiniowanych kompetencji.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<!-- ===================== MODAL: DODAJ ===================== -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2 text-primary"></i>Nowa kompetencja recepturowa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="createName" placeholder="np. Obsługa pieca konwekcyjnego">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Opis</label>
                    <textarea class="form-control" id="createDescription" rows="4"
                              placeholder="Szczegółowy opis wymagań i zakresu kompetencji..."></textarea>
                </div>
                <div id="createErrors" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="submitCreate()">
                    <span id="createBtnText">Zapisz</span>
                    <span id="createBtnSpinner" class="spinner-border spinner-border-sm d-none ms-1"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MODAL: EDYTUJ ===================== -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-warning"></i>Edytuj kompetencję</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="editName">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Opis</label>
                    <textarea class="form-control" id="editDescription" rows="4"></textarea>
                </div>
                <div id="editErrors" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-warning" onclick="submitEdit()">
                    <span id="editBtnText">Zapisz zmiany</span>
                    <span id="editBtnSpinner" class="spinner-border spinner-border-sm d-none ms-1"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MODAL: USUŃ ===================== -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Usuń kompetencję</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="deleteId">
                <p>Czy na pewno chcesz usunąć kompetencję <strong id="deleteNameLabel"></strong>?</p>
                <p class="text-muted small">Zostanie ona automatycznie odpięta od wszystkich receptur.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-danger" onclick="submitDelete()">Usuń</button>
            </div>
        </div>
    </div>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
const ROOT = '<?= ROOT ?>';

// ── helpers ────────────────────────────────────────────────
function showErrors(containerId, errors) {
    const el = document.getElementById(containerId);
    el.innerHTML = errors.map(e => `<div>${e}</div>`).join('');
    el.classList.remove('d-none');
}
function hideErrors(containerId) {
    const el = document.getElementById(containerId);
    el.innerHTML = '';
    el.classList.add('d-none');
}
function setBusy(btnTextId, spinnerId, busy) {
    document.getElementById(btnTextId).classList.toggle('d-none', busy);
    document.getElementById(spinnerId).classList.toggle('d-none', !busy);
}
function filterTable(val) {
    const filter = val.toUpperCase();
    document.querySelectorAll('#competencyTableBody tr[id^="row-"]').forEach(tr => {
        const name = tr.querySelector('td')?.textContent || '';
        tr.style.display = name.toUpperCase().includes(filter) ? '' : 'none';
    });
}

// ── CREATE ─────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('createName').value = '';
    document.getElementById('createDescription').value = '';
    hideErrors('createErrors');
    new bootstrap.Modal(document.getElementById('createModal')).show();
}

async function submitCreate() {
    const name        = document.getElementById('createName').value.trim();
    const description = document.getElementById('createDescription').value.trim();
    hideErrors('createErrors');
    setBusy('createBtnText', 'createBtnSpinner', true);

    try {
        const res  = await fetch(`${ROOT}/ajax/recipe-competencies`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({name, description})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            showErrors('createErrors', data.errors ?? [data.message ?? 'Błąd zapisu.']);
            return;
        }
        bootstrap.Modal.getInstance(document.getElementById('createModal')).hide();
        appendRow(data.competency);
        removeEmptyRow();
    } finally {
        setBusy('createBtnText', 'createBtnSpinner', false);
    }
}

// ── EDIT ───────────────────────────────────────────────────
function openEditModal(id, name, description) {
    document.getElementById('editId').value          = id;
    document.getElementById('editName').value        = name;
    document.getElementById('editDescription').value = description;
    hideErrors('editErrors');
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

async function submitEdit() {
    const id          = document.getElementById('editId').value;
    const name        = document.getElementById('editName').value.trim();
    const description = document.getElementById('editDescription').value.trim();
    hideErrors('editErrors');
    setBusy('editBtnText', 'editBtnSpinner', true);

    try {
        const res  = await fetch(`${ROOT}/ajax/recipe-competencies/${id}`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({name, description})
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            showErrors('editErrors', data.errors ?? [data.message ?? 'Błąd aktualizacji.']);
            return;
        }
        bootstrap.Modal.getInstance(document.getElementById('editModal')).hide();
        updateRow(data.competency);
    } finally {
        setBusy('editBtnText', 'editBtnSpinner', false);
    }
}

// ── DELETE ─────────────────────────────────────────────────
function openDeleteModal(id, name) {
    document.getElementById('deleteId').value        = id;
    document.getElementById('deleteNameLabel').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

async function submitDelete() {
    const id  = document.getElementById('deleteId').value;
    const res = await fetch(`${ROOT}/ajax/recipe-competencies/${id}`, {method: 'DELETE'});
    const data = await res.json();
    if (!res.ok || !data.ok) { alert('Błąd usunięcia.'); return; }
    bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
    document.getElementById(`row-${id}`)?.remove();
}

// ── DOM helpers ────────────────────────────────────────────
function appendRow(c) {
    const tbody = document.getElementById('competencyTableBody');
    const tr = document.createElement('tr');
    tr.id = `row-${c.id}`;
    tr.innerHTML = buildRowHtml(c);
    tbody.appendChild(tr);
}
function updateRow(c) {
    const tr = document.getElementById(`row-${c.id}`);
    if (tr) tr.innerHTML = buildRowHtml(c);
}
function buildRowHtml(c) {
    const name = escHtml(c.name);
    const desc = escHtml(c.description ?? '').replace(/\n/g,'<br>');
    const nameJ = JSON.stringify(c.name);
    const descJ = JSON.stringify(c.description ?? '');
    return `
        <td class="fw-semibold">${name}</td>
        <td class="text-muted">${desc}</td>
        <td class="text-end">
            <button class="btn btn-sm btn-warning me-1" onclick="openEditModal(${c.id}, ${nameJ}, ${descJ})">
                <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-danger" onclick="openDeleteModal(${c.id}, ${nameJ})">
                <i class="bi bi-trash"></i>
            </button>
        </td>`;
}
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function removeEmptyRow() {
    document.getElementById('emptyRow')?.remove();
}
</script>

