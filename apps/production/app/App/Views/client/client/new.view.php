<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Klient</h3>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Klient
                        </li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Nowy klient</h4>
            </div>
            <div class="card-body">

                <?php if(!empty($errors)):?>
                    <div class="alert alert-danger"><?=implode("<br>", array_map('htmlspecialchars', $errors))?></div>
                <?php endif; ?>

                <?php $steps_error = ''; foreach (['company', 'tax_number', 'email_head', 'email_salesman', 'email_accountant', 'payment_termin'] as $f) { if (!empty($errors[$f])) { $steps_error = $f; break; } } ?>
                <form method="POST" data-vies-lookup-form data-vies-lookup-endpoint="<?=ROOT?>/ajax/clients/vies-lookup" data-at-steps data-at-steps-error="<?= htmlspecialchars($steps_error) ?>" class="at-form-steps">

                    <section data-step="Firma i VAT">
                        <h4 class="at-step-title">Firma i numer VAT</h4>
                        <p class="at-step-lead">Wpisz NIP / VAT UE i pobierz nazwę i adres z VIES — resztę uzupełnisz w kolejnych krokach.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="tax_number">NIP / VAT UE *</label>
                                <div class="input-group">
                                    <input value="<?=old_value('tax_number')?>" type="text" class="form-control" id="tax_number" name="tax_number" placeholder="np. PL1234567890" autocomplete="off" required>
                                    <button class="btn btn-outline-secondary" type="button" data-vies-lookup-button title="Pobierz dane z VIES" aria-label="Pobierz dane z VIES">
                                        <i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Pobierz dane z VIES</span>
                                    </button>
                                </div>
                                <div class="form-text" data-vies-lookup-status>Wpisz NIP lub VAT UE i pobierz dane z VIES.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="company">Firma *</label>
                                <input value="<?=old_value('company')?>" type="text" class="form-control" id="company" name="company" placeholder="Firma" maxlength="60" required>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label" for="fac_street">Nazwa ulicy siedziby *</label>
                                <input value="<?=old_value('fac_street')?>" type="text" class="form-control" id="fac_street" name="fac_street" required placeholder="Ulica">
                            </div>
                            <div class="col-4 col-md-2">
                                <label class="form-label" for="fac_street_num">Numer budynku *</label>
                                <input value="<?=old_value('fac_street_num')?>" type="text" class="form-control" id="fac_street_num" name="fac_street_num" required placeholder="numer">
                            </div>
                            <div class="col-8 col-md-2">
                                <label class="form-label" for="fac_zip">Kod pocztowy *</label>
                                <input value="<?=old_value('fac_zip')?>" type="text" class="form-control" id="fac_zip" name="fac_zip" required placeholder="12-345">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label" for="fac_town">Miasto *</label>
                                <input value="<?=old_value('fac_town')?>" type="text" class="form-control" id="fac_town" name="fac_town" required placeholder="Miasto">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="id_country">Kraj</label>
                                <select class="form-select" name="id_country" id="id_country">
                                    <?php foreach ($countries ?? [] as $country): ?>
                                        <option value="<?=$country['id']?>" <?=old_select('id_country', $country['id'])?>><?=$country['name']?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </section>

                    <section data-step="Kontakt i język" hidden>
                        <h4 class="at-step-title">Kontakt, język i płatność</h4>
                        <p class="at-step-lead">Adresy e-mail są sprawdzane (kilka adresów : przecinek). Język wybiera wersję dokumentów, e-maili i SMS.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="name">Imię (osoba kontaktowa)</label>
                                <input value="<?=old_value('name')?>" type="text" class="form-control" id="name" name="name" placeholder="Imię">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="surname">Nazwisko (osoba kontaktowa)</label>
                                <input value="<?=old_value('surname')?>" type="text" class="form-control" id="surname" name="surname" placeholder="Nazwisko">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="email_head">Email główny</label>
                                <input value="<?=old_value('email_head')?>" type="email" multiple class="form-control" id="email_head" name="email_head" placeholder="adres@firma.pl (kilka : przecinek)">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="email_salesman">Email do przedstawiciela handlowego</label>
                                <input value="<?=old_value('email_salesman')?>" type="email" multiple class="form-control" id="email_salesman" name="email_salesman" placeholder="adres@firma.pl (kilka : przecinek)">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="email_accountant">Email do działu księgowości</label>
                                <input value="<?=old_value('email_accountant')?>" type="email" multiple class="form-control" id="email_accountant" name="email_accountant" placeholder="adres@firma.pl (kilka : przecinek)">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="phone_head">Numer kontaktowy</label>
                                <input value="<?=old_value('phone_head')?>" type="text" class="form-control" id="phone_head" name="phone_head" placeholder="+48 123456789">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="phone_alt">Numer kontaktowy pomocniczy</label>
                                <input value="<?=old_value('phone_alt')?>" type="text" class="form-control" id="phone_alt" name="phone_alt" placeholder="+48 123456789">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="communication_lang">Język komunikacji (dokumenty, e-maile, SMS)</label>
                                <select class="form-select" id="communication_lang" name="communication_lang">
                                    <?php foreach (['fr' => 'Francuski', 'nl' => 'Niderlandzki', 'pl' => 'Polski', 'en' => 'Angielski'] as $code => $label): ?>
                                        <option value="<?= $code ?>" <?= old_select('communication_lang', $code, 'fr') ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="payment_termin">Czas na płatność (dni)</label>
                                <input value="<?=old_value('payment_termin')?>" type="number" min="0" max="365" step="1" class="form-control" id="payment_termin" name="payment_termin" placeholder="Ilość dni (np. 14)">
                            </div>
                        </div>
                    </section>

                    <section data-step="Cennik" hidden>
                        <h4 class="at-step-title">Cennik klienta</h4>
                        <p class="at-step-lead">Cennik standardowy albo kopia cennika innego klienta (te same ceny od pierwszego zamówienia). Ceny zmienisz później w « Cennik klienta ».</p>
                        <?php $copyPrices = ($_POST['price_list_type'] ?? '') === 'customer_price_list'; ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="price_list_type" value="standard_price_list" id="standard_price_list" <?= $copyPrices ? '' : 'checked' ?> data-summary-group="Cennik" data-summary-label="standardowy">
                            <label class="form-check-label" for="standard_price_list">Użyj cennika standardowego</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="price_list_type" value="customer_price_list" id="customer_price_list" <?= $copyPrices ? 'checked' : '' ?> data-summary-group="Cennik" data-summary-label="kopia cennika klienta">
                            <label class="form-check-label" for="customer_price_list">Użyj cennika klienta</label>
                        </div>
                        <div class="col-12 col-md-6 ms-4">
                            <label class="form-label visually-hidden" for="customer_price_list_sel">Klient, od którego kopiujemy cennik</label>
                            <select class="form-select" name="customer_price_list_sel" id="customer_price_list_sel" <?= $copyPrices ? '' : 'disabled' ?> data-summary-label="Cennik jak u klienta">
                                <?php foreach ($rows ?? [] as $row): ?>
                                    <option value="<?=$row['id']?>" <?= old_select('customer_price_list_sel', $row['id']) ?>><?= htmlspecialchars((string) $row['company']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </section>

                    <section data-step="Dostawa" hidden>
                        <h4 class="at-step-title">Dostawa</h4>
                        <p class="at-step-lead">Stała trasa (dni i pojazd trasy) i instrukcje dla kierowcy na tablecie. Wszystko opcjonalne — uzupełnisz później na karcie klienta albo w Logistyka › Trasy.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="tour_id">Stała trasa</label>
                                <select class="form-select" id="tour_id" name="tour_id">
                                    <option value="">— bez stałej trasy —</option>
                                    <?php foreach ($tours ?? [] as $tour): ?>
                                        <option value="<?= (int) $tour['id'] ?>" <?= old_select('tour_id', $tour['id']) ?>><?= htmlspecialchars($tour['name'] . ($tour['days'] ? ' · ' . $tour['days'] : '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="tour_stop">Przystanek nr</label>
                                <input type="number" min="1" max="99" class="form-control" id="tour_stop" name="tour_stop" value="<?= old_value('tour_stop') ?>" placeholder="na końcu">
                            </div>
                            <?php foreach (\App\Services\Logistics\CustomerDeliveryInfoService::FIELDS as $key => [$label, $labelFr, $max, $secret]): ?>
                                <div class="col-12 <?= $key === 'procedure' ? '' : 'col-md-6' ?>">
                                    <label class="form-label mb-1" for="delivery_<?= $key ?>"><?= htmlspecialchars($label) ?><?= $secret ? ' <i class="bi bi-lock" title="poufne"></i>' : '' ?></label>
                                    <textarea class="form-control" id="delivery_<?= $key ?>" name="delivery_<?= $key ?>" rows="2" maxlength="<?= (int) $max ?>" autocomplete="off"><?= htmlspecialchars((string) ($_POST['delivery_' . $key] ?? '')) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                            <p class="text-muted small mb-0"><i class="bi bi-shield-lock"></i> Alarm i klucz : zapis zaszyfrowany, nigdy w dokumentach dla klienta.</p>
                        </div>
                    </section>

                    <section data-step="Dokumenty" hidden>
                        <h4 class="at-step-title">Dokumenty i e-maile</h4>
                        <p class="at-step-lead">Kto dostaje proformę, WDT, fakturę, WZ / CMR i w jakim momencie (Dystrybucja dokumentów). Domyślnie : ustawienia domyślne ; albo jak u podobnego klienta.</p>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="documents_from">Ustawienia dokumentów</label>
                            <select class="form-select" id="documents_from" name="documents_from">
                                <option value="">Domyślne (Komunikacja › Dystrybucja dokumentów)</option>
                                <?php foreach ($rows ?? [] as $row): ?>
                                    <option value="<?= (int) $row['id'] ?>" <?= old_select('documents_from', $row['id']) ?>>Jak u klienta : <?= htmlspecialchars((string) $row['company']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </section>

                    <section data-step="Opcje klienta" hidden>
                        <h4 class="at-step-title">Strona klienta i cel obrotu</h4>
                        <p class="at-step-lead">Dla franczyzobiorców : strona z dokumentami i cel obrotu (zakupy × współczynnik). Można włączyć później.</p>
                        <input type="hidden" name="space_docs" value="0">
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="space_docs" name="space_docs" value="1" <?= ($_POST['space_docs'] ?? '') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="space_docs">Strona klienta : dokumenty</label></div>
                        <input type="hidden" name="space_goal" value="0">
                        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="space_goal" name="space_goal" value="1" <?= ($_POST['space_goal'] ?? '') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="space_goal">Cel obrotu (franczyza) : zakupy × współczynnik</label></div>
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="space_coefficient">Współczynnik sprzedaży</label>
                                <input class="form-control" id="space_coefficient" name="space_coefficient" inputmode="decimal" placeholder="<?= number_format((float) ($space['default_coefficient'] ?? 2.5), 2, ',', '') ?>" value="<?= htmlspecialchars((string) ($_POST['space_coefficient'] ?? '')) ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="space_open_days">Dni otwarcia w miesiącu</label>
                                <input class="form-control" id="space_open_days" name="space_open_days" inputmode="numeric" placeholder="<?= \App\Services\Client\ClientSpaceService::DEFAULT_OPEN_DAYS ?>" value="<?= htmlspecialchars((string) ($_POST['space_open_days'] ?? '')) ?>">
                            </div>
                        </div>
                    </section>

                    <section data-step="Podsumowanie" data-at-steps-summary hidden>
                        <h4 class="at-step-title">Podsumowanie</h4>
                        <p class="at-step-lead">Sprawdź i utwórz klienta. Następnie otworzy się karta klienta.</p>
                        <div data-at-steps-summary-body></div>
                    </section>

                    <div class="d-flex justify-content-between mt-2">
                        <a href="<?=ROOT?>/clients" role="button" class="btn btn-link px-0">Anuluj</a>
                    </div>
                    <button class="btn btn-success" data-at-steps-submit><i class="bi bi-check2"></i> Utwórz klienta</button>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    // Cennik : lista klientów tylko przy « Użyj cennika klienta ».
    document.querySelectorAll('[name="price_list_type"]').forEach((r) => r.addEventListener('change', () => {
        document.getElementById('customer_price_list_sel').disabled = !document.getElementById('customer_price_list').checked;
    }));
</script>
<script src="<?=ROOT?>/assets/js/at-form-steps.js?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>"></script>
<script src="<?=ROOT?>/assets/js/client-vies-lookup.js"></script>
