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
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h4 class="card-title mb-0">Edytuj klienta</h4>
                <?php if (!empty($row['id'])): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?= ROOT ?>/clients/<?= (int) $row['id'] ?>/price-list"><i class="bi bi-tags"></i> Cennik klienta</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= ROOT ?>/clients/<?= (int) $row['id'] ?>/profitability"><i class="bi bi-graph-up"></i> Rentowność</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= ROOT ?>/finances/sales-analytics?customer_id=<?= (int) $row['id'] ?>"><i class="bi bi-box-seam"></i> Sprzedaż WDT</a>
                    </div>
                <?php endif; ?>
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
                    <?php if (!empty($created)): ?>
                        <?php
                        // Kreator klienta : co jeszcze warto uzupełnić (puste pola karty + kroki, które się nie zapisały).
                        $todo = [];
                        if (trim((string) ($row['email_head'] ?? '')) === '') $todo[] = ['E-mail główny (proformy, powiadomienia)', '#email_head'];
                        if (trim((string) ($row['email_accountant'] ?? '')) === '') $todo[] = ['E-mail księgowości (faktury)', '#email_accountant'];
                        if (trim((string) ($row['fac_street'] ?? '')) === '' || trim((string) ($row['fac_town'] ?? '')) === '') $todo[] = ['Adres siedziby (WZ, CMR, WDT)', '#fac_street'];
                        if (empty($delivery_info['fields']) || implode('', (array) $delivery_info['fields']) === '') $todo[] = ['Instrukcje dostawy dla kierowcy', '#delivery-info'];
                        ?>
                        <div class="at-note at-note-safe mb-3" role="status">
                            <i class="bi bi-check-circle"></i>
                            <div><strong>Klient utworzony.</strong> Karta klienta poniżej ; cennik, trasa i dokumenty są już ustawione.
                                <?php if (!empty($created['problems'])): ?><div class="text-danger mt-1"><?= implode('<br>', array_map('htmlspecialchars', $created['problems'])) ?></div><?php endif; ?>
                                <?php if ($todo): ?><div class="mt-1">Do uzupełnienia : <?= implode(' · ', array_map(fn($t) => '<a href="' . $t[1] . '">' . htmlspecialchars($t[0]) . '</a>', $todo)) ?></div><?php endif; ?>
                                <div class="mt-1"><a href="<?= ROOT ?>/clients/<?= (int) $row['id'] ?>/price-list">Cennik klienta</a> · <a href="<?= ROOT ?>/communication/distribution">Dystrybucja dokumentów</a> · <a href="<?= ROOT ?>/logistics/tours">Trasy</a></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" data-vies-lookup-form data-vies-lookup-endpoint="<?=ROOT?>/ajax/clients/vies-lookup">
                        <div class="row">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <div class="row">
                                    <div class="col-4">
                                        <label class="form-label" for="name">
                                            Imię (osoba kontaktowa)
                                        </label>
                                        <input value="<?=old_value('name', $row['name'])?>" type="text" class="form-control" id="name" name="name" placeholder="Nazwa">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="surname">
                                            Nazwisko (osoba kontaktowa)
                                        </label>
                                        <input value="<?=old_value('surname', $row['surname'])?>" type="text" class="form-control" id="surnamesurname" name="surname" placeholder="Nazwisko">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="company">
                                            Firma *
                                        </label>
                                        <input value="<?=old_value('company', $row['company'])?>" type="text" class="form-control" id="company" name="company" placeholder="Firma" maxlength="60" required>
                                    </div>
                                </div>
                                <div class="row mt-4">
                                    <div class="col-4">
                                        <label class="form-label" for="fac_street">
                                            Nazwa ulicy siedziby
                                        </label>
                                        <input value="<?=old_value('fac_street', $row['fac_street'])?>" type="text" class="form-control" id="fac_street" name="fac_street" placeholder="Ulica">
                                    </div>
                                    <div class="col-2">
                                        <label class="form-label" for="fac_street_num">
                                            Numer budynku
                                        </label>
                                        <input value="<?=old_value('fac_street_num', $row['fac_street_num'])?>" type="text" class="form-control" id="fac_street_num" name="fac_street_num" placeholder="numer">
                                    </div>
                                    <div class="col-2">
                                        <label class="form-label" for="fac_zip">
                                            Kod pocztowy
                                        </label>
                                        <input value="<?=old_value('fac_zip', $row['fac_zip'])?>" type="text" class="form-control" id="fac_zip" name="fac_zip" placeholder="12-345">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="fac_town">
                                            Miasto
                                        </label>
                                        <input value="<?=old_value('fac_town', $row['fac_town'])?>" type="text" class="form-control" id="fac_town" name="fac_town" placeholder="Miasto">
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
                                                    <option value="<?=$country['id']?>" <?=old_select('id_country', $country['id'], $row['id_country'])?> ><?=$country['name']?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="tax_number">
                                            NIP / VAT UE *
                                        </label>
                                        <div class="input-group">
                                            <input value="<?=old_value('tax_number', $row['tax_number'])?>" type="text" class="form-control" id="tax_number" name="tax_number" placeholder="np. PL1234567890" autocomplete="off" required>
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
                                        <input value="<?=old_value('payment_termin', $row['payment_termin'])?>" type="number" min="0" max="365" step="1" class="form-control" id="payment_termin" name="payment_termin" placeholder="Ilość dni (np. 14)">
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-4">
                                        <label class="form-label" for="email_head">
                                            Email główny
                                        </label>
                                        <input value="<?=old_value('email_head', $row['email_head'])?>" type="email" multiple class="form-control" id="email_head" name="email_head" placeholder="adres@firma.pl (kilka : przecinek)">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="email_salesman">
                                            Email do przedstawiciela handlowego
                                        </label>
                                        <input value="<?=old_value('email_salesman', $row['email_salesman'])?>" type="email" multiple class="form-control" id="email_salesman" name="email_salesman" placeholder="adres@firma.pl (kilka : przecinek)">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="email_accountant">
                                            Email do działu księgowości
                                        </label>
                                        <input value="<?=old_value('email_accountant', $row['email_accountant'])?>" type="email" multiple class="form-control" id="email_accountant" name="email_accountant" placeholder="adres@firma.pl (kilka : przecinek)">
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-4">
                                        <label class="form-label" for="phone_head">
                                            Numer kontaktowy
                                        </label>
                                        <input value="<?=old_value('phone_head', $row['phone_head'])?>" type="text" class="form-control" id="phone_head" name="phone_head" placeholder="+48 123456789">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="phone_alt">
                                            Numer kontaktowy pomocniczy
                                        </label>
                                        <input value="<?=old_value('phone_alt', $row['phone_alt'])?>" type="text" class="form-control" id="phone_alt" name="phone_alt" placeholder="+48 123456789">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label" for="communication_lang">
                                            Język komunikacji (dokumenty, e-maile, SMS)
                                        </label>
                                        <select class="form-select" id="communication_lang" name="communication_lang">
                                            <?php foreach (['fr' => 'Francuski', 'nl' => 'Niderlandzki', 'pl' => 'Polski', 'en' => 'Angielski'] as $code => $label): ?>
                                                <option value="<?= $code ?>" <?= old_select('communication_lang', $code, $row['communication_lang'] ?? 'fr') ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php $space = $space ?? []; $spaceValue = fn(string $key, $current) => isset($_POST[$key]) ? $_POST[$key] : $current; ?>
                        <div class="row mt-4" id="client-options">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <div class="border rounded-3 p-3">
                                    <h6 class="text-uppercase mb-3" style="color:#8D1D2C;letter-spacing:.04em">Opcje klienta</h6>
                                    <div class="d-flex justify-content-between align-items-center gap-3 py-2 border-bottom">
                                        <label for="space_docs"><strong>Strona klienta : dokumenty</strong><br><span class="text-muted small">Wszystkie dokumenty klienta w jednym miejscu (proformy, faktury WDT, faktury, WZ, CMR, różnice, cennik).</span></label>
                                        <div class="form-check form-switch m-0"><input type="hidden" name="space_docs" value="0"><input class="form-check-input" type="checkbox" role="switch" id="space_docs" name="space_docs" value="1" <?= $spaceValue('space_docs', !empty($space['docs']) ? '1' : '0') === '1' ? 'checked' : '' ?>></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-3 py-2 border-bottom">
                                        <label for="space_goal"><strong>Cel obrotu (franczyza) : zakupy × współczynnik</strong><br><span class="text-muted small">Obrót brutto, który sklep powinien osiągnąć z towaru kupionego w SL Food.</span></label>
                                        <div class="form-check form-switch m-0"><input type="hidden" name="space_goal" value="0"><input class="form-check-input" type="checkbox" role="switch" id="space_goal" name="space_goal" value="1" <?= $spaceValue('space_goal', !empty($space['goal']) ? '1' : '0') === '1' ? 'checked' : '' ?>></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-3 py-2 border-bottom">
                                        <label for="space_coefficient"><strong>Współczynnik sprzedaży klienta</strong><br><span class="text-muted small">Puste = domyślny z Ustawień (× <?= number_format((float) ($space['default_coefficient'] ?? 2.5), 2, ',', '') ?>). Współczynnik kategorii ma pierwszeństwo.</span></label>
                                        <div class="d-flex align-items-center gap-2"><span class="text-muted">×</span><input class="form-control" style="width:90px" id="space_coefficient" name="space_coefficient" inputmode="decimal" placeholder="<?= number_format((float) ($space['default_coefficient'] ?? 2.5), 2, ',', '') ?>" value="<?= htmlspecialchars((string) $spaceValue('space_coefficient', isset($space['coefficient']) && $space['coefficient'] !== null ? number_format((float) $space['coefficient'], 2, ',', '') : '')) ?>"></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-3 py-2 border-bottom">
                                        <label for="space_open_days"><strong>Dni otwarcia sklepu w miesiącu</strong><br><span class="text-muted small">Do celu dziennego (obrót ÷ dni). Puste = <?= \App\Services\Client\ClientSpaceService::DEFAULT_OPEN_DAYS ?>.</span></label>
                                        <input class="form-control" style="width:90px" id="space_open_days" name="space_open_days" inputmode="numeric" placeholder="<?= \App\Services\Client\ClientSpaceService::DEFAULT_OPEN_DAYS ?>" value="<?= htmlspecialchars((string) $spaceValue('space_open_days', $space['open_days'] ?? '')) ?>">
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-3 pt-2">
                                        <span class="text-muted small">Strona klienta : przycisk tutaj i w menu ⋯ na liście klientów.</span>
                                        <?php if (!empty($space['docs']) || !empty($space['goal'])): ?><a class="btn btn-sm btn-outline-primary" href="<?= ROOT ?>/clients/<?= (int) $row['id'] ?>/space">Strona klienta →</a><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php $delivery = $delivery_info ?? null; ?>
                        <?php if ($delivery !== null): ?>
                        <div class="row mt-4" id="delivery-info">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <div class="border rounded-3 p-3">
                                    <h6 class="text-uppercase mb-1" style="color:#8D1D2C;letter-spacing:.04em"><i class="bi bi-shield-lock"></i> Dostawa : instrukcje dla kierowcy</h6>
                                    <p class="text-muted small mb-3">Inne u każdego klienta. Pokazywane kierowcy na tablecie przy tym kliencie (alarm i klucz dopiero po dotknięciu). Zapis zaszyfrowany ; nigdy w dokumentach dla klienta.
                                        <?php if (!empty($delivery['updated_at'])): ?>Ostatnia zmiana : <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $delivery['updated_at']))) ?><?= !empty($delivery['updated_by']) ? ', ' . htmlspecialchars((string) $delivery['updated_by']) : '' ?>.<?php endif; ?></p>
                                    <?php if (!empty($delivery['unreadable'])): ?><div class="alert alert-warning py-2 small">Zapisanych instrukcji nie można odczytać (zmieniony sekret serwera) — wpisz je ponownie.</div><?php endif; ?>
                                    <?php foreach (\App\Services\Logistics\CustomerDeliveryInfoService::FIELDS as $key => [$label, $labelFr, $max, $secret]): ?>
                                        <?php $value = isset($_POST['delivery_' . $key]) ? (string) $_POST['delivery_' . $key] : (string) ($delivery['fields'][$key] ?? ''); ?>
                                        <div class="mb-2">
                                            <label class="form-label mb-1" for="delivery_<?= $key ?>"><?= htmlspecialchars($label) ?><?= $secret ? ' <i class="bi bi-lock" title="poufne"></i>' : '' ?> <span class="text-muted small">· <?= htmlspecialchars($labelFr) ?></span></label>
                                            <textarea class="form-control" id="delivery_<?= $key ?>" name="delivery_<?= $key ?>" rows="<?= $key === 'procedure' ? 3 : 2 ?>" maxlength="<?= (int) $max ?>" autocomplete="off"><?= htmlspecialchars($value) ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-warning">Zapisz</button>
                                <a href="<?=ROOT?>/clients" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="row">
                        <div class="col-4"></div>
                        <div class="col-4">
                            <div class="alert alert-danger text-center">Klient nie istnieje!</div>
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <a href="<?=ROOT?>/clients" role="button" class="btn btn-secondary">Powrót</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>
<script src="<?=ROOT?>/assets/js/client-vies-lookup.js"></script>
