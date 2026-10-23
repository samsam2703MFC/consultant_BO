<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Cena produktu gotowego</h3>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Cena produktu gotowego
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
