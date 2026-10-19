<?php $this->view("page_components/header"); ?>

<div class="card">
    <div class="card-header">
        <h4 class="card-title">Lista produktów
            <?php if (isset($category['id'])): ?>
                <a href="<?= ROOT ?>/product-categories/<?= $category['id'] ?>/products/new" class="btn btn-primary"
                   role="button">Dodaj nowy</a>
            <?php else: ?>
                <a href="<?= ROOT ?>/products/new" class="btn btn-primary"
                   role="button">Dodaj nowy</a>
            <?php endif; ?>
        </h4>
    </div>
    <div class="card-body">

        <?php if (isset($category['name'])): ?>
            <div class="row">
                <div class="col-12 text-center">
                    <h2><?= $category['name'] ?></h2>
                </div>
            </div>
        <?php endif; ?>

        <div class="row mt-2">
            <div class="col-12">
                <table class="table table-striped" data-at-table>
                    <thead>
                    <th>Nazwa</th>
                    <th>Pracochłonność (1–5)</th>
                    <th>Aktywny</th>
                    <th>Waga</th>
                    <th>Minimalny magazyn</th>
                    <th class="text-end">Akcje</th>
                    </thead>
                    <tbody id="id_product_table">
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark" style="font-size: 1.05rem;">
                                        <?= esc($row['name'] ?? '') ?>
                                    </div>
                                    <div class="text-muted small">ID: <?= $row['id'] ?></div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="workload-value badge bg-light text-dark"
                                              data-product-id="<?= $row['id'] ?>">
                                            <?= $row['workload_points'] ?? 3 ?>
                                        </span>
                                        <button class="btn btn-outline-primary btn-sm workload-edit-btn"
                                                type="button"
                                                data-product-id="<?= $row['id'] ?>"
                                                data-product-name="<?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES) ?>"
                                                data-workload-points="<?= $row['workload_points'] ?? 3 ?>">
                                            Edytuj
                                        </button>
                                    </div>
                                </td>
                                <?php
                                $checked = ($row['active'] == "1") ? "checked" : "";
                                ?>
                                <td class="form-switch text-center"><input class="form-check-input" type="checkbox"
                                                                           name="active"
                                                                           onchange="update(<?= $row['id'] ?>, this);"
                                                                           value="<?= $row['active'] ?>" <?= $checked ?>>
                                </td>
                                <td><?= $row['weight'] ?></td>
                                <td><?= $row['stock_minimum'] ?></td>
                                <td class="text-end">
                                    <div class="at-actions">
                                        <a href="<?= ROOT ?>/products/<?= $row['id'] ?>/edit" class="btn btn-sm btn-primary">Edytuj</a>
                                        <div class="at-more">
                                            <button type="button" class="at-more-toggle" aria-haspopup="menu" aria-expanded="false" aria-label="Więcej akcji" title="Więcej akcji"><i class="bi bi-three-dots"></i></button>
                                            <div class="at-more-menu" role="menu" hidden>
                                                <?php if (!empty($row['id_recipe'])): ?>
                                                    <a role="menuitem" href="<?= ROOT ?>/recipes/<?= (int) $row['id_recipe'] ?>/calculation"><i class="bi bi-journal-text"></i>Receptura</a>
                                                    <button type="button" role="menuitem" class="product-card-btn" data-id-product="<?= $row['id'] ?>"><i class="bi bi-file-earmark-arrow-down"></i>Karta produktu (PDF)</button>
                                                <?php else: ?>
                                                    <button type="button" role="menuitem" disabled title="Produkt nie ma przypisanej receptury"><i class="bi bi-file-earmark-arrow-down"></i>Karta — brak receptury</button>
                                                <?php endif; ?>
                                                <hr>
                                                <a role="menuitem" class="at-more-danger" href="<?= ROOT ?>/products/<?= $row['id'] ?>/delete"><i class="bi bi-trash"></i>Usuń…</a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="row mt-4">
            <div class="col-4">
                <a href="<?= ROOT ?>/product-categories" role="button" class="btn btn-secondary">Powrót</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal edycji workload_points -->
