<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Reklamacja</h3>
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

            <?php if($action == 'new'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Przyjęcie reklamacji</h4>
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

                        </form>
                    </div>
                </div>

            <?php elseif($action == 'new_from_note'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Reklamacja franczyzobiorcy</h4>
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
                                <div class="col-1"></div>
                                <div class="col-10">
                                    <div class="row">
                                        <div class="col-3">
                                            <label for="customer" class="form-label">
                                                Klient
                                            </label>
                                            <div class="form-control" id="customer" disabled><?=$order_note->company?></div>
                                        </div>
                                        <div class="col-2">
                                            <label for="invoice_num" class="form-label">
                                                Numer faktury
                                            </label>
                                            <div class="form-control" id="invoice_num" disabled><?=$order_note->invoice_num?></div>
                                        </div>
                                        <div class="col-4">
                                            <label for="id_complaint_reason" class="form-label">
                                                Powód reklamacji
                                            </label>
                                            <select class="form-select" name="id_complaint_reason" id="id_complaint_reason">
                                                <?php if(!empty($complaint_reason)): ?>
                                                    <?php foreach ($complaint_reason as $reason): ?>
                                                        <option value="<?=$reason->id?>"><?=$reason->content?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="col-3">
                                            <label for="id_complaint_compensation_method" class="form-label">
                                                Sposób rekompensaty
                                            </label>
                                            <select class="form-select" name="id_complaint_compensation_method" id="id_complaint_compensation_method">
                                                <?php if(!empty($complaint_compensation_method)): ?>
                                                    <?php foreach ($complaint_compensation_method as $method): ?>
                                                        <option value="<?=$method->id?>"><?=$method->content?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-4">
                                            <label for="product" class="form-label">
                                                Produkt
                                            </label>
                                            <div class="form-control" id="product" disabled><?=$order_note->product_name?></div>
                                        </div>
                                        <div class="col-3">
                                            <label for="product_quantity" class="form-label">
                                                Ilość
                                            </label>
                                            <div class="input-group mb-3" id="product_quantity">
                                                <input type="number" class="form-control" step="1" name="product_quantity" id="product_quantity_inp" readonly value="<?=$order_note->product_quantity?>">
                                                <div class="input-group-append">
                                                    <button class="btn btn-warning mx-3" type="button" onclick="enableEdit('product_quantity_inp')">Edytuj</button>
                                                </div>
                                            </div>

                                        </div>
                                        <div class="col-3">
                                            <label for="single_purchase_price" class="form-label">
                                                Cena zakupu [€]
                                            </label>
                                            <div class="input-group mb-3" id="single_purchase_price">
                                                <input type="number" class="form-control" step="0.01" name="single_purchase_price" id="single_purchase_price_inp" readonly value="<?=$order_note->single_purchase_price?>">
                                                <div class="input-group-append">
                                                    <button class="btn btn-warning mx-3" type="button" onclick="enableEdit('single_purchase_price_inp')">Edytuj</button>
                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-6">
                                            <button class="btn btn-success">Potwierdź</button>
                                        </div>
                                        <div class="col-6">
                                            <a href="<?=ROOT?>/Franchisee/feedback/progress" role="button" class="btn btn-secondary float-end">Powrót</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif($action == 'generated'): ?>


            <?php else:?>

            <?php endif; ?>
        </section>
    </div>

    <script>

        function enableEdit(id_edit_control){
            document.getElementById(id_edit_control).readOnly = !document.getElementById(id_edit_control).readOnly
        }

    </script>

<?php $this->view("page_components/footer"); ?>