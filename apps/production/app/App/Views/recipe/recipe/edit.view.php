<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Produkt gotowy</h3>
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
                <h4 class="card-title">Edytuj recepturę produktu</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <?php if(!empty($errors)):?>
                            <div class="alert alert-danger text-center"><?=implode("<br>", $errors)?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="POST">
                    <div class="row">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <h3><?=$product_data['name']?></h3>
                        </div>
                    </div>

                    <div class="divider mt-5 mb-5">
                        <div class="divider-text">
                            <h3>Składniki</h3>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12 border border-info rounded pt-4 px-4">
                            <div class="row">
                                <div class="col-12 text-center">
                                    <h4>Surowce</h4>
                                </div>
                                <div class="col-12">
                                    <table class="table">
                                        <thead>
                                        <th style="width: 30%">Nazwa</th>
                                        <th style="width: 10%">Ilość</th>
                                        <th style="width: 10%"></th>
                                        </thead>
                                        <tbody id="id_material_table_body">
                                        <?php if(!empty($old_data['material_data'])): ?>
                                            <?php foreach($old_data['material_data'] as $old_item): ?>
                                                <tr>
                                                    <td>
                                                        <?=$old_item['name']?>
                                                        <input type="hidden" value="<?=$old_item['id']?>" name="material_data[<?=$old_item['id']?>][id]">
                                                        <input type="hidden" value="<?=$old_item['name']?>" name="material_data[<?=$old_item['id']?>][name]">
                                                    </td>
                                                    <td><input type="number" class="form-control" name="material_data[<?=$old_item['id']?>][quantity]" value="<?=$old_item['quantity']?>"></td>

                                                    <td><button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button></td>
                                                </tr>
                                            <?php endforeach;; ?>
                                        <?php endif; ?>
                                        </tbody>
                                        <tbody>
                                        <tr class="bg-light-info" id="id_add_material_data_row">
                                            <td>
                                                <select class="choices form-select">
                                                    <option value="-1">Wybierz...</option>
                                                    <?php if(!empty($materials)): ?>
                                                        <?php foreach($materials as $material): ?>
                                                            <option value="<?=$material['id']?>"><?=$material['name']?> - [<?=$material['unit_name']?>]</option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" step="any" class="form-control">
                                            </td>
                                            <td>
                                                <button class="btn btn-success float-end" type="button" onclick="addMaterial(this)">Dodaj</button>
                                            </td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 border border-info rounded pt-4 px-4 mt-5">
                            <div class="row">
                                <div class="col-12 text-center">
                                    <h4>Półprodukty</h4>
                                </div>
                                <div class="col-12">
                                    <table class="table">
                                        <thead>
                                        <th style="width: 30%">Nazwa</th>
                                        <th style="width: 10%">Ilość [szt]</th>
                                        <th style="width: 10%"></th>
                                        </thead>
                                        <tbody id="id_sfp_table_body">
                                        <?php if(!empty($old_data['sfp_data'])): ?>
                                            <?php foreach ($old_data['sfp_data'] as $item): ?>
                                                <tr>
                                                    <td><?=$item['name']?></td>
                                                    <td><input type="number" class="form-control" name="sfp_data[<?=$item['id']?>][quantity]" value="<?=$item['quantity']?>">
                                                        <input type="hidden" name="sfp_data[<?=$item['id']?>][name]" value="<?=$item['name']?>">
                                                        <input type="hidden" name="sfp_data[<?=$item['id']?>][id]" value="<?=$item['id']?>">
                                                    </td>
                                                    <td><button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button></td>
                                                </tr>

                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                        <tbody>
                                        <tr class="bg-light-info" id="id_add_sfp_data_row">
                                            <td>
                                                <select class="form-select" id="id_sfp_name">
                                                    <option value="-1">Wybierz...</option>
                                                    <?php if(!empty($sfp)): ?>
                                                        <?php foreach($sfp as $semiFinalProduct): ?>
                                                            <option value="<?=$semiFinalProduct['id']?>"><?=$semiFinalProduct['name']?> [<?=$semiFinalProduct['unit_name']?>]</option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" step="any" id="id_quantity_of_sfp_in_recipe" class="form-control">
                                            </td>
                                            <td>
                                                <button class="btn btn-success float-end" type="button" onclick="addSFP(this)">Dodaj</button>
                                            </td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="divider mt-5 mb-5">
                        <div class="divider-text">
                            <h3>Przygotowanie</h3>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <table class="table">
                                <thead>
                                <th>Nazwa czynności</th>
                                <th>Czas [sekundy]</th>
                                <th></th>
                                </thead>
                                <tbody id="id_preparation_table_body">
                                <?php if(!empty($old_data['preparation_type'])): ?>
                                    <?php foreach ($old_data['preparation_type'] as $item): ?>
                                        <tr>
                                            <td><input type="text" class="form-control" name="preparation_type[<?=$item['id_preparation']?>][name]" value="<?=$item['name']?>"></td>
                                            <td>
                                                <input type="number" class="form-control" name="preparation_type[<?=$item['id_preparation']?>][duration_second]" value="<?=$item['duration_second']?>">
                                                <input type="hidden" name="preparation_type[<?=$item['id_preparation']?>][id_preparation]" value="<?=$item['id_preparation']?>">
                                            </td>
                                            <td><button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                                <tbody>
                                <tr class="bg-light-info" id="id_add_preparation_data_row">
                                    <td>
                                        <select class="form-select" id="id_preparation_name">
                                            <option value="-1">Wybierz...</option>
                                            <?php if(!empty($preparation_types)): ?>
                                                <?php foreach($preparation_types as $preparation_type): ?>
                                                    <option value="<?=$preparation_type['id']?>"><?=$preparation_type['name']?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" placeholder="0" id="id_preparation_time" class="form-control"></td>
                                    <td><button class="btn btn-success" type="button" onclick="addPreparation()">Dodaj</button></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <button class="btn btn-warning">Edytuj</button>
                            <a href="<?=ROOT?>/recipes" role="button" class="btn btn-secondary float-end">Powrót</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>

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
        const quantity_input = btn.parentNode.parentNode.querySelectorAll('input[type="number"]')[0];

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