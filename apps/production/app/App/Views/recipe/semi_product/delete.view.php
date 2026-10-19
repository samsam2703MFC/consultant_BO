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
                <h4 class="card-title">Usuwanie półproduktu</h4>
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
                        <div class="col-3"></div>
                        <div class="col-6">
                            <label for="composition_name" class="form-label">
                                Nazwa kompozycji
                            </label>
                            <input type="text" class="form-control" readonly id="composition_name" name="name" value="<?=old_value('name', $row['name'])?>" placeholder="Nazwa">
                        </div>
                    </div>
                    <div class="row mt-5">
                        <div class="col-3"></div>
                        <div class="col-4">
                            <label for="yield_quantity" class="form-label">
                                Otrzymana ilość produktu z receptury
                            </label>
                            <input type="number" class="form-control" readonly name="yield_quantity" value="<?=old_value('yield_quantity', $row['yield_quantity'])?>">
                        </div>
                        <div class="col-2">
                            <label for="unit" class="form-label">
                                Jednostka wyjściowa
                            </label>
                            <div class="form-control">
                                g
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <button class="btn btn-danger">Usuń</button>
                            <a href="<?=ROOT?>/semi-products" role="button" class="btn btn-secondary float-end">Powrót</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>
