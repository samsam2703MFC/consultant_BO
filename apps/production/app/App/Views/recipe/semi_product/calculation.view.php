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
                <h4 class="card-title">Kalkulacja ceny półproduktu</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6">
                        <table class="table">
                            <thead>
                            <th></th>
                            <th></th>
                            </thead>
                            <tbody>
                            <tr>
                                <td>Nazwa półproduktu</td>
                                <td><?=$recipe_calculation_data['name']?></td>
                            </tr>
                            <tr>
                                <td>Ilość</td>
                                <td><?=$recipe_data['yield_quantity']?> <?=$recipe_data['recipe_unit']?></td>
                            </tr>
                            <tr>
                                <td>Koszt surowców</td>
                                <td><?=$recipe_data['total_ingredient_cost']?> zł</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-6">
                        <?php show(recipeList2html($recipe_calculation_data['elements'])) ?>
                    </div>
                </div>

            </div>
            <div class="row mt-4">
                <div class="col-2"></div>
                <div class="col-8">
                    <a href="<?=ROOT?>/semi-products" role="button" class="btn btn-secondary float-end">Powrót</a>
                </div>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>

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

    function addComposition(btn){
        const composition_table_body = document.getElementById('id_composition_table_body');

        const composition_select = btn.parentNode.parentNode.querySelectorAll("select")[0];
        const quantity_input = btn.parentNode.parentNode.querySelectorAll("input")[0];
        if(composition_select.value === "-1") return;

        if(!isSelectedControlExist(composition_select.value, "composition_data", "id_composition_table_body")){
            const row = composition_table_body.insertRow(composition_table_body.length);
            row.insertCell(0).innerHTML = `${composition_select.options[composition_select.selectedIndex].text}
                                                <input type="hidden" name="composition_data[${composition_select.value}][id]" value="${composition_select.value}">
                                                <input type="hidden" name="composition_data[${composition_select.value}][name]" value="${composition_select.options[composition_select.selectedIndex].text}">`
            row.insertCell(1).innerHTML = `<input type="number" class="form-control" name="composition_data[${composition_select.value}][quantity]" value="${quantity_input.value}">`
            row.insertCell(2).innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;
        } else {
            alert("Wybrana kompozycja już istnieje w tabeli");
        }



        resetAllControlInElem("id_add_composition_data_row");
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

