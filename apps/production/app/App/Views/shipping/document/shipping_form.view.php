<?php
$this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Dokumenty wysyłki</h3>
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
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h4 class="card-title mb-0">Generowanie dokumentu WZ, CRM, WDT</h4>
                <div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/shipping/wz">Archiwum WZ</a><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/shipping/cmr">Archiwum CMR</a><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/shipping/wdt">Archiwum WDT</a><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/shipping/difference">Archiwum różnic</a></div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-3"></div>
                    <div class="col-6">
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label" for="shipping_date">
                                    Data wysyłki
                                </label>
                                <select class="form-select" name="shipping_date" id="shipping_date"
                                        onchange="getClientsByShippingDate(this.value)">
                                    <option value="-1">Wybierz</option>
                                    <?php if (!empty($dates)): ?>
                                        <?php foreach ($dates as $date): ?>
                                            <option value="<?= $date['delivery_date'] ?>" <?= old_select('delivery_date', $date['delivery_date']) ?> ><?= $date['delivery_date'] ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="id_customer">
                                    Klient
                                </label>
                                <select class="form-select" name="id_customer" id="id_customer" disabled>

                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-6">
                                <label class="form-label" for="id_carrier">
                                    Przewoźnik
                                </label>
                                <select class="form-select" name="id_carrier" id="id_carrier"
                                        onchange="getVehiclesByCarrier(this.value, 'id_carrier_vehicle')">
                                    <option value="-1">Wybierz</option>
                                    <?php if (!empty($carriers)): ?>
                                        <?php foreach ($carriers as $carrier): ?>
                                            <option value="<?= $carrier['id'] ?>" <?= old_select('id_carrier', $carrier['id']) ?> ><?= $carrier['name'] ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_vehicle">
                                    Pojazd
                                </label>
                                <select class="form-select" name="id_carrier_vehicle" id="id_carrier_vehicle" disabled>

                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_sec">
                                    Przewoźnik dodatkowy
                                </label>
                                <select class="form-select" name="id_carrier_sec" id="id_carrier_sec"
                                        onchange="getVehiclesByCarrier(this.value, 'id_carrier_sec_vehicle')">
                                    <option value="-1">Wybierz</option>
                                    <?php if (!empty($carriers)): ?>
                                        <?php foreach ($carriers as $carrier): ?>
                                            <option value="<?= $carrier['id'] ?>" <?= old_select('id_carrier_sec', $carrier['id']) ?> ><?= $carrier['name'] ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_sec_vehicle">
                                    Pojazd
                                </label>
                                <select class="form-select" name="id_carrier_sec_vehicle" id="id_carrier_sec_vehicle"
                                        disabled>

                                </select>
                            </div>
                            <input type="hidden" name="shipping" value="1">
                        </div>
                    </div>
                </div>
                <div class="row mt-4" id="pending-products-panel" style="display:none;">
                    <div class="col-3"></div>
                    <div class="col-6">
                        <div class="card border" id="pending-products-card">
                            <div class="card-header d-flex align-items-center justify-content-between py-2">
                                <span class="fw-semibold">Podgląd skojarzenia palet z zamówieniem</span>
                                <span id="pending-products-badge" class="badge"></span>
                            </div>
                            <div class="card-body p-0">
                                <table class="table table-sm mb-0" id="pending-products-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Produkt</th>
                                            <th class="text-center">Zamówione</th>
                                            <th class="text-center">Zeskanowane</th>
                                            <th class="text-center">Różnica</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pending-products-tbody"></tbody>
                                </table>
                            </div>
                            <div class="card-footer py-1" id="pending-products-footer" style="display:none;"></div>
                        </div>
                    </div>
                </div>

                <div class="row mt-3" id="other-dates-alert" style="display:none;">
                    <div class="col-3"></div>
                    <div class="col-6">
                        <div class="alert alert-warning d-flex gap-2 mb-0" role="alert">
                            <i class="bi bi-calendar-exclamation-fill fs-5 flex-shrink-0"></i>
                            <div>
                                <strong>Uwaga — inne aktywne zamówienia tego klienta:</strong>
                                <div id="other-dates-list" class="mt-1"></div>
                                <div class="mt-1 text-muted small">Upewnij się, że daty wszystkich zamówień wyjeżdżających dzisiaj zostały poprawnie ustawione przed wygenerowaniem dokumentów.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-3"></div>
                    <div class="col-6">
                        <button class="btn btn-success" id="btn-generate-shipping"
                                onclick="saveShippingAndGetDocuments(this)" type="button" disabled>
                            Generuj komplet dokumentów
                        </button>
                        <small class="text-muted ms-2" id="btn-generate-shipping-hint">
                            Wybierz datę wysyłki i klienta, aby aktywować przycisk.
                        </small>
                    </div>
                </div>

            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h4 class="card-title mb-0">Generowanie dokumentu WZ, CRM dla zwrotów</h4>
                <a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/returns">Archiwum zwrotów</a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-3"></div>
                    <div class="col-6">
                        <div class="row">
                            <div class="col-6">
                                <label class="form-label" for="shipping_date_complaint">
                                    Data wysyłki
                                </label>
                                <select class="form-select" name="shipping_date" id="shipping_date_complaint"
                                        onchange="getComplaintClientByDate(this.value)">
                                    <option value="-1">Wybierz</option>
                                    <?php if (!empty($complaint_dates)): ?>
                                        <?php foreach ($complaint_dates as $date): ?>
                                            <option value="<?= $date['shipping_date'] ?>" <?= old_select('shipping_date', $date['shipping_date']) ?> ><?= $date['shipping_date'] ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="complaint_customer_select">
                                    Klient
                                </label>
                                <select class="form-select" name="complaint_customer_select"
                                        id="complaint_customer_select" disabled>

                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_complaint">
                                    Przewoźnik
                                </label>
                                <select class="form-select" name="id_carrier_complaint" id="id_carrier_complaint"
                                        onchange="getVehiclesByCarrier(this.value, 'id_carrier_vehicle_complaint')">
                                    <option value="-1">Wybierz</option>
                                    <?php if (!empty($carriers)): ?>
                                        <?php foreach ($carriers as $carrier): ?>
                                            <option value="<?= $carrier['id'] ?>" <?= old_select('id_carrier', $carrier['id']) ?> ><?= $carrier['name'] ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_vehicle_complaint">
                                    Pojazd
                                </label>
                                <select class="form-select" name="id_carrier_vehicle_complaint"
                                        id="id_carrier_vehicle_complaint" disabled>

                                </select>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_sec_complaint">
                                    Przewoźnik dodatkowy
                                </label>
                                <!--                                    <select class="form-select" name="id_carrier_sec" id="id_carrier_sec" onchange="loadData(this, 'vehicle')">-->
                                <select class="form-select" name="id_carrier" id="id_carrier_sec_complaint"
                                        onchange="getVehiclesByCarrier(this.value, 'id_carrier_sec_vehicle_complaint')">
                                    <option value="-1">Wybierz</option>
                                    <?php if (!empty($carriers)): ?>
                                        <?php foreach ($carriers as $carrier): ?>
                                            <option value="<?= $carrier['id'] ?>" <?= old_select('id_carrier_sec', $carrier['id']) ?> ><?= $carrier['name'] ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="id_carrier_sec_vehicle_complaint">
                                    Pojazd
                                </label>
                                <select class="form-select" name="id_carrier_sec_vehicle_complaint"
                                        id="id_carrier_sec_vehicle_complaint" disabled>

                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="complaint" value="1">

                    </div>
                </div>
                <div class="row mt-5">
                    <div class="col-3"></div>
                    <div class="col-6">
                        <button class="btn btn-success" onclick="saveComplaintAndGetDocument(this)"
                                id="id_submit_btn_complaint" name="complaint" type="button">Generuj komplet dokumentów
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>


