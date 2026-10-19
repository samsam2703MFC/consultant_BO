<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3><?= $translations['preparation_steps'] ?? 'Kroki przygotowania' ?></h3>
                <p class="text-subtitle text-muted">
                    <?php if (!empty($recipe)): ?>
                        <?= htmlspecialchars($recipe['name']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/recipes">Receptury</a></li>
                        <li class="breadcrumb-item active" aria-current="page">
                            <?= $translations['preparation_steps'] ?? 'Kroki przygotowania' ?>
                        </li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger"><?= implode('<br>', $errors) ?></div>
        <?php endif; ?>

        <!-- ─── Karta: Informacje o recepturze ─────────────────────────────── -->
        <?php if (!empty($recipe)): ?>
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center gap-3">
                <i class="bi bi-journal-text fs-4 text-primary"></i>
                <div>
                    <h5 class="mb-0"><?= htmlspecialchars($recipe['name']) ?></h5>
                    <small class="text-muted">
                        <?= htmlspecialchars($recipe['type_name'] ?? '') ?>
                        <?php if (!empty($recipe['yield_quantity']) && !empty($recipe['id_unit'])): ?>
                            &nbsp;|&nbsp; Wydajność: <?= $recipe['yield_quantity'] ?> <?= htmlspecialchars($recipe['unit_name'] ?? '') ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="ms-auto">
                    <a href="<?= ROOT ?>/recipes/<?= $recipe_id ?>/calculation"
                       class="btn btn-sm btn-outline-info me-1">
                        <i class="bi bi-calculator"></i> Kalkulacja
                    </a>
                    <a href="<?= ROOT ?>/recipes/<?= $recipe_id ?>/edit"
                       class="btn btn-sm btn-outline-warning me-1">
                        <i class="bi bi-pencil"></i> Edytuj
                    </a>
                    <a href="<?= ROOT ?>/recipes" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Lista receptur
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Karta: Lista kroków ─────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">
                    <i class="bi bi-list-ol me-2"></i>
                    <?= $translations['preparation_steps'] ?? 'Kroki przygotowania' ?>
                    <span class="badge bg-primary ms-2" id="steps-count"><?= count($steps ?? []) ?></span>
                </h4>
                <button class="btn btn-primary btn-sm" id="btn-add-step">
                    <i class="bi bi-plus-circle me-1"></i> Dodaj krok
                </button>
            </div>
            <div class="card-body">

                <!-- Alert o drag & drop -->
                <div class="alert alert-light border d-flex align-items-center py-2 mb-3" role="alert">
                    <i class="bi bi-info-circle-fill text-primary me-2"></i>
                    <small>Przeciągnij i upuść krok, aby zmienić kolejność. Możesz też użyć strzałek.</small>
                </div>

                <!-- Lista kroków -->
                <ol class="list-group list-group-numbered" id="steps-list">
                    <?php if (!empty($steps)): ?>
                        <?php foreach ($steps as $step): ?>
                            <li class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3 step-item"
                                data-step-id="<?= $step['id'] ?>"
                                data-step-number="<?= $step['step_number'] ?>"
                                draggable="true">

                                <!-- Uchwyt drag -->
                                <span class="drag-handle text-muted" style="cursor: grab; font-size: 1.2rem; padding-top: 2px;">
                                    <i class="bi bi-grip-vertical"></i>
                                </span>

                                <!-- Numer kroku -->
                                <span class="step-number badge bg-primary rounded-pill fs-6 mt-1"
                                      style="min-width:2rem; height:2rem; display:inline-flex; align-items:center; justify-content:center;">
                                    <?= $step['step_number'] ?>
                                </span>

                                <!-- Treść (tryb podglądu / edycji) -->
                                <div class="flex-grow-1">
                                    <div class="step-view">
                                        <p class="mb-0 step-description-text"><?= nl2br(htmlspecialchars($step['step_description'])) ?></p>
                                    </div>
                                    <div class="step-edit d-none">
                                        <textarea class="form-control step-edit-textarea" rows="3"><?= htmlspecialchars($step['step_description']) ?></textarea>
                                        <div class="mt-2 d-flex gap-2">
                                            <button class="btn btn-success btn-sm btn-save-step">
                                                <i class="bi bi-check-lg"></i> Zapisz
                                            </button>
                                            <button class="btn btn-secondary btn-sm btn-cancel-edit">
                                                Anuluj
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Akcje -->
                                <div class="d-flex flex-column gap-1 step-actions">
                                    <button class="btn btn-outline-secondary btn-sm btn-move-up" title="Przesuń wyżej">
                                        <i class="bi bi-chevron-up"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm btn-move-down" title="Przesuń niżej">
                                        <i class="bi bi-chevron-down"></i>
                                    </button>
                                    <button class="btn btn-outline-primary btn-sm btn-edit-step" title="Edytuj">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm btn-delete-step" title="Usuń">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item text-center text-muted py-4" id="empty-state">
                            <i class="bi bi-clipboard2-x fs-2 d-block mb-2"></i>
                            Brak kroków przygotowania. Kliknij <strong>Dodaj krok</strong>, aby zacząć.
                        </li>
                    <?php endif; ?>
                </ol>

                <!-- ─── Formularz dodawania nowego kroku ──────────────────── -->
                <div class="card mt-4 border-primary d-none" id="add-step-form-card">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Nowy krok przygotowania</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Opis czynności <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="new-step-textarea" rows="4"
                                      placeholder="Opisz co należy zrobić w tym kroku..."></textarea>
                            <div class="invalid-feedback" id="new-step-error">Opis kroku jest wymagany.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Wstaw po kroku (opcjonalnie)</label>
                            <select class="form-select" id="new-step-after">
                                <option value="">Na końcu listy</option>
                                <?php if (!empty($steps)): ?>
                                    <?php foreach ($steps as $step): ?>
                                        <option value="<?= $step['step_number'] ?>">
                                            Po kroku <?= $step['step_number'] ?> – <?= htmlspecialchars(mb_substr($step['step_description'], 0, 60)) ?><?= mb_strlen($step['step_description']) > 60 ? '…' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" id="btn-submit-step">
                                <i class="bi bi-check-circle me-1"></i> Dodaj krok
                            </button>
                            <button class="btn btn-secondary" id="btn-cancel-add">
                                Anuluj
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<!-- ─────────────────────────────── MODAL POTWIERDZENIA USUNIĘCIA ──────── -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Usuń krok
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz usunąć ten krok przygotowania? Pozostałe kroki zostaną automatycznie przenumerowane.
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete">
                    <i class="bi bi-trash me-1"></i>Usuń
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    /* =====================================================================
     * Kroki przygotowania receptury – logika JavaScript
     * ===================================================================== */

    const RECIPE_ID  = <?= (int)$recipe_id ?>;
    const API_BASE   = `${window.API_BASE_URL}/ajax/recipes/${RECIPE_ID}/preparation-steps`;

    // ─── Pomocnicze ─────────────────────────────────────────────────────────

    function showSnack(msg, type = 'success') {
        const el = document.getElementById('snackbar');
        if (!el) return;
        el.textContent = msg;
        el.className   = `show ${type === 'error' ? 'bg-danger' : 'bg-success'} text-white`;
        setTimeout(() => { el.className = el.className.replace('show', ''); }, 3500);
    }

    function setLoading(btn, state) {
        if (!btn) return;
        if (state) {
            btn.disabled = true;
            btn._origHtml = btn.innerHTML;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span>`;
        } else {
            btn.disabled  = false;
            btn.innerHTML = btn._origHtml ?? btn.innerHTML;
        }
    }

    // ─── Renderowanie listy kroków po odpowiedzi API ─────────────────────────

    function renderSteps(steps) {
        const list       = document.getElementById('steps-list');
        const emptyState = document.getElementById('empty-state');
        const counter    = document.getElementById('steps-count');

        if (counter) counter.textContent = steps.length;

        if (steps.length === 0) {
            list.innerHTML = `
                <li class="list-group-item text-center text-muted py-4" id="empty-state">
                    <i class="bi bi-clipboard2-x fs-2 d-block mb-2"></i>
                    Brak kroków przygotowania. Kliknij <strong>Dodaj krok</strong>, aby zacząć.
                </li>`;
            // Odśwież select w formularzu dodawania
            refreshInsertAfterSelect([]);
            return;
        }

        list.innerHTML = steps.map(step => `
            <li class="list-group-item list-group-item-action d-flex gap-3 align-items-start py-3 step-item"
                data-step-id="${step.id}"
                data-step-number="${step.step_number}"
                draggable="true">

                <span class="drag-handle text-muted" style="cursor:grab;font-size:1.2rem;padding-top:2px;">
                    <i class="bi bi-grip-vertical"></i>
                </span>

                <span class="step-number badge bg-primary rounded-pill fs-6 mt-1"
                      style="min-width:2rem;height:2rem;display:inline-flex;align-items:center;justify-content:center;">
                    ${step.step_number}
                </span>

                <div class="flex-grow-1">
                    <div class="step-view">
                        <p class="mb-0 step-description-text">${escapeHtml(step.step_description).replace(/\n/g,'<br>')}</p>
                    </div>
                    <div class="step-edit d-none">
                        <textarea class="form-control step-edit-textarea" rows="3">${escapeHtml(step.step_description)}</textarea>
                        <div class="mt-2 d-flex gap-2">
                            <button class="btn btn-success btn-sm btn-save-step"><i class="bi bi-check-lg"></i> Zapisz</button>
                            <button class="btn btn-secondary btn-sm btn-cancel-edit">Anuluj</button>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column gap-1 step-actions">
                    <button class="btn btn-outline-secondary btn-sm btn-move-up" title="Przesuń wyżej"><i class="bi bi-chevron-up"></i></button>
                    <button class="btn btn-outline-secondary btn-sm btn-move-down" title="Przesuń niżej"><i class="bi bi-chevron-down"></i></button>
                    <button class="btn btn-outline-primary btn-sm btn-edit-step" title="Edytuj"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-outline-danger btn-sm btn-delete-step" title="Usuń"><i class="bi bi-trash"></i></button>
                </div>
            </li>
        `).join('');

        refreshInsertAfterSelect(steps);
        initDragDrop();
    }

    function refreshInsertAfterSelect(steps) {
        const sel = document.getElementById('new-step-after');
        if (!sel) return;
        sel.innerHTML = `<option value="">Na końcu listy</option>` +
            steps.map(s => {
                const short = s.step_description.length > 60
                    ? s.step_description.substring(0, 60) + '…'
                    : s.step_description;
                return `<option value="${s.step_number}">Po kroku ${s.step_number} – ${escapeHtml(short)}</option>`;
            }).join('');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ─── AJAX helpers ───────────────────────────────────────────────────────

    async function apiFetch(url, method, body = null) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (body) opts.body = JSON.stringify(body);
        const resp = await fetch(url, opts);
        return resp.json();
    }

    // ─── Dodawanie kroku ────────────────────────────────────────────────────

    document.getElementById('btn-add-step').addEventListener('click', () => {
        const card = document.getElementById('add-step-form-card');
        card.classList.remove('d-none');
        document.getElementById('new-step-textarea').focus();
    });

    document.getElementById('btn-cancel-add').addEventListener('click', () => {
        document.getElementById('add-step-form-card').classList.add('d-none');
        document.getElementById('new-step-textarea').value = '';
    });

    document.getElementById('btn-submit-step').addEventListener('click', async function () {
        const textarea = document.getElementById('new-step-textarea');
        const desc     = textarea.value.trim();

        if (!desc) {
            textarea.classList.add('is-invalid');
            return;
        }
        textarea.classList.remove('is-invalid');

        const afterVal    = document.getElementById('new-step-after').value;
        const insertAfter = afterVal !== '' ? parseInt(afterVal) : null;

        setLoading(this, true);
        try {
            const res = await apiFetch(API_BASE, 'POST', { description: desc, insert_after: insertAfter });
            if (res.ok) {
                renderSteps(res.steps);
                textarea.value = '';
                document.getElementById('add-step-form-card').classList.add('d-none');
                showSnack('Krok dodany pomyślnie.');
            } else {
                showSnack(res.error ?? 'Błąd podczas dodawania kroku.', 'error');
            }
        } catch (e) {
            showSnack('Błąd sieci.', 'error');
        } finally {
            setLoading(this, false);
        }
    });

    // ─── Delegacja zdarzeń na liście kroków ─────────────────────────────────

    document.getElementById('steps-list').addEventListener('click', async function (e) {
        const item = e.target.closest('.step-item');
        if (!item) return;

        const stepId = parseInt(item.dataset.stepId);

        // ── Edytuj ──
        if (e.target.closest('.btn-edit-step')) {
            item.querySelector('.step-view').classList.add('d-none');
            item.querySelector('.step-edit').classList.remove('d-none');
            item.querySelector('.step-edit-textarea').focus();
            return;
        }

        // ── Anuluj edycję ──
        if (e.target.closest('.btn-cancel-edit')) {
            item.querySelector('.step-view').classList.remove('d-none');
            item.querySelector('.step-edit').classList.add('d-none');
            return;
        }

        // ── Zapisz edycję ──
        if (e.target.closest('.btn-save-step')) {
            const btn  = e.target.closest('.btn-save-step');
            const desc = item.querySelector('.step-edit-textarea').value.trim();
            if (!desc) return;
            setLoading(btn, true);
            try {
                const res = await apiFetch(`${API_BASE}/${stepId}`, 'PUT', { description: desc });
                if (res.ok) {
                    renderSteps(res.steps);
                    showSnack('Krok zaktualizowany.');
                } else {
                    showSnack(res.error ?? 'Błąd podczas aktualizacji.', 'error');
                }
            } finally {
                setLoading(btn, false);
            }
            return;
        }

        // ── Usuń ──
        if (e.target.closest('.btn-delete-step')) {
            pendingDeleteId = stepId;
            const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
            modal.show();
            return;
        }

        // ── Przesuń w górę ──
        if (e.target.closest('.btn-move-up')) {
            const btn = e.target.closest('.btn-move-up');
            setLoading(btn, true);
            try {
                const res = await apiFetch(`${API_BASE}/${stepId}/move`, 'POST', { direction: 'up' });
                if (res.ok) renderSteps(res.steps);
                else showSnack(res.error ?? 'Błąd.', 'error');
            } finally {
                setLoading(btn, false);
            }
            return;
        }

        // ── Przesuń w dół ──
        if (e.target.closest('.btn-move-down')) {
            const btn = e.target.closest('.btn-move-down');
            setLoading(btn, true);
            try {
                const res = await apiFetch(`${API_BASE}/${stepId}/move`, 'POST', { direction: 'down' });
                if (res.ok) renderSteps(res.steps);
                else showSnack(res.error ?? 'Błąd.', 'error');
            } finally {
                setLoading(btn, false);
            }
        }
    });

    // ─── Modal: potwierdzenie usunięcia ─────────────────────────────────────

    let pendingDeleteId = null;

    document.getElementById('btn-confirm-delete').addEventListener('click', async function () {
        if (!pendingDeleteId) return;
        setLoading(this, true);

        bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal')).hide();

        try {
            const res = await apiFetch(`${API_BASE}/${pendingDeleteId}`, 'DELETE');
            if (res.ok) {
                renderSteps(res.steps);
                showSnack('Krok usunięty.');
            } else {
                showSnack(res.error ?? 'Błąd podczas usuwania.', 'error');
            }
        } finally {
            setLoading(this, false);
            pendingDeleteId = null;
        }
    });

    // ─── Drag & Drop (reorder) ───────────────────────────────────────────────

    function initDragDrop() {
        const list  = document.getElementById('steps-list');
        let dragged = null;

        list.querySelectorAll('.step-item').forEach(item => {
            item.addEventListener('dragstart', e => {
                dragged = item;
                e.dataTransfer.effectAllowed = 'move';
                setTimeout(() => item.classList.add('opacity-50'), 0);
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('opacity-50');
                list.querySelectorAll('.step-item').forEach(i => i.classList.remove('drag-over'));
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                list.querySelectorAll('.step-item').forEach(i => i.classList.remove('drag-over'));
                if (item !== dragged) item.classList.add('drag-over');
            });
            item.addEventListener('drop', async e => {
                e.preventDefault();
                item.classList.remove('drag-over');
                if (!dragged || dragged === item) return;

                // Ustal nową kolejność
                const allItems = [...list.querySelectorAll('.step-item')];
                const fromIdx  = allItems.indexOf(dragged);
                const toIdx    = allItems.indexOf(item);

                allItems.splice(fromIdx, 1);
                allItems.splice(toIdx, 0, dragged);

                // Odbuduj DOM
                allItems.forEach(i => list.appendChild(i));

                const orderedIds = allItems.map(i => parseInt(i.dataset.stepId));

                try {
                    const res = await apiFetch(
                        `${window.API_BASE_URL}/ajax/recipes/${RECIPE_ID}/preparation-steps/reorder`,
                        'POST',
                        { ordered_ids: orderedIds }
                    );
                    if (res.ok) {
                        renderSteps(res.steps);
                        showSnack('Kolejność zaktualizowana.');
                    } else {
                        showSnack(res.error ?? 'Błąd przy zmianie kolejności.', 'error');
                    }
                } catch (err) {
                    showSnack('Błąd sieci.', 'error');
                }
            });
        });
    }

    // Inicjalizacja drag & drop przy ładowaniu strony
    initDragDrop();
</script>

<style>
    .step-item.drag-over {
        background-color: #e8f4ff !important;
        border: 2px dashed #0d6efd !important;
    }
    .step-item {
        transition: background-color .15s;
    }
    #steps-list .step-item:hover .drag-handle {
        color: #0d6efd !important;
    }
    .step-actions .btn {
        width: 2rem;
        height: 2rem;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
</style>

