<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Receptura produktu gotowego</h3>
                <p class="text-subtitle text-muted">
                    Zarządzaj recepturami produktów gotowych
                </p>
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
                <h4 class="card-title">Lista utworzonych receptur dla produktu gotowego <a href="<?=ROOT?>/recipes/new" class="btn btn-primary" role="button">Dodaj nową</a></h4>
            </div>
            <div class="card-body">
                <div class="row mt-4">
                    <div class="col-4">
                        <input class="form-control" type="text" placeholder="Wyszukaj po nazwie..." onkeyup="searchInTable(this.value)">
                    </div>
                    <div class="col-4"></div>
                    <div class="col-4">
                        <button class="btn btn-outline-primary btn-sm float-end" onclick="getCostCsv(this)">
                            Pobierz listę receptur z kosztami wytworzenia (PDF)
                        </button>
                        <button class="btn btn-outline-warning btn-sm float-end me-2" onclick="getProductsWithoutRecipePdf(this)">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Produkty bez receptury (PDF)
                        </button>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 50%">Nazwa</th>
                            <th style="width: 20%"></th>
                            <th style="width: 30%"></th>
                            </thead>
                            <tbody id="id_recipe_table">
                            <?php if(!empty($final_recipes)): ?>
                                <?php foreach($final_recipes as $final_recipe): ?>
                                    <tr>
                                        <td><?= $final_recipe['name'] ?></td>
                                        <td><?= $final_recipe['precalculated_material_cost'] ?> zł / <?= $final_recipe['yield_quantity'] ?> <?=$final_recipe['unit_name']?></td>
                                        <td>
                                            <div class="btn-group-sm">
                                                <a href="<?=ROOT?>/recipes/<?=$final_recipe['id']?>/preparation-steps" class="btn btn-primary"><?=$translations['instruction']?></a>
                                                <a href="<?=ROOT?>/recipes/<?=$final_recipe['id']?>/calculation" class="btn btn-info">Kalkulacja</a>
                                                <button class="btn btn-secondary"
                                                        onclick="openCompetencyModal(<?=$final_recipe['id']?>, <?=htmlspecialchars(json_encode($final_recipe['name']))?>)"
                                                        title="Kompetencje">
                                                    <i class="bi bi-award"></i>
                                                </button>
                                                <a href="<?=ROOT?>/recipes/<?=$final_recipe['id']?>/edit" class="btn btn-warning">Edytuj</a>
                                                <a href="<?=ROOT?>/recipes/<?=$final_recipe['id']?>/delete" class="btn btn-danger">Usuń</a>
                                            </div>
                                        </td>
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

<!-- ====== MODAL: Kompetencje receptury ====== -->
<div class="modal fade" id="competencyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-award me-2 text-secondary"></i>
                    Kompetencje receptury: <span id="competencyModalRecipeName" class="fw-bold"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Zaznacz kompetencje wymagane do wykonania tej receptury.</p>
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
                <button type="button" class="btn btn-primary" id="competencySaveBtn" onclick="saveCompetencies()">
                    <span id="competencySaveBtnText">Zapisz</span>
                    <span id="competencySaveBtnSpinner" class="spinner-border spinner-border-sm d-none ms-1"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // ── dane przekazane z PHP ──────────────────────────────
    const ALL_COMPETENCIES = <?= json_encode($competencies ?? []) ?>;
    const ROOT = window.API_BASE_URL;

    let _currentRecipeId = null;

    // ── otwarcie modala ────────────────────────────────────
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

        // pobierz przypisane ID
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

    // ── zapis ──────────────────────────────────────────────
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
</script>

