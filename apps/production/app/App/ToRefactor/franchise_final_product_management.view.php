<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Produkty w sieci franczyzowej</h3>
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

            <?php if($action == 'manage'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Lista dostępnych produktów</h4>
                    </div>
                    <div class="card-body">

                        <div class="row">
                            <div class="col-2"></div>
                            <div class="col-8 text-center">
                                <h1><?=$franchisee->company?></h1>
                                <input type="hidden" id="id_franchisee" value="<?=$franchisee->id?>">
                            </div>
                        </div>

                        <div class="row mt-5">
                            <div class="col-2"></div>
                            <div class="col-2">
                                <input type="text" class="form-control" onkeyup="searchInTable(this.value)" placeholder="Wyszukaj po nazwie">
                            </div>
                            <div class="col-2">
                                <select class="form-select" onchange="filter(this.value)">
                                    <option value="2">Wszystkie</option>
                                    <option value="1">Dostępne</option>
                                    <option value="0">Niedostępne</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <?php if(!empty($errors)):?>
                                    <div class="alert alert-danger text-center"><?=implode("<br>", $errors)?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <table class="table table-hover">
                                    <thead>
                                        <th>Produkt</th>
                                        <th>Dostępny</th>
                                        <th>Cena [€]</th>
                                        <th></th>
                                    </thead>
                                    <tbody id="id_product_table_body">

                                    <?php if(!empty($products)): ?>
                                        <?php foreach($products as $product): ?>
                                            <tr>
                                                <?php
                                                $checked = ($product->available == "1") ? "checked" : "";
                                                ?>
                                                <td><?= $product->name?></td>
                                                <td class="form-switch text-center"><input class="form-check-input" type="checkbox" onchange="updateAvaiability(<?=$product->id?>, this);" value="<?=$product->available?>" <?=$checked?>></td>
                                                <td><input type="number" class="form-control" step="0.01" min="0" value="<?= $product->price?>" disabled></td>
                                                <td>
                                                    <button class="btn btn-warning" type="button" onclick="enableEdit(this)">Edytuj</button>
                                                    <button class="btn btn-success" type="button" style="display: none" onclick="saveNewPrice(<?= $product->id ?>, this)">Zapisz</button>
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

            <?php else:?>
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Lista franczyzobiorców</h4>
                    </div>
                    <div class="card-body">
                        <table class="table table-striped">
                            <thead>
                            <th style="width: 20%;">Firma</th>
                            <th style="width: 15%;">Imię</th>
                            <th style="width: 20%;">Nazwisko</th>
                            <th style="width: 20%;">Email</th>
                            <th style="width: 10%;" class="text-center">Lista cenowa [PDF]</th>
<!--                            <th style="width: 15%;"></th>-->
                            </thead>
                            <tbody>
                            <?php if(!empty($rows)): ?>
                                <?php foreach($rows as $row): ?>
                                    <tr>
                                        <td><?= $row->company?></td>
                                        <td><?= $row->name?></td>
                                        <td><?= $row->surname?></td>
                                        <td><?= $row->email_head?></td>
                                        <td class="text-center"><button type="button" class="btn btn-info" onclick="checkAndRun(<?=$row->id?>, '<?=ROOT?>/Document/price_list/franchisee/<?=$row->id?>')">PDF</button></td>
<!--                                        <td class="text-center"><a href="--><?//=ROOT?><!--/FinalProduct/franchisee/manage/--><?//=$row->id?><!--" class="btn btn-warning btn-sm">Cena i dostepność produktów</a></td>-->
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

<script>

    function enableEdit(btn){
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let saveBtn = btn.parentNode.children[1];
        btn.style.display = "none";
        saveBtn.style.display = "inline-block";
        previousColInput.disabled = false;
        previousColInput.focus();
        previousColInput.value = "";
    }

    function disableUpdate(btn){
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let enableBtn = btn.parentNode.children[0];
        btn.style.display = "none";
        enableBtn.style.display = "inline-block";
        previousColInput.disabled = true;
    }

    function updateAvaiability(id_product, chckbox){
        const id_franchisee = document.getElementById("id_franchisee").value;
        let nextColInput = chckbox.parentNode.nextSibling.nextSibling.children[0];

        const data = {
            "id_final_product": id_product,
            "id_customer": id_franchisee,
            "price": nextColInput.value,
            "available": Number(chckbox.checked)
        }
        console.log(data);

        const promise = getJSON("../../ajax/franchisee/update", "data=" + JSON.stringify(data));
        promise.then(function (res) {
            showSnack(res);
        })
    }

    function saveNewPrice(id_product, btn) {
        const input = btn.parentNode.previousSibling.previousSibling.children[0];
        const chckbox = btn.parentNode.previousSibling.previousSibling.previousSibling.previousSibling.children[0];
        const id_franchisee = document.getElementById("id_franchisee").value;

        const newPrice = input.value;

        if(newPrice == "0" || newPrice == ""){
            input.value = "0.00";
            disableUpdate(btn);
            return;
        }

        const data = {
            "id_final_product": id_product,
            "id_customer": id_franchisee,
            "price": newPrice,
            "available": Number(chckbox.checked)
        }

        console.log(data);

        const promise = getJSON("../../ajax/franchisee/update", "data=" + JSON.stringify(data));
        promise.then(function (res) {
            showSnack(res);
            if (res) {
                disableUpdate(btn);
            }
        })
    }

    function searchInTable(input_value){
        // Declare variables
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_product_table_body");
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

    function filter(type){
        const table = document.getElementById("id_product_table_body");
        const tr = table.getElementsByTagName("tr");

        for (i = 0; i < tr.length; i++) {
            let td = tr[i].getElementsByTagName("td")[1];
            let elem = td.children[0];

            if (td) {
                if(type == 2 ){
                    tr[i].style.display = "";
                }else{
                    if (Number(elem.checked) == type) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }

            }
        }
    }

    function checkAndRun(id_franchisee, path){
        const data = {
            "id_customer": id_franchisee
        }

        console.log(data);

        const promise = getJSON("ajax/franchisee/price_list", "data=" + JSON.stringify(data));
        promise.then(function (res) {
            if(res){
                window.open(path, "_blank");
            }else {
                Swal.fire({
                    icon: "error",
                    title: "Błąd",
                    text: "Brak przypisanych produktów"
                })
            }
        })
    }



</script>

<?php $this->view("page_components/footer"); ?>