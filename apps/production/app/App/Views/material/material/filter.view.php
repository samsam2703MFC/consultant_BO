<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Surowiec</h3>
                    <p class="text-subtitle text-muted">Navbar will appear in top of the page.</p>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.html">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Layout Vertical Navbar
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Lista produktów  <a href="<?=ROOT?>/material-categories/<?=$id_category?>/materials/new" class="btn btn-primary" role="button">Dodaj nowy</a></h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 text-center">
                            <h3>
                                <?= $category['name'] ?>
                            </h3>
                        </div>
                        <div class="col-12 mt-4">
                            <table class="table table-striped">
                                <thead>
                                <th>Nazwa</th>
                                <th>Obecny dostawca</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                </thead>
                                <tbody>
                                <?php if(!empty($rows)): ?>
                                    <?php foreach($rows as $row): ?>
                                        <tr>
                                            <td><?= $row['name'] ?></td>
                                            <td><?= $row['supplier_name'] ?></td>
                                            <td>
                                                <button
                                                        type="button"
                                                        class="btn btn-outline-secondary"
                                                        onclick="openAllergensModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES) ?>')"
                                                >
                                                    Alergeny
                                                </button>
                                            </td>
                                            <td><a href="<?=ROOT?>/materials/<?=$row['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                            <td><a href="<?=ROOT?>/materials/<?=$row['id']?>/delete" class="btn btn-danger">Usuń</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-12">
                            <a href="<?= ROOT ?>/material-categories" class="btn btn-secondary" type="button" >Powrót do kategorii</a>
                        </div>
                    </div>

                </div>
            </div>
        </section>
    </div>

    <!-- Modal: alergeny surowca -->
    <div class="modal fade" id="allergensModal" tabindex="-1" aria-labelledby="allergensModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="allergensModalLabel">Alergeny surowca</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">

                    <input type="hidden" id="allergens-material-id" value="">

                    <div class="alert alert-info py-2" id="allergens-material-name" style="display:none;"></div>

                    <div id="allergens-loading" class="text-muted" style="display:none;">Ładowanie…</div>

                    <div class="row" id="allergens-checkboxes">
                        <?php if(!empty($allergens)): ?>
                            <?php foreach($allergens as $aid => $a): ?>
                                <div class="col-12 col-md-6 col-lg-4 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input allergen-checkbox" type="checkbox"
                                               id="allergen_<?= (int)$aid ?>"
                                               value="<?= (int)$aid ?>">
                                        <label class="form-check-label" for="allergen_<?= (int)$aid ?>">
                                            <?= htmlspecialchars($a['name'] ?? $a['code'] ?? '-', ENT_QUOTES) ?>
                                        </label>
                                        <div class="text-muted small"><?= htmlspecialchars($a['code'] ?? '', ENT_QUOTES) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-0">Brak zdefiniowanych alergenów.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3 d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-secondary" onclick="selectAllAllergens(true)">Zaznacz wszystkie</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="selectAllAllergens(false)">Odznacz wszystkie</button>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
                    <button type="button" class="btn btn-primary" id="allergens-save-btn" onclick="saveAllergens()">Zapisz</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let allergensModalInstance = null;

        function getAllergenCheckboxes() {
            return Array.from(document.querySelectorAll('#allergens-checkboxes .allergen-checkbox'));
        }

        function selectAllAllergens(checked) {
            getAllergenCheckboxes().forEach(cb => cb.checked = !!checked);
        }

        function setAllergensLoading(isLoading) {
            const loading = document.getElementById('allergens-loading');
            const btnSave = document.getElementById('allergens-save-btn');

            if (loading) loading.style.display = isLoading ? '' : 'none';
            if (btnSave) btnSave.disabled = !!isLoading;
        }

        function openAllergensModal(materialId, materialName) {
            const idInput = document.getElementById('allergens-material-id');
            const nameBox = document.getElementById('allergens-material-name');

            if (idInput) idInput.value = String(materialId);
            if (nameBox) {
                nameBox.style.display = '';
                nameBox.textContent = `Surowiec: ${materialName}`;
            }

            // wyczyść
            selectAllAllergens(false);

            const el = document.getElementById('allergensModal');
            allergensModalInstance = allergensModalInstance || new bootstrap.Modal(el);
            allergensModalInstance.show();

            // dociągnij aktualne alergeny dla materiału
            setAllergensLoading(true);

            api(`/ajax/materials/${materialId}/allergens`, { method: 'GET' })
                .then(result => {
                    if (!result.ok) {
                        showSnack(false, result.message || 'Nie udało się pobrać alergenów');
                        return;
                    }

                    const rows = result.rows || result.data?.rows || [];
                    const selectedIds = (rows || []).map(r => parseInt(r.id, 10)).filter(n => !Number.isNaN(n));

                    getAllergenCheckboxes().forEach(cb => {
                        const id = parseInt(cb.value, 10);
                        cb.checked = selectedIds.includes(id);
                    });
                })
                .catch(err => {
                    console.error(err);
                    showSnack(false, 'Błąd komunikacji');
                })
                .finally(() => setAllergensLoading(false));
        }

        function saveAllergens() {
            const materialId = parseInt(document.getElementById('allergens-material-id')?.value || '0', 10);
            if (!materialId) {
                showSnack(false, 'Brak ID surowca');
                return;
            }

            const allergenIds = getAllergenCheckboxes()
                .filter(cb => cb.checked)
                .map(cb => parseInt(cb.value, 10))
                .filter(n => !Number.isNaN(n));

            setAllergensLoading(true);

            api(`/ajax/materials/${materialId}/allergens`, {
                method: 'PATCH',
                data: { allergenIds }
            })
                .then(result => {
                    if (result.ok) {
                        showSnack(true, result.message || 'Zapisano');
                        if (allergensModalInstance) allergensModalInstance.hide();
                    } else {
                        showSnack(false, result.message || 'Nie udało się zapisać');
                    }
                })
                .catch(err => {
                    console.error(err);
                    showSnack(false, 'Błąd komunikacji');
                })
                .finally(() => setAllergensLoading(false));
        }
    </script>

<?php $this->view("page_components/footer"); ?>