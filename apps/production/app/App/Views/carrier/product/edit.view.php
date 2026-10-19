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
                    <h4 class="card-title">Edytuj dane przewoźnika</h4>
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
                                <div class="col-2"></div>
                                <div class="col-8">
                                    <div class="row">
                                        <div class="col-6">
                                            <label class="form-label" for="name">
                                                Nazwa
                                            </label>
                                            <input value="<?=old_value('name', $row['name'])?>" type="text" class="form-control" id="name" name="name" placeholder="Nazwa">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="active">
                                                Aktywny
                                            </label>
                                            <select class="form-select" name="active" id="active">
                                                <option value="1" <?php if($row['active'] == "1") echo 'selected'; ?>>Tak</option>
                                                <option value="0" <?php if($row['active'] == "0") echo 'selected'; ?>>Nie</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-6">
                                            <label class="form-label" for="phone">
                                                Numer telefonu
                                            </label>
                                            <input value="<?=old_value('phone', $row['phone'])?>" type="text" class="form-control" id="phone" name="phone" placeholder="Numer telefonu">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="email">
                                                Email
                                            </label>
                                            <input value="<?=old_value('email', $row['email'])?>" type="email" class="form-control" id="email" name="email" placeholder="przykladowy@email.com">
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-6">
                                            <label class="form-label" for="street">
                                                Nazwa ulicy siedziby
                                            </label>
                                            <input value="<?=old_value('street', $row['street'])?>" type="text" class="form-control" id="street" name="street" placeholder="Ulica">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="street_num">
                                                Numer budynku
                                            </label>
                                            <input value="<?=old_value('street_num', $row['street_num'])?>" type="text" class="form-control" id="street_num" name="street_num" placeholder="Numer">
                                        </div>
                                    </div>
                                    <div class="row mt-4">
                                        <div class="col-6">
                                            <label class="form-label" for="zip">
                                                Kod pocztowy
                                            </label>
                                            <input value="<?=old_value('zip', $row['zip'])?>" type="text" class="form-control" id="zip" name="zip" placeholder="Kod pocztowy">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" for="town">
                                                Miasto
                                            </label>
                                            <input value="<?=old_value('town', $row['town'])?>" type="text" class="form-control" id="town" name="town" placeholder="Miasto">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-2"></div>
                                <div class="col-8">
                                    <button class="btn btn-warning">Zapisz</button>
                                    <a href="<?=ROOT?>/product-carriers" role="button" class="btn btn-secondary float-end">Powrót</a>
                                </div>
                            </div>
                        </form>

                    <?php else: ?>
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="alert alert-danger text-center">Dostawca nie istnieje nie istnieje!</div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <a href="<?=ROOT?>/product-carriers" role="button" class="btn btn-secondary">Powrót</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>