<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Obsługiwane języki</h3>
                <p class="text-subtitle text-muted">Zarządzaj językami używanymi do generowania etykiet produktów</p>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Lista języków</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLanguageModal">
                    <i class="bi bi-plus-lg"></i> Dodaj język
                </button>
            </div>
            <div class="card-body">
                <table class="table table-hover" id="languagesTable">
                    <thead>
                        <tr>
                            <th style="width: 15%">Kod (ISO 639-1)</th>
                            <th style="width: 50%">Nazwa</th>
                            <th style="width: 15%">Aktywny</th>
                            <th style="width: 20%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($languages as $lang): ?>
                        <tr id="lang-row-<?= (int)$lang['id'] ?>">
                            <td><?= htmlspecialchars($lang['code']) ?></td>
                            <td><?= htmlspecialchars($lang['name']) ?></td>
                            <td>
                                <?php if ($lang['is_active']): ?>
                                    <span class="badge bg-success">Tak</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nie</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning"
                                        onclick="openEditModal(<?= (int)$lang['id'] ?>, '<?= htmlspecialchars($lang['code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>', <?= (int)$lang['is_active'] ?>)">
                                    Edytuj
                                </button>
                                <button class="btn btn-sm btn-danger"
                                        onclick="deleteLanguage(<?= (int)$lang['id'] ?>, '<?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>')">
                                    Usuń
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($languages)): ?>
                        <tr id="lang-empty-row">
                            <td colspan="4" class="text-center">Brak skonfigurowanych języków</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<!-- Modal: Dodaj język -->
<div class="modal fade" id="addLanguageModal" tabindex="-1" aria-labelledby="addLanguageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addLanguageModalLabel">Dodaj język</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="addCode" class="form-label">Kod języka (ISO 639-1, np. pl, fr, en)</label>
                    <input type="text" class="form-control" id="addCode" maxlength="2" placeholder="pl">
                </div>
                <div class="mb-3">
                    <label for="addName" class="form-label">Nazwa przyjazna użytkownikowi</label>
                    <input type="text" class="form-control" id="addName" placeholder="Polski">
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="addIsActive" checked>
                    <label class="form-check-label" for="addIsActive">Aktywny</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="createLanguage()">Dodaj</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Edytuj język -->
<div class="modal fade" id="editLanguageModal" tabindex="-1" aria-labelledby="editLanguageModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editLanguageModalLabel">Edytuj język</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editId">
                <div class="mb-3">
                    <label for="editCode" class="form-label">Kod języka (ISO 639-1, np. pl, fr, en)</label>
                    <input type="text" class="form-control" id="editCode" maxlength="2">
                </div>
                <div class="mb-3">
                    <label for="editName" class="form-label">Nazwa przyjazna użytkownikowi</label>
                    <input type="text" class="form-control" id="editName">
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="editIsActive">
                    <label class="form-check-label" for="editIsActive">Aktywny</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="updateLanguage()">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<script>
    const hasSwal = (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function');

    function openEditModal(id, code, name, isActive) {
        document.getElementById('editId').value = id;
        document.getElementById('editCode').value = code;
        document.getElementById('editName').value = name;
        document.getElementById('editIsActive').checked = !!isActive;
        const modal = new bootstrap.Modal(document.getElementById('editLanguageModal'));
        modal.show();
    }

    async function createLanguage() {
        const code     = document.getElementById('addCode').value.trim();
        const name     = document.getElementById('addName').value.trim();
        const isActive = document.getElementById('addIsActive').checked ? 1 : 0;

        try {
            const data = await api('/ajax/settings/languages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                data: { code, name, is_active: isActive }
            });

            if (data?.ok) {
                location.reload();
            } else {
                const msg = data?.error || 'Nie udało się dodać języka.';
                hasSwal ? Swal.fire('Błąd', msg, 'error') : alert(msg);
            }
        } catch (e) {
            const msg = e?.message ? ('Błąd: ' + e.message) : 'Wystąpił błąd.';
            hasSwal ? Swal.fire('Błąd', msg, 'error') : alert(msg);
        }
    }

    async function updateLanguage() {
        const id       = document.getElementById('editId').value;
        const code     = document.getElementById('editCode').value.trim();
        const name     = document.getElementById('editName').value.trim();
        const isActive = document.getElementById('editIsActive').checked ? 1 : 0;

        try {
            const data = await api('/ajax/settings/languages/' + id, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                data: { code, name, is_active: isActive }
            });

            if (data?.ok) {
                location.reload();
            } else {
                const msg = data?.error || 'Nie udało się zaktualizować języka.';
                hasSwal ? Swal.fire('Błąd', msg, 'error') : alert(msg);
            }
        } catch (e) {
            const msg = e?.message ? ('Błąd: ' + e.message) : 'Wystąpił błąd.';
            hasSwal ? Swal.fire('Błąd', msg, 'error') : alert(msg);
        }
    }

    async function deleteLanguage(id, name) {
        if (hasSwal) {
            const result = await Swal.fire({
                title: 'Potwierdzenie',
                text: 'Czy na pewno chcesz usunąć język "' + name + '"?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Tak, usuń',
                cancelButtonText: 'Anuluj'
            });
            if (!result.isConfirmed) return;
        } else {
            if (!confirm('Czy na pewno chcesz usunąć język "' + name + '"?')) return;
        }

        try {
            const data = await api('/ajax/settings/languages/' + id, { method: 'DELETE' });

            if (data?.ok) {
                location.reload();
            } else {
                const msg = data?.error || 'Nie udało się usunąć języka.';
                hasSwal ? Swal.fire('Błąd', msg, 'error') : alert(msg);
            }
        } catch (e) {
            const msg = e?.message ? ('Błąd: ' + e.message) : 'Wystąpił błąd.';
            hasSwal ? Swal.fire('Błąd', msg, 'error') : alert(msg);
        }
    }
</script>

<?php $this->view("page_components/footer"); ?>
