<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Przewoźnik</h3>
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
                    <h4 class="card-title">Nowy przewoźnik</h4>
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
                                        <label class="form-label" for="name">
                                            Nazwa
                                        </label>
                                        <input value="<?=old_value('name')?>" type="text" class="form-control" id="name" name="name" placeholder="Nazwa">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="active">
                                            Aktywny
                                        </label>
                                        <select class="form-select" name="active" id="active">
                                            <?php
                                            if(@$_POST['active'] == "1"){
                                                echo "<option value='1' selected>Tak</option>";
                                                echo "<option value='0'>Nie</option>";
                                            } else {
                                                echo "<option value='1'>Tak</option>";
                                                echo "<option value='0' selected>Nie</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-6">
                                        <label class="form-label" for="phone">
                                            Numer telefonu
                                        </label>
                                        <input value="<?=old_value('phone')?>" type="text" class="form-control" id="phone" name="phone" placeholder="Numer telefonu">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="email">
                                            Email
                                        </label>
                                        <input value="<?=old_value('email')?>" type="email" class="form-control" id="email" name="email" placeholder="przykladowy@email.com">
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-6">
                                        <label class="form-label" for="street">
                                            Nazwa ulicy siedziby
                                        </label>
                                        <input value="<?=old_value('street')?>" type="text" class="form-control" id="street" name="street" placeholder="Ulica">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="street_num">
                                            Numer budynku
                                        </label>
                                        <input value="<?=old_value('street_num')?>" type="text" class="form-control" id="street_num" name="street_num" placeholder="Numer">
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-6">
                                        <label class="form-label" for="zip">
                                            Kod pocztowy
                                        </label>
                                        <input value="<?=old_value('zip')?>" type="text" class="form-control" id="zip" name="zip" placeholder="Kod pocztowy">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label" for="town">
                                            Miasto
                                        </label>
                                        <input value="<?=old_value('town')?>" type="text" class="form-control" id="town" name="town" placeholder="Miasto">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-success">Utwórz</button>
                                <a href="<?=ROOT?>/product-carriers" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
    </section>
</div>

<?php $this->view("page_components/footer")?>