<div class="modal fade" id="workloadModal" tabindex="-1" aria-labelledby="workloadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="workloadModalLabel">Edycja współczynnika trudności</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="workloadProductId" value="">
                <div class="mb-3">
                    <label class="form-label">Produkt</label>
                    <div class="form-control-plaintext" id="workloadProductName">-</div>
                </div>
                <div class="mb-3">
                    <label for="workloadSlider" class="form-label">Współczynnik trudności (1–5)</label>
                    <input type="range" class="form-range" id="workloadSlider" min="1" max="5" step="1" value="3">
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">1</small>
                        <span class="badge bg-primary" id="workloadValue">3</span>
                        <small class="text-muted">5</small>
                    </div>
                    <div class="mt-3">
                        <div class="alert alert-light border mb-2">
                            <strong>Wybrano:</strong> <span id="workloadDescription"></span>
                        </div>
                        <div class="small text-muted">
                            <div><strong>1</strong> – bardzo niska pracochłonność. Prosty proces, mało pracy ręcznej,
                                mało etapów.
                            </div>
                            <div><strong>2</strong> – niska pracochłonność. Raczej prosty produkt, ograniczone czynności
                                ręczne.
                            </div>
                            <div><strong>3</strong> – standardowa pracochłonność. Typowy produkt dla działu (punkt
                                odniesienia).
                            </div>
                            <div><strong>4</strong> – wysoka pracochłonność. Więcej etapów, więcej pracy ręcznej /
                                dokładności.
                            </div>
                            <div><strong>5</strong> – bardzo wysoka pracochłonność. Produkt wymagający, czasochłonny
                                operacyjnie, np. dekorowanie / precyzja / wiele kroków.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" id="saveWorkloadBtn">Zapisz</button>
            </div>
        </div>
    </div>
</div>

<?php $this->view("page_components/footer"); ?>

<script>

    document.querySelectorAll('.product-card-btn').forEach(button => {
        button.addEventListener('click', function () {
            const idProduct = this.getAttribute('data-id-product');

            downloadPDF(
                `/ajax/products/${idProduct}/technical-card`,
                {},
                "Karta_produktu"
            );

        });
    });

    function update(id, control) {
        const data = {
            id: id
        };
        data[control.name] = Number(control.checked);

        api(`/ajax/products/${id}`, {
            method: "PATCH",
            data
        })
            .then(data => {
                showSnack(data.ok)
            })
            .catch(error => console.error('Error:', error));

    }

    const workloadModalEl = document.getElementById('workloadModal');
    const workloadModal = new bootstrap.Modal(workloadModalEl);
    const workloadSlider = document.getElementById('workloadSlider');
    const workloadValue = document.getElementById('workloadValue');
    const workloadDescription = document.getElementById('workloadDescription');
    const workloadProductId = document.getElementById('workloadProductId');
    const workloadProductName = document.getElementById('workloadProductName');
    const saveWorkloadBtn = document.getElementById('saveWorkloadBtn');

    const workloadScale = {
        1: 'bardzo niska pracochłonność',
        2: 'niska pracochłonność',
        3: 'standardowa pracochłonność',
        4: 'wysoka pracochłonność',
        5: 'bardzo wysoka pracochłonność'
    };

    function normalizeWorkload(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) return 3;
        return Math.min(5, Math.max(1, Math.round(num)));
    }

    function updateWorkloadUI(value) {
        const normalized = normalizeWorkload(value);
        workloadSlider.value = normalized;
        workloadValue.textContent = String(normalized);
        workloadDescription.textContent = workloadScale[normalized];
    }

    document.querySelectorAll('.workload-edit-btn').forEach(button => {
        button.addEventListener('click', function () {
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product-name');
            const workloadPoints = this.getAttribute('data-workload-points');

            workloadProductId.value = productId;
            workloadProductName.textContent = productName || '-';
            updateWorkloadUI(workloadPoints);

            workloadModal.show();
        });
    });

    workloadSlider.addEventListener('input', function () {
        updateWorkloadUI(this.value);
    });

    saveWorkloadBtn.addEventListener('click', function () {
        const productId = workloadProductId.value;
        const newValue = normalizeWorkload(workloadSlider.value);

        api(`/ajax/products/${productId}`, {
            method: "PATCH",
            data: {workload_points: newValue}
        })
            .then(data => {
                if (data.ok) {
                    const valueEl = document.querySelector(`.workload-value[data-product-id="${productId}"]`);
                    if (valueEl) valueEl.textContent = String(newValue);
                    const editBtn = document.querySelector(`.workload-edit-btn[data-product-id="${productId}"]`);
                    if (editBtn) editBtn.setAttribute('data-workload-points', String(newValue));

                    showSnack(true);
                    workloadModal.hide();
                } else {
                    showSnack(false);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showSnack(false);
            });
    });
</script>

