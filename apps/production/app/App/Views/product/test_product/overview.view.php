<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Produkt testowy</h3>
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
                <h4 class="card-title">Lista testowych produktów  <a href="<?=ROOT?>/test-products/new" class="btn btn-primary" role="button">Dodaj nowy</a></h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-8"></div>
                    <div class="col-4">
                        <a href="<?=ROOT?>/TestProduct/synchronizeInFranchisee" class="btn btn-success  float-end" role="button">Prześlij do franczyzy</a>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <table class="table table-striped">
                            <thead>
                            <th style="width: 20%">Kategoria</th>
                            <th style="width: 20%">Nazwa</th>
                            <th style="width: 10%">Typ</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 15%">Termin następnego etapu</th>
                            <th style="width: 5%"></th>
                            <th style="width: 5%"></th>
                            <th style="width: 10%"></th>
                            <th style="width: 5%"></th>
                            </thead>
                            <tbody>
                            <?php if(!empty($rows)): ?>
                                <?php foreach($rows as $row): ?>
                                    <tr>
                                        <td><?= $row['category_name'] ?></td>
                                        <td><?= $row['name'] ?></td>
                                        <td><?= $row['type_name'] ?></td>
                                        <td>
                                            <select class="form-select" name="id_status" onchange="updateTestProductStatus(<?=$row['id']?>, this)">
                                                <?php if(!empty($product_status)):?>
                                                    <?php foreach ($product_status as $product_status_item): ?>
                                                        <?php ($row['id_status'] == $product_status_item['id']) ? $selected = "selected" : $selected = ""; ?>
                                                        <option value="<?= $product_status_item['id'] ?>" <?=$selected?>><?= $product_status_item['name'] ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </td>
                                        <td><?= $row['closest_deadline'] ?></td>
                                        <?php if($row['closest_deadline'] != null): ?>
                                            <td><a href="<?=ROOT?>/TestProductChangelog/getPDF/<?=$row['id']?>" class="btn btn-info" target="_blank">PDF</a></td>
                                        <?php else: ?>
                                            <td></td>
                                        <?php endif; ?>
                                        <td><a href="<?=ROOT?>/test-products/<?=$row['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                        <td><a href="<?=ROOT?>/test-products/<?=$row['id']?>/changelogs" class="btn btn-secondary">Dziennik zmian</a></td>
                                        <td><a href="<?=ROOT?>/test-products/<?=$row['id']?>/delete" class="btn btn-danger">Usuń</a></td>
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

<script>
    function updateTestProduct(id_test_product, control){
        let data = {};
        data[control.name] = control.value;
        data['id'] = id_test_product;

        const promise = getJSON(validAjaxUrl()+`ajax/test_product/update`, "data="+JSON.stringify(data));
        promise.then(function (res){

        })
    }

    function checkType(type){
        if(type != 1){
            document.getElementById('id_category').disabled = true;
            document.getElementById('id_section').disabled = true;
            document.getElementById('label_type').disabled = true;
        }else{
            document.getElementById('id_category').disabled = false;
            document.getElementById('id_section').disabled = false;
            document.getElementById('label_type').disabled = false;
        }
    }
</script>