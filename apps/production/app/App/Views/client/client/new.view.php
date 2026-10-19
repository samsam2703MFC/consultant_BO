<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Klient</h3>
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
                <h4 class="card-title">Tworzenie nowego klienta</h4>
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

                <form method="POST" data-vies-lookup-form data-vies-lookup-endpoint="<?=ROOT?>/ajax/clients/vies-lookup">
                    <div class="row">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <div class="row">
                                <div class="col-4">
                                    <label class="form-label" for="name">
                                        Imię
                                    </label>
                                    <input value="<?=old_value('name')?>" type="text" class="form-control" id="name" name="name" placeholder="Nazwa">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="surname">
                                        Nazwisko
                                    </label>
                                    <input value="<?=old_value('surname')?>" type="text" class="form-control" id="surnamesurname" name="surname" placeholder="Nazwisko">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="company">
                                        Firma
                                    </label>
                                    <input value="<?=old_value('company')?>" type="text" class="form-control" id="company" name="company" placeholder="Firma">
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-4">
                                    <label class="form-label" for="fac_street">
                                        Nazwa ulicy siedziby
                                    </label>
                                    <input value="<?=old_value('fac_street')?>" type="text" class="form-control" id="fac_street" name="fac_street" placeholder="Ulica">
                                </div>
                                <div class="col-2">
                                    <label class="form-label" for="fac_street_num">
                                        Numer budynku
                                    </label>
                                    <input value="<?=old_value('fac_street_num')?>" type="text" class="form-control" id="fac_street_num" name="fac_street_num" placeholder="numer">
                                </div>
                                <div class="col-2">
                                    <label class="form-label" for="fac_zip">
                                        Kod pocztowy
                                    </label>
                                    <input value="<?=old_value('fac_zip')?>" type="text" class="form-control" id="fac_zip" name="fac_zip" placeholder="12-345">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="fac_town">
                                        Miasto
                                    </label>
                                    <input value="<?=old_value('fac_town')?>" type="text" class="form-control" id="fac_town" name="fac_town" placeholder="Miasto">
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-4">
                                    <label class="form-label" for="id_country">
                                        Kraj
                                    </label>
                                    <select class="form-select" name="id_country" id="id_country">
                                        <?php if(!empty($countries)): ?>
                                            <?php foreach($countries as $country): ?>
                                                <option value="<?=$country['id']?>" <?=old_select('id_country', $country['id'])?> ><?=$country['name']?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="tax_number">
                                        NIP / VAT UE
                                    </label>
                                    <div class="input-group">
                                        <input value="<?=old_value('tax_number')?>" type="text" class="form-control" id="tax_number" name="tax_number" placeholder="np. PL1234567890" autocomplete="off">
                                        <button class="btn btn-outline-secondary" type="button" data-vies-lookup-button title="Pobierz dane z VIES" aria-label="Pobierz dane z VIES">
                                            <i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Pobierz dane z VIES</span>
                                        </button>
                                    </div>
                                    <div class="form-text" data-vies-lookup-status>Wpisz NIP lub VAT UE i pobierz dane z VIES.</div>
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="payment_termin">
                                        Czas na płatność (dni)
                                    </label>
                                    <input value="<?=old_value('payment_termin')?>" type="text" class="form-control" id="payment_termin" name="payment_termin" placeholder="Ilość dni">
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-4">
                                    <label class="form-label" for="email_head">
                                        Email główny
                                    </label>
                                    <input value="<?=old_value('email_head')?>" type="email" class="form-control" id="email_head" name="email_head" placeholder="Email">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="email_salesman">
                                        Email do przedstawiciela handlowego
                                    </label>
                                    <input value="<?=old_value('email_salesman')?>" type="email" class="form-control" id="email_salesman" name="email_salesman" placeholder="Email">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="email_accountant">
                                        Email do działu księgowości
                                    </label>
                                    <input value="<?=old_value('email_accountant')?>" type="email" class="form-control" id="email_accountant" name="email_accountant" placeholder="Email">
                                </div>
                            </div>

                            <div class="row mt-4">
                                <div class="col-4">
                                    <label class="form-label" for="phone_head">
                                        Numer kontaktowy
                                    </label>
                                    <input value="<?=old_value('phone_head')?>" type="text" class="form-control" id="phone_head" name="phone_head" placeholder="+48 123456789">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" for="phone_alt">
                                        Numer kontaktowy pomocniczy
                                    </label>
                                    <input value="<?=old_value('phone_alt')?>" type="text" class="form-control" id="phone_alt" name="phone_alt" placeholder="+48 123456789">
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="row mt-5">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <div class="row">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="price_list_type" value="standard_price_list" id="standard_price_list" checked onchange="toggleCustomers()">
                                    <label class="form-check-label" for="standard_price_list">
                                        Użyj cennika standardowego
                                    </label>
                                </div>
                            </div>
                            <div class="row">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="price_list_type" value="customer_price_list" id="customer_price_list" onchange="toggleCustomers()">
                                    <label class="form-check-label" for="customer_price_list">
                                        Użyj cennika klienta
                                    </label>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-4">
                                    <select class="form-select" name="customer_price_list_sel" id="customer_price_list_sel" disabled>
                                        <?php if(!empty($rows)): ?>
                                            <?php foreach($rows as $row): ?>
                                                <option value="<?=$row['id']?>"><?=$row['company']?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                    </div>
                    <div class="row mt-4">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <button class="btn btn-success">Utwórz</button>
                            <a href="<?=ROOT?>/clients" role="button" class="btn btn-secondary float-end">Powrót</a>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    function toggleCustomers(){
        document.getElementById('customer_price_list_sel').disabled = !document.getElementById('customer_price_list_sel').disabled;
    }
</script>
<script src="<?=ROOT?>/assets/js/client-vies-lookup.js"></script>