<script>
    function getCostCsv(btn){

        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generowanie pliku...`;

        downloadCSV(`/ajax/recipes/final-recipes/costs/csv`, 'receptury_produktow_gotowych.pdf')
            .then(function(){
                btn.disabled = false;
                btn.innerHTML = `Pobierz listę receptur z kosztami wytworzenia (PDF)`;
            })
    }

    function getProductsWithoutRecipePdf(btn) {
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generowanie pliku...`;

        downloadPDF(`/ajax/recipes/products-without-recipe/pdf`, {}, 'produkty_bez_receptury.pdf')
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    }

    function searchInTable(input_value){
        // Declare variables
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_recipe_table");
        tr = table.getElementsByTagName("tr");

        // Loop through all table rows, and hide those who don't match the search query
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

    function isSelectedControlExist(id_ingredient, control_name, table_body_id){
        const elems = document.getElementById(table_body_id).querySelectorAll(`input[name="${control_name}[${id_ingredient}][name]"]`)
        return elems.length > 0;
    }

    function addMaterial(btn){
        const material_table_body = document.getElementById('id_material_table_body');

        const material_select = btn.parentNode.parentNode.querySelectorAll("select")[0];
        const quantity_input = btn.parentNode.parentNode.querySelectorAll("input")[0];

        if(material_select.value === "-1") return;

        if(!isSelectedControlExist(material_select.value, "material_data", "id_material_table_body")){
            const row = material_table_body.insertRow(material_table_body.length);
            row.insertCell(0).innerHTML = `${material_select.options[material_select.selectedIndex].text}
                                                <input type="hidden" name="material_data[${material_select.value}][id]" value="${material_select.value}">
                                                <input type="hidden" name="material_data[${material_select.value}][name]" value="${material_select.options[material_select.selectedIndex].text}">`
            row.insertCell(1).innerHTML = `<input type="number" class="form-control" name="material_data[${material_select.value}][quantity]" value="${quantity_input.value}">`
            row.insertCell(2).innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;
        } else {
            alert("Wybrany surowiec już istnieje w tabeli");
        }

        resetAllControlInElem("id_add_material_data_row");
    }

    function addSFP(){
        const sfp_table_body = document.getElementById('id_sfp_table_body');
        const sfp_name_sel = document.getElementById('id_sfp_name');
        const quantity_of_sfp_in_recipe_inp = document.getElementById('id_quantity_of_sfp_in_recipe');

        if(sfp_name_sel.value === "-1") return;

        if(!isSelectedControlExist(sfp_name_sel.value, "sfp_data", "id_sfp_table_body")){
            const row = sfp_table_body.insertRow(sfp_table_body.length);
            row.insertCell(0).innerHTML = `${sfp_name_sel.options[sfp_name_sel.selectedIndex].text}`
            row.insertCell(1).innerHTML = `<input type="number" class="form-control" name="sfp_data[${sfp_name_sel.value}][quantity]" value="${quantity_of_sfp_in_recipe_inp.value}">
                                                <input type="hidden" name="sfp_data[${sfp_name_sel.value}][name]" value="${sfp_name_sel.options[sfp_name_sel.selectedIndex].text}">
                                                <input type="hidden" name="sfp_data[${sfp_name_sel.value}][id]" value="${sfp_name_sel.value}">`
            row.insertCell(2).innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;
        } else {
            alert("Wybrana receptura już istnieje w tabeli");
        }

        resetAllControlInElem("id_add_sfp_data_row");
    }

    function addPreparation(){
        const preparation_table_body = document.getElementById('id_preparation_table_body');
        const id_preparation_sel = document.getElementById('id_preparation_name');
        const id_preparation_inp = document.getElementById('id_preparation_time');

        if(id_preparation_sel.value === "-1") return;

        if(!isSelectedControlExist(id_preparation_sel.value, "preparation_type", "id_preparation_table_body")){
            const row = preparation_table_body.insertRow(preparation_table_body.length);
            row.insertCell(0).innerHTML = `<input type="text" class="form-control" name="preparation_type[${id_preparation_sel.value}][name]" value="${id_preparation_sel.options[id_preparation_sel.selectedIndex].text}">`
            row.insertCell(1).innerHTML = `<input type="number" class="form-control" name="preparation_type[${id_preparation_sel.value}][duration_second]" value="${id_preparation_inp.value}">
                                                <input type="hidden" name="preparation_type[${id_preparation_sel.value}][id_preparation]" value="${id_preparation_sel.value}">`
            row.insertCell(2).innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;
        } else {
            alert("Wybrana czynność już istnieje w tabeli");
        }
        resetAllControlInElem("id_add_preparation_data_row");
    }

    function resetAllControlInElem(id_elem){
        const elem = document.getElementById(id_elem);
        const inputs = elem.getElementsByTagName("input");
        const selects = elem.getElementsByTagName("select");

        for(let i=0; i<inputs.length; i++){
            inputs[i].value = "";
        }

        for(let i=0; i<selects.length; i++){
            selects[i].selectedIndex = 0;
        }
    }

    function setUnit(value, id_control){
        document.getElementById(id_control).value = value;
        document.getElementById(id_control).value = value;
    }

    function calcTotalWeight(){
        const material_unit_inp = document.getElementById("id_material_unit");
        const material_unit_weight_inp = document.getElementById("id_material_unit_weight");
        const total_weight_of_material_in_recipe_inp = document.getElementById("id_total_weight_of_material_in_recipe");
        const quantity_of_material_in_recipe_inp = document.getElementById("id_quantity_of_material_in_recipe");

        const id_unit = material_unit_inp.getAttribute("data-id-unit");

        if(id_unit === "5"){
            total_weight_of_material_in_recipe_inp.value = (material_unit_weight_inp.value * quantity_of_material_in_recipe_inp.value)
        } else {
            total_weight_of_material_in_recipe_inp.value = quantity_of_material_in_recipe_inp.value;
        }
    }

    function calcTotalWeightInserted(control){
        const row = control.parentNode.parentNode;
        const id_unit = row.querySelector("input[name$='[id_unit]'").value;
        const total_weight_control = row.querySelector("input[name$='[total_weight_in_recipe]'");

        if(id_unit === "5"){
            total_weight_control.value = (row.children[1].firstChild.value * row.children[3].firstChild.value)
        } else {
            total_weight_control.value = row.children[3].firstChild.value;
        }

        // const material_unit_inp = document.getElementById("id_material_unit");
        // const material_unit_weight_inp = document.getElementById("id_material_unit_weight");
        // const total_weight_of_material_in_recipe_inp = document.getElementById("id_total_weight_of_material_in_recipe");
        // const quantity_of_material_in_recipe_inp = document.getElementById("id_quantity_of_material_in_recipe");
        //
        // const id_unit = material_unit_inp.getAttribute("data-id-unit");
        //
        // if(id_unit === "5"){
        //     total_weight_of_material_in_recipe_inp.value = (material_unit_weight_inp.value * quantity_of_material_in_recipe_inp.value)
        // } else {
        //     total_weight_of_material_in_recipe_inp.value = quantity_of_material_in_recipe_inp.value;
        // }
    }

    function getRawMaterialDataById(id_raw_material){
        const data = {
            id : id_raw_material
        }
        const promise = getJSON(validAjaxUrl("RawMaterial") + `/ajax/material/get`, "data="+JSON.stringify(data));
        promise.then(function(res){
            const material_unit_control = document.getElementById("id_material_unit");
            material_unit_control.value = res.unit_name;
            material_unit_control.setAttribute("data-id-unit", res.id_unit);

            const material_unit_weight_control = document.getElementById("id_material_unit_weight");
            material_unit_weight_control.value = res.single_weight;

            material_unit_weight_control.readOnly = res.id_unit !== 5;

            const weight_of_material_in_recipe_control = document.getElementById('id_quantity_of_material_in_recipe');
            weight_of_material_in_recipe_control.readOnly = false;
            weight_of_material_in_recipe_control.value = 0;
            calcTotalWeight()
        })
    }





    function addPreparationStepToTable(btn, input_name){
        const row = btn.parentNode.parentNode;
        const textarea = row.querySelectorAll("textarea")[0];
        const target_body = row.parentNode.parentNode.querySelectorAll("tbody")[0];

        let insertedRow = target_body.insertRow(target_body.rows.length);
        const cell0 = insertedRow.insertCell(0);
        const cell1 = insertedRow.insertCell(1);
        const cell2 = insertedRow.insertCell(2);

        cell0.innerHTML = target_body.rows.length;
        cell1.innerHTML = `<textarea class="form-control" name="${input_name}[${target_body.rows.length}]">${textarea.value}</textarea>`
        cell2.innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;

        textarea.value = "";
    }

    function removeRow(btn){
        const row = btn.parentNode.parentNode;
        row.parentNode.removeChild(row);
    }

</script>