<?php $this->view("page_components/footer"); ?>

<script type="text/javascript">

    document.addEventListener('DOMContentLoaded', function () {
        const customerSelect = document.getElementById('id_customer');
        customerSelect.addEventListener('change', function () {
            isPendingProducts(this.value);
        });
    });

    function getClientsByShippingDate(shippingDate) {
        api(`/ajax/shipments/clients?shipping_date=${shippingDate}`)
            .then(res => {
                if (res.ok) {
                    const select = document.getElementById('id_customer');
                    select.innerHTML = '';
                    res.data.forEach(client => {
                        const option = document.createElement('option');
                        option.value = client.id_customer;
                        option.textContent = client.company;
                        select.appendChild(option);
                    });
                    select.disabled = false;
                    // Wywołaj isPendingProducts dla aktualnie wybranej (pierwszej) wartości
                    if (select.value) {
                        isPendingProducts(select.value);
                    }
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Błąd',
                    text: 'Wystąpił błąd podczas pobierania klientów.'
                });
            });
    }

    function getVehiclesByCarrier(idCarrier, selectId) {
        api(`/ajax/product-carriers/${idCarrier}/vehicles`)
            .then(res => {
                if (res.ok) {
                    const select = document.getElementById(selectId);
                    select.innerHTML = '';
                    res.data.forEach(vehicle => {
                        const option = document.createElement('option');
                        option.value = vehicle.id;
                        option.textContent = vehicle.regis_num;
                        select.appendChild(option);
                    });
                    select.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Błąd',
                    text: 'Wystąpił błąd podczas pobierania pojazdów.'
                });
            });
    }

    function isPendingProducts(clientId) {
        const shipping_date = document.getElementById('shipping_date').value;
        const panel         = document.getElementById('pending-products-panel');
        const tbody         = document.getElementById('pending-products-tbody');
        const badge         = document.getElementById('pending-products-badge');
        const footer        = document.getElementById('pending-products-footer');
        const card          = document.getElementById('pending-products-card');
        const btn           = document.getElementById('btn-generate-shipping');
        const hint          = document.getElementById('btn-generate-shipping-hint');

        // Ukryj panel i zablokuj przycisk do czasu odpowiedzi
        panel.style.display = 'none';
        btn.disabled = true;
        hint.textContent = 'Trwa sprawdzanie palet…';

        if (!clientId || shipping_date === '-1' || shipping_date === '') {
            hint.textContent = 'Wybierz datę wysyłki i klienta, aby aktywować przycisk.';
            document.getElementById('other-dates-alert').style.display = 'none';
            return;
        }

        api(`/ajax/shipments/pending-products?client_id=${clientId}&shipping_date=${shipping_date}`)
            .then(res => {
                const products   = res.data?.products   ?? [];
                const hasPallets = res.data?.has_pallets ?? false;
                const allOk      = res.data?.all_complete ?? false;
                const otherDates = res.data?.other_dates  ?? [];

                // Wyczyść tabelę
                tbody.innerHTML = '';

                if (!hasPallets) {
                    // Brak palet – pokaż ostrzeżenie, przycisk zablokowany
                    card.className = 'card border border-warning';
                    badge.className = 'badge bg-warning text-dark';
                    badge.textContent = 'Brak palet';
                    footer.style.display = 'block';
                    footer.className = 'card-footer py-2 bg-warning-subtle text-warning-emphasis';
                    footer.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> Nie znaleziono palet dla wybranego klienta i daty wysyłki.';
                    panel.style.display = '';
                    document.getElementById('other-dates-alert').style.display = 'none';
                    btn.disabled = true;
                    hint.textContent = 'Brak skojarzonych palet – generowanie zablokowane.';
                    return;
                }

                // Wypełnij tabelę
                products.forEach(p => {
                    // quantity_diff = ordered - scanned
                    // diff > 0 → za mało zeskanowanych (brak)
                    // diff < 0 → za dużo zeskanowanych (nadmiar)
                    // diff = 0 → OK
                    const diff   = parseInt(p.quantity_diff);
                    const rowCls = diff < 0 ? 'table-warning' : (diff > 0 ? 'table-danger' : 'table-success');
                    let diffLabel;
                    if (diff === 0) {
                        diffLabel = '<span class="text-success fw-bold">OK</span>';
                    } else if (diff < 0) {
                        // nadmiar zeskanowanych – pokazuj jako "+X"
                        diffLabel = `<span class="fw-bold text-warning">+${Math.abs(diff)}</span>`;
                    } else {
                        // brak skanów – pokazuj jako "-X"
                        diffLabel = `<span class="fw-bold text-danger">-${diff}</span>`;
                    }

                    tbody.insertAdjacentHTML('beforeend', `
                        <tr class="${rowCls}">
                            <td>${p.product_name}</td>
                            <td class="text-center">${p.quantity_ordered}</td>
                            <td class="text-center">${p.quantity_scanned}</td>
                            <td class="text-center">${diffLabel}</td>
                        </tr>`);
                });

                if (allOk) {
                    card.className = 'card border border-success';
                    badge.className = 'badge bg-success';
                    badge.textContent = 'Kompletne';
                    footer.style.display = 'block';
                    footer.className = 'card-footer py-2 bg-success-subtle text-success-emphasis';
                    footer.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Wszystkie produkty są zeskanowane. Można generować dokumenty.';
                } else {
                    const missing = products.filter(p => parseInt(p.quantity_diff) > 0);
                    const extra   = products.filter(p => parseInt(p.quantity_diff) < 0);
                    let msg = [];
                    if (missing.length) msg.push(`${missing.length} produkt(ów) ma za mało zeskanowanych kartonów`);
                    if (extra.length)   msg.push(`${extra.length} produkt(ów) ma nadmiar zeskanowanych kartonów`);

                    card.className = 'card border border-warning';
                    badge.className = 'badge bg-warning text-dark';
                    badge.textContent = 'Rozbieżności';
                    footer.style.display = 'block';
                    footer.className = 'card-footer py-2 bg-warning-subtle text-warning-emphasis';
                    footer.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1"></i> ${msg.join('; ')}. Sprawdź skany przed wygenerowaniem dokumentów.`;
                }

                // Przycisk zawsze aktywny gdy istnieją palety – użytkownik decyduje sam
                btn.disabled = false;
                hint.textContent = '';

                // Inne aktywne daty zamówień klienta
                const otherDatesAlert = document.getElementById('other-dates-alert');
                const otherDatesList  = document.getElementById('other-dates-list');
                if (otherDates.length > 0) {
                    otherDatesList.innerHTML = otherDates
                        .map(d => `<span class="badge bg-warning text-dark me-1">${d.shipping_date}</span> <span class="small">(${d.order_count} zamówienie/ń)</span>`)
                        .join('<br>');
                    otherDatesAlert.style.display = '';
                } else {
                    otherDatesAlert.style.display = 'none';
                }

                panel.style.display = '';
            })
            .catch(err => {
                console.error(err);
                hint.textContent = 'Błąd podczas sprawdzania palet.';
            });
    }

    function getComplaintClientByDate(date) {
        const select = document.getElementById('complaint_customer_select');
        select.innerHTML = "";

        const promise = api(`/ajax/complaints/clients?date=${date}`);
        promise.then(function (res) {

            if (res.data.length == 0) {
                return false;
            }

            for (let i = 0; i < res.data.length; i++) {
                var opt = document.createElement('option');
                opt.value = res.data[i].id_customer;
                opt.innerHTML = res.data[i].company;
                select.appendChild(opt);
            }
            //
            // else if (type == 'vehicle') {
            //     console.log(res.data);
            //     for (let i = 0; i < res.data.length; i++) {
            //         var opt = document.createElement('option');
            //         opt.value = res.data[i].id;
            //         opt.innerHTML = res.data[i].regis_num;
            //         filledSelect.appendChild(opt);
            //     }
            // }
            select.disabled = false;

        })
    }

    function loadData(select, type) {
        const value = select.value;
        let filledSelect = select.parentNode.parentNode.getElementsByTagName("select")[1];
        filledSelect.innerHTML = "";

        if (value == "-1") {
            filledSelect.disabled = true;
            return;
        }

        const data = {
            "value": value
        }

        const promise = api(`/ajax/complaints/clients`, "data=" + JSON.stringify(data));
        promise.then(function (res) {
            if (!res.success || res.data.length == 0) {
                return false;
            }

            if (type == 'customer' || type == 'complaint_customer') {
                for (let i = 0; i < res.data.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = res.data[i].id_customer;
                    opt.innerHTML = res.data[i].company;
                    filledSelect.appendChild(opt);
                }
            } else if (type == 'vehicle') {
                console.log(res.data);
                for (let i = 0; i < res.data.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = res.data[i].id;
                    opt.innerHTML = res.data[i].regis_num;
                    filledSelect.appendChild(opt);
                }
            }
            filledSelect.disabled = false;

        })

    }

    async function saveShippingAndGetDocuments(btn) {
        const select_date = document.getElementById('shipping_date');
        btn.disabled = true;

        if (select_date.value == "-1") {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: "Wybierz datę wysyłki i klienta"
            });
            btn.disabled = false;
            return;
        }

        const select_carrier = document.getElementById('id_carrier');
        if (select_carrier.value == "-1") {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: "Wybierz przewoźnika"
            });
            btn.disabled = false;
            return;
        }

        const data = {
            "shipping_date": select_date.value,
            "id_customer": document.getElementById('id_customer').value,
            "id_carrier": select_carrier.value,
            "id_carrier_vehicle": document.getElementById('id_carrier_vehicle').value,
            "id_carrier_sec": document.getElementById('id_carrier_sec').value,
            "id_carrier_sec_vehicle": document.getElementById('id_carrier_sec_vehicle').value
        };

        // Zapisz wysyłkę
        const res = await api(`/ajax/shipments`, {
            method: "POST",
            data
        });

        if (res) {
            Swal.fire({
                icon: "success",
                title: "Sukces",
                html: "Dokumenty zostały wygenerowane"
            });

            console.log(res);

            // Pobierz dokumenty, przekazując ID nowej wysyłki
            await downloadPDF(`/ajax/shipments/${res.id}/documents/grouped`);

            // Odśwież stronę po zakończeniu
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: res.message || "Wystąpił błąd podczas generowania dokumentów"
            });
            btn.disabled = false;
        }
    }


    async function saveComplaintAndGetDocument(btn) {
        const select_date = document.getElementById('shipping_date_complaint');
        btn.disabled = true;

        if (select_date.value == "-1") {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: "Wybierz datę wysyłki i klienta dla zwrotu"
            });
            btn.disabled = false;
            return;
        }

        const select_carrier = document.getElementById('id_carrier_complaint');
        if (select_carrier.value == "-1") {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: "Wybierz przewoźnika dla zwrotu"
            });
            btn.disabled = false;
            return;
        }

        const data = {
            "shipping_date": select_date.value,
            "id_customer": document.getElementById('complaint_customer_select').value,
            "id_carrier": select_carrier.value,
            "id_carrier_vehicle": document.getElementById('id_carrier_vehicle_complaint').value,
            "id_carrier_sec": document.getElementById('id_carrier_sec_complaint').value,
            "id_carrier_sec_vehicle": document.getElementById('id_carrier_sec_vehicle_complaint').value
        };

        // Zapisz wysyłkę
        const res = await api(`/ajax/complaints`, {
            method: "POST",
            data
        });

        if (res) {
            Swal.fire({
                icon: "success",
                title: "Sukces",
                html: "Dokumenty zostały wygenerowane"
            });


            // Pobierz dokumenty, przekazując ID nowej wysyłki
            await downloadPDF(`/ajax/complaints/${res.id}/documents/grouped`);

            // Odśwież stronę po zakończeniu
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: res.message || "Wystąpił błąd podczas generowania dokumentów"
            });
            btn.disabled = false;
        }
    }


    function validateComplaint() {
        const select_date = document.getElementById('shipping_date_complaint');
        if (select_date.value == "-1") {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: "Wybierz datę wysyłki i klienta"
            })
            return;
        }

        const select_carrier = document.getElementById('id_carrier_complaint');
        if (select_carrier.value == "-1") {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: "Wybierz przewoźnika"
            })
            return;
        }

        document.getElementById('id_form_complaint').submit();
        document.getElementById('id_submit_btn_complaint').disabled = true;
    }


    function savePrice(id_product, id_doc, btn) {
        console.log(btn.parentNode.parentNode.children)
        const data = {
            "id_product": id_product,
            "price_carton": btn.parentNode.parentNode.children[2].children[0].value,
            "id_document_wdt": id_doc
        }

        const promise = getJSON(validAjaxUrl() + "ajax/wdt_price/update", "data=" + JSON.stringify(data));
        promise.then(function (res) {
            showSnack(res);
            if (res) {
                disableUpdate(btn);
            }
        })
    }

    function enableUpdate(btn) {
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let saveBtn = btn.parentNode.children[1];
        btn.style.display = "none";
        saveBtn.style.display = "inline-block";
        previousColInput.disabled = false;
    }

    function disableUpdate(btn) {
        let previousColInput = btn.parentNode.previousSibling.previousSibling.children[0];
        let enableBtn = btn.parentNode.children[0];
        btn.style.display = "none";
        enableBtn.style.display = "inline-block";
        previousCol.disabled = true;
    }

</script>
