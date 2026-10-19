<?php $this->view("page_components/header"); ?>
    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Zamówienia</h3>
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
                    <h4 class="card-title">Usuwanie zamówienia</h4>
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

                    <?php if(!empty($row)): ?>

                        <form method="POST">
                            <div class="row">
                                <div class="col-4"></div>
                                <div class="col-4">
                                    <div class="row">
                                        <label class="form-label" for="">
                                            Klient
                                        </label>
                                        <div class="form-control"><?=$row['company']?></div>
                                    </div>
                                    <div class="row mt-4">
                                        <label class="form-label" for="expected_shipping_date">
                                            Przewidywana data wysyłki
                                        </label>
                                        <div class="form-control"><?=$row['expected_shipping_date']?></div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-6">
                                            <button class="btn btn-danger">Usuń</button>
                                        </div>
                                        <div class="col-6">
                                            <a href="<?=ROOT?>/client-orders" role="button" class="btn btn-secondary float-end">Powrót</a>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </form>

                    <?php else: ?>
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="alert alert-danger text-center">Zamówienie nie istnieje!</div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <a href="<?=ROOT?>/client-orders" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>

<script>
    function togglePending(source){
        checkboxes = document.getElementsByName('order[]');
        for(var i=0, n=checkboxes.length;i<n;i++) {
            checkboxes[i].checked = source.checked;
        }
    }

    function validateZeroValue(input){
        if(input.value === "0"){
            input.classList.add("is-invalid")
            console.log("X");
        }else{
            if(input.classList.contains("is-invalid")) input.classList.remove("is-invalid");
        }
    }

    function addToTable(btn, input_name){
        const row = btn.parentNode.parentNode;
        const product_select = row.querySelectorAll("select")[0];
        const id_product = product_select.value;
        const product_name = product_select[product_select.selectedIndex].innerText;
        const quantityInput = row.querySelectorAll("input[type='number']")[0];
        const quantity = quantityInput.value;
        const target_body = row.parentNode.parentNode.querySelectorAll("tbody")[0];

        for(let i=0; i<target_body.rows.length; i++){
            if(target_body.rows[i].cells[0].innerText === product_name){
                return;
            }
        }

        let insertedRow = target_body.insertRow(target_body.rows.length);
        const cell0 = insertedRow.insertCell(0);
        const cell1 = insertedRow.insertCell(1);
        const cell2 = insertedRow.insertCell(2);
        cell0.innerHTML = `${product_name}`
        cell1.innerHTML = `<input type="number" class="form-control" value="${quantity}" name="${input_name}[${id_product}]" onkeyup="validateZeroValue(this)">`
        cell2.innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;

        product_select.selectedIndex = 0;
        quantityInput.value = "";
    }

    function removeRow(btn){
        const row = btn.parentNode.parentNode;
        row.parentNode.removeChild(row);
    }

    function updateStatus(id_order, btn){
        const data = {
            "id_order" : id_order
        }

        const promise = getJSON(validAjaxUrl()+`ajax/status/update`, "data="+JSON.stringify(data));
        promise.then(function (res){
            showSnack(res);
            if(res){
                btn.parentNode.parentNode.remove();
            }
        })
    }

</script>

