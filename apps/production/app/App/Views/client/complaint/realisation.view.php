<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Produkty oczekujące na wysłanie</h3>
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
                    <h4 class="card-title">Ustalanie daty wysyłki dla wybranych produktów</h4>
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
                    <?php if(!empty($pending_product_info)): ?>
                        <form method="POST">
                            <div class="row">
                                <div class="col-2"></div>
                                <div class="col-8">
                                    <div class="row">
                                        <div class="col-4">
                                            <label class="form-label">
                                                Klient
                                            </label>
                                            <div class="form-control" readonly><?=$pending_product_info['company']?></div>
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label">
                                                Produkt
                                            </label>
                                            <div class="form-control" readonly><?=$pending_product_info['product_name']?></div>
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label">
                                                Zapełnienie kartonu
                                            </label>
                                            <div class="form-control" readonly><?=$pending_product_info['pending_product_qty']?> / <?=$pending_product_info['ppc']?></div>
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-4">
                                            <label for="shipping_date" class="form-label">
                                                Data wysyłki
                                            </label>
                                            <input type="date" class="form-control" name="shipping_date" id="shipping_date" required min="">
                                        </div>
                                        <div class="col-4">
                                            <label for="carton_qty" class="form-label">
                                                Ilość kartonów
                                            </label>
                                            <input type="number" class="form-control" name="carton_qty" id="carton_qty" required min="1" value="<?=$pending_product_info['expected_carton_qty']?>">
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-6">
                                            <button class="btn btn-success">Zapisz</button>
                                        </div>
                                        <div class="col-6">
                                            <a href="<?=ROOT?>/client-complaints/pending-products" class="btn btn-secondary float-end">Powrót</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-12 text-center">
                                <h4>Brak danych dotyczących wybranego produktu!</h4>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>

<script>
    document.getElementById("shipping_date").min = new Date().toISOString().split("T")[0];
</script>

