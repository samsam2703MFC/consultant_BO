<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Cena produktu gotowego</h3>
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
                <h4 class="card-title">Podnieś wszystkie ceny</h4>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row mt-5">
                        <div class="col-4"></div>
                        <div class="col-4">
                            <label class="form-label" for="percentage">
                                Podnoszona wartość [%]
                            </label>
                            <input type="number" class="form-control" name="percentage" id="percentage" value="<?=old_value('percentage')?>" required step="0.01">
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-4"></div>
                        <div class="col-4">
                            <button class="btn btn-success">Zapisz</button>
                            <a href="<?=ROOT?>/clients/<?=$id_customer?>/price-list" role="button" class="btn btn-secondary float-end">Powrót</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    function enableUpdate(btn) {
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let saveBtn = btn.parentNode.children[1];
        btn.style.display = "none";
        saveBtn.style.display = "inline-block";
        previousColInput.disabled = false;
    }

    function disableUpdate(btn) {
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let enableBtn = btn.parentNode.children[0];
        btn.style.display = "none";
        enableBtn.style.display = "inline-block";
        previousColInput.disabled = true;
    }

    function saveNewPrice(id_product, id_customer, btn) {
        const data = {
            "id_final_product": id_product,
            "id_customer": id_customer,
            "price": btn.parentNode.parentNode.children[1].children[0].value
        }

        const promise = getJSON(validAjaxUrl() + "ajax/price/update", "data=" + JSON.stringify(data));
        promise.then(function (res) {
            showSnack(res);
            if (res) {
                disableUpdate(btn);
            }
        })
    }

    function saveStandardPrice(id_product, btn) {
        const data = {
            "id": id_product,
            "standard_price": btn.parentNode.parentNode.children[1].children[0].value
        }

        const promise = getJSON(validAjaxUrl() + "ajax/final_product/update", "data=" + JSON.stringify(data));
        promise.then(function (res) {
            showSnack(res);
            if (res) {
                disableUpdate(btn);
            }
        })
    }

    function searchInTable(input_value) {
        // Declare variables
        var filter, table, tr, td, i, txtValue;
        filter = input_value.toUpperCase();
        table = document.getElementById("id_final_product_table");
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

</script>
