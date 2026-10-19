<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Dokumenty wysyłki</h3>
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
                <h4 class="card-title">Różnice w zamówieniu</h4>
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
                <div class="row">
                    <div class="col-1"></div>
                    <div class="col-10">
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 20%">Produkt</th>
                            <th style="width: 15%">Zamówiona ilość</th>
                            <th style="width: 15%">Wysłana ilość</th>
                            <th style="width: 15%">Różnica</th>
                            <th style="width: 20%">Komentarz</th>
                            <th style="width: 15%"></th>
                            </thead>
                            <tbody>
                            <?php if(!empty($rows)): ?>
                                <?php foreach($rows as $row): ?>
                                    <?php $class = ($row['quantity_ordered'] != $row['invoiced_quantity']) ? "bg-light-danger" : ""; ?>
                                    <tr class="<?=$class?>">
                                        <td><?=$row['product_name']?></td>
                                        <td><?=$row['quantity_ordered']?></td>
                                        <td><?=$row['invoiced_quantity']?></td>
                                        <td><?=$row['quantity_ordered'] - $row['invoiced_quantity']?></td>
                                        <td><select class="form-select">
                                                <?php if(!empty($missing_reasons)): ?>
                                                    <?php foreach($missing_reasons as $reason): ?>
                                                        <option value="-1" selected></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>


                                            <input type="text" class="form-control" name="difference_reason" value="<?=$row['difference_reason']?>"></td>
                                        <td><button type="button" class="btn btn-success" onclick="saveDifference(this, <?=$row['id_document_order_shipping_difference']?>, <?=$row['id_product']?>)">Zapisz</button></td>
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

<script type="text/javascript">

    function saveDifference(btn, id_doc, id_product){

        const data = {
            "value" : btn.parentNode.parentNode.children[4].firstChild.value,
            "id_doc" : id_doc,
            "id_product" : id_product
        }

        const promise = getJSON(validAjaxUrl()+`ajax/diff/update`, "data="+JSON.stringify(data));
        promise.then(function (res){
            showSnack(res);
        })
    }


    function enableUpdate(btn){
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let saveBtn = btn.parentNode.children[1];
        btn.style.display = "none";
        saveBtn.style.display = "inline-block";
        previousColInput.disabled = false;
    }

    function disableUpdate(btn){
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let enableBtn = btn.parentNode.children[0];
        btn.style.display = "none";
        enableBtn.style.display = "inline-block";
        previousCol.disabled = true;
    }

</script>
