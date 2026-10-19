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
                    <h4 class="card-title">Marża gotowych produktów</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-3"></div>
                        <div class="col-3">
                            <label class="form-label" for="id_category">
                                Kategoria
                            </label>
                            <select class="form-select" name="id_category" id="id_category" onchange="showProductMarge()">
                                <option selected value="-1">Wybierz</option>
                                <?php if(!empty($categories)): ?>
                                    <?php foreach($categories as $category): ?>
                                        <option value="<?=$category->id_category?>"><?=$category->name?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label" for="id_customer">
                                Klient
                            </label>
                            <select class="form-select" name="id_customer" id="id_customer" onchange="showProductMarge()">
                                <option selected value="-1">Ogólne</option>
                                <?php if(!empty($customers)): ?>
                                    <?php foreach($customers as $customer): ?>
                                        <option value="<?=$customer->id?>"><?=$customer->company?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-2">
                            <label class="form-label" for="exchange_rate">
                                Kurs euro
                            </label>
                            <input class="form-control" type="number" step="0.01" name="exchange_rate_euro" id="exchange_rate_euro" onblur="updateExchangeRate(this)" value="<?= $exchange_rate_euro->exchange_rate_euro ?>">
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-12">
                            <table class="table table-hover">
                                <thead>
                                <th>Produkt</th>
                                <th>Dział</th>
                                <th>Surowiec</th>
                                <th>Opak.</th>
                                <th>Koszt</th>
                                <th>Praca</th>
                                <th>Łącznie</th>
                                <th style="width: 8%">Koszt pal [zł]</th>
                                <th>cpp</th>
                                <th>KPC</th>
                                <th>Sum</th>
                                <th style="width: 8%">Marża [%]</th>
                                <th>Oczekiw</th>
                                <th>Szt/kart</th>
                                <th>Cena/szt</th>
                                <th style="width: 8%">Cena/kart [€]</th>
                                <th>Cena/szt</th>
                                </thead>
                                <tbody class="text-sm" id="id_marge_body_table">

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
<?php

//TODO: Dodać palette_cost w tworzeniu i edycji produktu
?>

<?php $this->view("page_components/footer"); ?>

<script>

    function showProductMarge(){
        const id_category = document.getElementById('id_category').value
        const id_customer = document.getElementById('id_customer').value

        let table = document.getElementById('id_marge_body_table');
        table.innerHTML = "";
        const data = {
            id_category : id_category,
            id_customer : id_customer
        };

        const promise = getJSON(validAjaxUrl()+`ajax/marge/get`, "data="+JSON.stringify(data));
        promise.then(function (res){

            for(let i=0; i<res.length; i++){
                let row = table.insertRow(table.length);

                let cell0 = row.insertCell(0);
                    cell0.innerHTML = res[i].name_product;
                let cell1 = row.insertCell(1);
                    cell1.innerHTML = res[i].name_section;

                let cell2 = row.insertCell(2);
                    cell2.innerHTML = `${res[i].material_price} zł`;
                    cell2.style.backgroundColor = "#f8d7da";
                let cell3 = row.insertCell(3);
                    cell3.innerHTML = `${res[i].pack_price} zł`;
                    cell3.style.backgroundColor = "#f8d7da";

                const material_price = (Number(res[i].material_price) + Number(res[i].pack_price)).toFixed(2);
                let cell4 = row.insertCell(4);
                    cell4.innerHTML = `${material_price} zł`;

                let cell5 = row.insertCell(5);
                    cell5.innerHTML = `${res[i].preparation_price} zł`;
                    cell5.style.backgroundColor = "#f8d7da";

                const total_material_preparation_price = (Number(material_price) + Number(res[i].preparation_price)).toFixed(2);
                let cell6 = row.insertCell(6);
                    cell6.innerHTML = `${total_material_preparation_price} zł`;

                let cell7 = row.insertCell(7);
                    cell7.innerHTML = `<input type="number" class="form-control" onblur="update(this, ${res[i].id_product}, ${id_category})" name="palette_cost" value="${res[i].palette_cost}">`;
                    cell7.style.backgroundColor = "#fff3cd";
                let cell8 = row.insertCell(8);
                    cell8.innerHTML = res[i].carton_per_palette;
                    cell8.style.backgroundColor = "#fff3cd";

                const kpc = (Number(res[i].palette_cost) / Number(res[i].carton_per_palette)).toFixed(2);
                let cell9 = row.insertCell(9);
                cell9.innerHTML = `${kpc} zł`;
                cell9.style.backgroundColor = "#fff3cd";

                const totalPricePalette = (Number(kpc) + Number(total_material_preparation_price));
                let cell10 = row.insertCell(10);
                cell10.innerHTML = `${totalPricePalette} zł`;

                let cell11 = row.insertCell(11);
                cell11.innerHTML = `<input type="number" class="form-control" onblur="update(this, ${res[i].id_product}, ${id_category})" name="marge_perc" value="${res[i].marge_perc}">`;
                cell11.style.backgroundColor = "#cce5ff";

                const priceWithMarge = ((totalPricePalette*(1+(res[i].marge_perc/100)))/document.getElementById('exchange_rate_euro').value).toFixed(2);
                let cell12 = row.insertCell(12);
                cell12.innerHTML = `${priceWithMarge} €`;
                cell12.style.backgroundColor = "#cce5ff";

                let cell13 = row.insertCell(13);
                cell13.innerHTML = res[i].ppc;
                cell13.style.backgroundColor = "#cce5ff";

                const pricePerPcs = (priceWithMarge/res[i].ppc).toFixed(2);
                let cell14 = row.insertCell(14);
                cell14.innerHTML = `${pricePerPcs} €`;
                cell14.style.backgroundColor = "#cce5ff";

                let cell15 = row.insertCell(15);
                cell15.innerHTML = `<input type="number" class="form-control" onblur="updatePrice(this, ${res[i].id_product}, ${id_customer})" value="${res[i].price}" name="standard_price">`;
                cell15.style.backgroundColor = "#d4edda";

                const currPricePerPcs = (res[i].price / res[i].ppc).toFixed(2);
                let cell16 = row.insertCell(16);
                cell16.innerHTML = `${currPricePerPcs} €`;
                cell16.style.backgroundColor = "#d4edda";
            }
        })
    }

    function update(control, id_final_product, id_category){
        const data = {
            id : id_final_product
        }
        data[control.name] = control.value;

        const promise = getJSON(validAjaxUrl()+`ajax/final_product/update`, "data="+JSON.stringify(data));
        promise.then(function (res){
            showProductMarge(id_category);
        })
    }

    function updatePrice(control, id_final_product, id_category){
        const id_customer = document.getElementById('id_customer').value

        const data = {
            id_customer : id_customer,
            price : control.value,
            param : control.name,
            id : id_final_product
        }


        const promise = getJSON(validAjaxUrl()+`ajax/final_product_price/update`, "data="+JSON.stringify(data));
        promise.then(function (res){
            showProductMarge(id_category);
        })
    }

    function updateExchangeRate(control){

        const data = {
            exchange_rate_euro : control.value
        }

        const promise = getJSON(validAjaxUrl("ErpCompanySettings")+`ajax/settings/update`, "data="+JSON.stringify(data));
        promise.then(function (res){
            showProductMarge();
        })
    }

</script>
