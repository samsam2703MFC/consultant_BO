<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Pojazd przewoźnika</h3>
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
                    <h4 class="card-title">Nowy pojazd przewoźnika</h4>
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
                            <div class="col-2"></div>
                            <div class="col-8">
                                <div class="row">
                                    <div class="col-6">
                                        <label class="form-label" for="regis_num">
                                            Numer rejestracyjny
                                        </label>
                                        <input value="<?=old_value('regis_num')?>" type="text" class="form-control" id="regis_num" name="regis_num" placeholder="Numer rejestracyjny">
                                    </div>
                                    <div class="col-6"></div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-6">
                                        <label class="form-label" for="driver_name">
                                            Imię kierowcy
                                        </label>
                                        <input value="<?=old_value('driver_name')?>" type="text" class="form-control" id="driver_name" name="driver_name" placeholder="Imię">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="driver_surname">
                                            Nazwisko kierowcy
                                        </label>
                                        <input value="<?=old_value('driver_surname')?>" type="text" class="form-control" id="driver_surname" name="driver_surname" placeholder="Nazwisko">
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-6">
                                        <label class="form-label" for="driver_phone">
                                            Numer telefonu kierowcy
                                        </label>
                                        <input value="<?=old_value('driver_phone')?>" type="text" class="form-control" id="driver_phone" name="driver_phone" placeholder="Numer telefonu">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="driver_email">
                                            Email kierowcy
                                        </label>
                                        <input value="<?=old_value('driver_email')?>" type="email" class="form-control" id="driver_email" name="driver_email" placeholder="przykladowy@email.com">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-success">Utwórz</button>
                                <a href="<?=ROOT?>/product-carriers/<?=$id_carrier?>/vehicles" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
    </section>
</div>

<?php $this->view("page_components/footer")?>