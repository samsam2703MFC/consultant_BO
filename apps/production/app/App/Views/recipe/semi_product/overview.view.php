<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Półprodukt</h3>
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
                <h4 class="card-title">Lista utworzonych receptur półproduktu <a href="<?=ROOT?>/semi-products/new" class="btn btn-primary" role="button">Dodaj nową</a></h4>
            </div>
            <div class="card-body">
                <div class="row mt-4">
                    <div class="col-4">
                        <input class="form-control" type="text" placeholder="Wyszukaj po nazwie..." onkeyup="searchInTable(this.value)">
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 50%">Nazwa</th>
                            <th style="width: 20%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 10%"></th>
                            </thead>
                            <tbody id="id_recipe_table">
                            <?php if(!empty($semi_final_recipes)): ?>
                                <?php foreach($semi_final_recipes as $semi_final_recipe): ?>
                                    <tr>
                                        <td><?= $semi_final_recipe['name'] ?></td>
                                        <td><?= $semi_final_recipe['precalculated_material_cost'] ?> zł / <?= $semi_final_recipe['yield_quantity'] ?> <?=$semi_final_recipe['unit_name']?></td>
                                        <td class="text-center">
                                            <button class="btn btn-secondary"
                                                    onclick="openCompetencyModal(<?=$semi_final_recipe['id']?>, <?=htmlspecialchars(json_encode($semi_final_recipe['name']))?>)"
                                                    title="Kompetencje">
                                                <i class="bi bi-award"></i>
                                            </button>
                                        </td>
                                        <td class="text-center"><a href="<?=ROOT?>/semi-products/<?=$semi_final_recipe['id']?>/calculation" class="btn btn-info">Kalkulacja</a></td>
                                        <td class="text-center"><a href="<?=ROOT?>/semi-products/<?=$semi_final_recipe['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                        <td class="text-center"><a href="<?=ROOT?>/semi-products/<?=$semi_final_recipe['id']?>/delete" class="btn btn-danger">Usuń</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<!-- ====== MODAL: Kompetencje półproduktu ====== -->
<div class="modal fade" id="competencyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-award me-2 text-secondary"></i>
                    Kompetencje półproduktu: <span id="competencyModalRecipeName" class="fw-bold"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Zaznacz kompetencje wymagane do wykonania tego półproduktu.</p>
                <div id="competencyModalSpinner" class="text-center py-4">
                    <div class="spinner-border text-secondary"></div>
                </div>
                <div id="competencyModalList" class="row g-2 d-none"></div>
                <div id="competencyModalEmpty" class="text-center text-muted py-3 d-none">
                    Brak zdefiniowanych kompetencji. <a href="<?=ROOT?>/hr/recipe-competencies">Dodaj kompetencje →</a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="saveCompetencies()">
                    <span id="competencySaveBtnText">Zapisz</span>
                    <span id="competencySaveBtnSpinner" class="spinner-border spinner-border-sm d-none ms-1"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const ALL_COMPETENCIES = <?= json_encode($competencies ?? []) ?>;
    const ROOT = window.API_BASE_URL;
    let _currentRecipeId = null;

    async function openCompetencyModal(recipeId, recipeName) {
        _currentRecipeId = recipeId;
        document.getElementById('competencyModalRecipeName').textContent = recipeName;

        const list    = document.getElementById('competencyModalList');
        const spinner = document.getElementById('competencyModalSpinner');
        const empty   = document.getElementById('competencyModalEmpty');

        list.classList.add('d-none');
        empty.classList.add('d-none');
        spinner.classList.remove('d-none');

        new bootstrap.Modal(document.getElementById('competencyModal')).show();

        let assignedIds = [];
        try {
            const res  = await fetch(`${ROOT}/ajax/recipes/${recipeId}/competencies`);
            const data = await res.json();
            assignedIds = data.assigned_ids ?? [];
        } catch(e) { console.error(e); }

        spinner.classList.add('d-none');

        if (!ALL_COMPETENCIES.length) {
            empty.classList.remove('d-none');
            return;
        }

        list.innerHTML = ALL_COMPETENCIES.map(c => `
            <div class="col-12 col-md-6">
                <div class="form-check border rounded p-3 h-100 ${assignedIds.includes(c.id) ? 'border-primary bg-primary bg-opacity-10' : ''}">
                    <input class="form-check-input comp-checkbox" type="checkbox"
                           id="comp_${c.id}" value="${c.id}"
                           ${assignedIds.includes(c.id) ? 'checked' : ''}
                           onchange="toggleCardHighlight(this)">
                    <label class="form-check-label w-100" for="comp_${c.id}">
                        <span class="fw-semibold">${escHtmlJS(c.name)}</span>
                        ${c.description ? `<br><small class="text-muted">${escHtmlJS(c.description)}</small>` : ''}
                    </label>
                </div>
            </div>`).join('');

        list.classList.remove('d-none');
    }

    function toggleCardHighlight(cb) {
        const card = cb.closest('.form-check');
        card.classList.toggle('border-primary', cb.checked);
        card.classList.toggle('bg-primary',     cb.checked);
        card.classList.toggle('bg-opacity-10',  cb.checked);
    }

    async function saveCompetencies() {
        const ids = [...document.querySelectorAll('.comp-checkbox:checked')].map(c => parseInt(c.value));

        document.getElementById('competencySaveBtnText').classList.add('d-none');
        document.getElementById('competencySaveBtnSpinner').classList.remove('d-none');

        try {
            const res = await fetch(`${ROOT}/ajax/recipes/${_currentRecipeId}/competencies/sync`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({competency_ids: ids})
            });
            const data = await res.json();
            if (data.ok) {
                bootstrap.Modal.getInstance(document.getElementById('competencyModal')).hide();
            } else {
                alert('Błąd zapisu kompetencji.');
            }
        } finally {
            document.getElementById('competencySaveBtnText').classList.remove('d-none');
            document.getElementById('competencySaveBtnSpinner').classList.add('d-none');
        }
    }

    function escHtmlJS(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function searchInTable(input_value) {
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_recipe_table");
        tr = table.getElementsByTagName("tr");
        for (i = 0; i < tr.length; i++) {
            td = tr[i].getElementsByTagName("td")[0];
            if (td) {
                txtValue = td.textContent || td.innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }
</script>

