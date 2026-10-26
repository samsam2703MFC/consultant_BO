<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3 class="mb-0">Zamówienia</h3>
                <p class="text-subtitle text-muted mb-0">Lista oczekujących zamówień klientów</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Zamówienia</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">

            <!-- Card header: tytuł + akcje -->
            <div class="card-header d-flex flex-wrap align-items-center gap-2 justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-box-seam fs-5 text-primary"></i>
                    <h4 class="card-title mb-0">Oczekujące zamówienia</h4>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-primary btn-sm" href="<?= ROOT ?>/client-orders/archive">
                        <i class="bi bi-archive me-1"></i>Archiwum zamówień
                    </a>
                    <a class="btn btn-outline-info btn-sm" href="<?= ROOT ?>/archive/documents/proformas"><i class="bi bi-receipt me-1"></i>Archiwum proform</a>
                    <a class="btn btn-outline-info btn-sm" href="<?= ROOT ?>/archive/documents/picking-lists"><i class="bi bi-card-checklist me-1"></i>Archiwum list</a>
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="loadOrdersFromFranchise()">
                        <i class="bi bi-cloud-download me-1"></i>Pobierz zamówienia
                    </button>
                    <a class="btn btn-primary btn-sm" href="<?= ROOT ?>/client-orders/new">
                        <i class="bi bi-plus-lg me-1"></i>Nowe zamówienie
                    </a>
                </div>
            </div>

            <div class="card-body px-0 pb-0">
                <form method="POST">

                    <?php if (!empty($errors)): ?>
                        <div class="mx-3 mb-3">
                            <div class="alert alert-danger d-flex gap-2 align-items-start mb-0" role="alert">
                                <i class="bi bi-x-circle-fill flex-shrink-0 mt-1"></i>
                                <div><?= implode("<br>", $errors) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Alerty informacyjne -->
                    <div class="mx-3">
                        <?php if (!empty(array_filter($pending ?? [], fn($o) => !empty($o['is_freebie'])))): ?>
                            <div class="alert alert-warning d-flex gap-2 align-items-start mb-3" role="alert">
                                <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
                                <div>
                                    <strong>Uwaga — zamówienia gratisowe</strong><br>
                                    Na liście znajdują się zamówienia oznaczone jako <span class="badge bg-success"><i class="bi bi-gift-fill me-1"></i>GRATIS</span>.
                                    Produkty z tych zamówień <strong>nie powinny być skanowane w procesie kompletacji</strong> —
                                    są one wyłączone z dokumentu różnic i nie podlegają standardowej weryfikacji ilościowej.
                                    <?php if (!empty(array_filter($pending, fn($o) => !empty($o['is_freebie']) && ($o['order_type'] ?? 'STANDARD') === 'SUPPLEMENT'))): ?>
                                        <br>Niektóre zamówienia gratisowe są jednocześnie <span class="badge bg-warning text-dark"><i class="bi bi-plus-circle-fill me-1"></i>DOMÓWIENIEM</span> —
                                        zostały dołączone przez klienta po złożeniu głównego zamówienia.
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($batchCounts)): ?>
                            <div class="alert alert-info d-flex gap-2 align-items-start mb-3" role="alert">
                                <i class="bi bi-diagram-2-fill fs-5 flex-shrink-0 mt-1"></i>
                                <div>
                                    <strong>Zamówienia podzielone</strong><br>
                                    Na liście znajdują się zamówienia będące częścią zamówień podzielonych
                                    (oznaczone jako <span class="badge bg-info text-dark"><i class="bi bi-diagram-2 me-1"></i>Część X / Y</span>).
                                    Każda część jest osobnym zamówieniem i powinna być kompletowana oraz wysyłana niezależnie.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tabela zamówień -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: .875rem;">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:3%" class="ps-3">
                                        <input type="checkbox" class="form-check-input" onClick="togglePending(this)" checked>
                                    </th>
                                    <th style="width:25%">Firma</th>
                                    <th style="width:23%">Opis</th>
                                    <th style="width:10%">Data zamówienia</th>
                                    <th style="width:10%">Wysyłka</th>
                                    <th style="width:7%" class="text-end">Waga</th>
                                    <th style="width:10%" class="text-center">Proforma</th>
                                    <th style="width:12%" class="text-end pe-3">Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($pending)): ?>
                                <?php for ($i = 0; $i < count($pending); $i++): ?>

                                    <?php if ($pending[$i]['expected_shipping_date'] != @$pending[$i - 1]['expected_shipping_date'] || $i == 0): ?>
                                        <tr class="table-secondary">
                                            <td colspan="8" class="ps-3 py-2">
                                                <i class="bi bi-calendar-event me-2 text-secondary"></i>
                                                <strong class="text-secondary">
                                                    <?= day_label_pl($pending[$i]['expected_shipping_date']) ?>
                                                </strong>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php
                                        $rowClass  = '';
                                        $rowBorder = '';
                                        if (!empty($pending[$i]['is_freebie'])) {
                                            $rowClass  = 'table-success';
                                            $rowBorder = ($pending[$i]['order_type'] ?? 'STANDARD') === 'SUPPLEMENT'
                                                ? 'border-start border-warning border-3'
                                                : 'border-start border-success border-3';
                                        } elseif (!empty($pending[$i]['order_batch_key'])) {
                                            $rowClass  = 'table-info';
                                            $rowBorder = 'border-start border-info border-3';
                                        }
                                    ?>
                                    <tr class="<?= $rowClass ?> <?= $rowBorder ?>">

                                        <!-- Checkbox -->
                                        <td class="ps-3">
                                            <input type="checkbox" class="form-check-input" name="order[]"
                                                   value="<?= $pending[$i]['id'] ?>" checked>
                                        </td>

                                        <!-- Firma -->
                                        <td>
                                            <?php if (!empty($pending[$i]['is_freebie']) && ($pending[$i]['order_type'] ?? 'STANDARD') === 'SUPPLEMENT'): ?>
                                                <i class="bi bi-gift-fill text-success me-1"></i>
                                                <strong><?= htmlspecialchars($pending[$i]['company']) ?></strong>
                                                <span class="badge bg-success ms-1">GRATIS</span>
                                                <span class="badge bg-warning text-dark ms-1">DOMÓWIENIE</span>
                                            <?php elseif (!empty($pending[$i]['is_freebie'])): ?>
                                                <i class="bi bi-gift-fill text-success me-1"></i>
                                                <strong><?= htmlspecialchars($pending[$i]['company']) ?></strong>
                                                <span class="badge bg-success ms-1">GRATIS</span>
                                            <?php elseif (!empty($pending[$i]['order_batch_key'])): ?>
                                                <i class="bi bi-diagram-2 text-info me-1"></i>
                                                <?= htmlspecialchars($pending[$i]['company']) ?>
                                                <span class="badge bg-info text-dark ms-1"
                                                      title="Klucz grupy: <?= htmlspecialchars($pending[$i]['order_batch_key']) ?>">
                                                    <i class="bi bi-diagram-2 me-1"></i>Część <?= $pending[$i]['batch_index'] ?> / <?= $pending[$i]['batch_total'] ?>
                                                </span>
                                            <?php elseif(($pending[$i]['order_type'] ?? 'STANDARD') === 'SUPPLEMENT'): ?>
                                                <i class="bi bi-plus-circle-fill text-warning me-1"></i>
                                                <?= htmlspecialchars($pending[$i]['company']) ?>
                                                <span class="badge bg-warning text-dark ms-1">DOMÓWIENIE</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($pending[$i]['company']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($pending[$i]['proforma_stale'])): ?>
                                                <span class="badge bg-warning text-dark ms-1" title="Zamówienie zostało zmienione po wystawieniu proformy">Proforma nieaktualna</span>
                                            <?php endif; ?>
                                            <?php if (!empty($pending[$i]['proforma_quantity_difference'])): ?>
                                                <span class="badge bg-danger ms-1" title="Ilości produktów w zamówieniu różnią się od ilości na proformie">
                                                    Różnica ilości względem proformy
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Opis -->
                                        <td class="text-muted">
                                            <?= htmlspecialchars($pending[$i]['order_description'] ?? '') ?>
                                        </td>

                                        <!-- Data zamówienia -->
                                        <td class="text-nowrap text-muted small text-center">
                                            <?= $pending[$i]['create_date'] ?>
                                            <br>
                                            <?= $pending[$i]['create_order_time'] ?>
                                        </td>

                                        <!-- Data wysyłki -->
                                        <td class="text-nowrap small">
                                            <i class="bi bi-truck me-1 text-primary"></i><?= $pending[$i]['expected_shipping_date'] ?>
                                        </td>

                                        <!-- Waga -->
                                        <td class="text-end text-nowrap small">
                                            <span class="badge bg-light text-dark border">
                                                <i class="bi bi-box me-1"></i><?= $pending[$i]['totalSumKg'] ?> kg
                                            </span>
                                        </td>

                                        <?php
                                            $idProforma    = $pending[$i]['id_proforma'];
                                            $proformaPaid  = !is_null($idProforma) && strtolower(trim((string) ($pending[$i]['proforma_status'] ?? ''))) === 'paid';
                                            $proformaStale = !is_null($idProforma) && !$proformaPaid && !empty($pending[$i]['proforma_stale']);
                                            $isSupplement  = ($pending[$i]['order_type'] ?? 'STANDARD') === 'SUPPLEMENT';
                                        ?>
                                        <!-- Proforma: stan; działania w menu „⋯” -->
                                        <td class="text-center">
                                            <?php if (is_null($idProforma)): ?>
                                                <span class="at-pill at-pill-muted">Brak</span>
                                            <?php elseif ($proformaPaid): ?>
                                                <span class="at-pill at-pill-ok">Opłacona</span>
                                            <?php elseif ($proformaStale): ?>
                                                <span class="at-pill at-pill-danger" title="Zamówienie zostało zmienione po wystawieniu proformy">Nieaktualna</span>
                                            <?php else: ?>
                                                <span class="at-pill at-pill-warn">Nieopłacona</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Akcje: jedna główna + menu „⋯” -->
                                        <td class="text-end pe-3">
                                            <div class="at-actions">
                                                <?php if (is_null($pending[$i]['id_wdt'])): ?>
                                                    <a href="<?= ROOT ?>/client-orders/<?= $pending[$i]['id'] ?>/edit" class="btn btn-sm btn-primary">Edytuj</a>
                                                <?php elseif (!is_null($idProforma)): ?>
                                                    <button class="btn btn-sm btn-primary" type="button" onclick="generateProforma(<?= (int) $idProforma ?>)">Proforma</button>
                                                <?php endif; ?>
                                                <div class="at-more">
                                                    <button type="button" class="at-more-toggle" aria-haspopup="menu" aria-expanded="false" aria-label="Więcej akcji" title="Więcej akcji"><i class="bi bi-three-dots"></i></button>
                                                    <div class="at-more-menu" role="menu" hidden>
                                                        <div class="at-more-head">Proforma</div>
                                                        <?php if (is_null($idProforma)): ?>
                                                            <button type="button" role="menuitem" onclick="insertAndGenerateProforma(<?= $pending[$i]['id'] ?>, this)"><i class="bi bi-file-earmark-plus"></i>Wystaw proformę</button>
                                                        <?php else: ?>
                                                            <button type="button" role="menuitem" onclick="generateProforma(<?= (int) $idProforma ?>)"><i class="bi bi-file-earmark-text"></i>Pobierz proformę (PDF)</button>
                                                            <a role="menuitem" href="<?= ROOT ?>/communication/compose/proforma/<?= (int) $idProforma ?>"><i class="bi bi-envelope"></i>Przygotuj e-mail</a>
                                                            <?php if (!$proformaPaid): ?>
                                                                <button type="button" role="menuitem" onclick="markProformaPaid(<?= (int) $idProforma ?>)"><i class="bi bi-check-circle"></i>Oznacz jako opłaconą</button>
                                                            <?php endif; ?>
                                                            <?php if ($proformaStale): ?>
                                                                <button type="button" role="menuitem" onclick="regenerateProforma(<?= (int) $pending[$i]['id'] ?>)"><i class="bi bi-arrow-repeat"></i>Wygeneruj nową proformę</button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <hr>
                                                        <div class="at-more-head">Zamówienie</div>
                                                        <?php if ($isSupplement): ?>
                                                            <button type="button" role="menuitem" onclick="pushSupplement(<?= $pending[$i]['id'] ?>, this)"
                                                                    data-at-confirm="Wysłać domówienie ponownie do panelu klienta?" data-at-confirm-ok="Wyślij">
                                                                <i class="bi bi-box-arrow-up-right"></i>Wyślij domówienie ponownie
                                                                <?php if (($pending[$i]['supplement_push_status'] ?? '') === 'sent'): ?>
                                                                    <span class="at-pill at-pill-ok ms-auto">wysłane</span>
                                                                <?php elseif (($pending[$i]['supplement_push_status'] ?? '') === 'failed'): ?>
                                                                    <span class="at-pill at-pill-danger ms-auto">błąd</span>
                                                                <?php endif; ?>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php /* Wyślij dzień oznacza zamówienia dnia jako wysłane i powiadamia sklep ; tu : wysyłka poza kreatorem (bez WDT). */ ?>
                                                        <button type="button" role="menuitem" onclick="updateStatus(<?= $pending[$i]['id'] ?>, this)"
                                                                data-at-confirm="<?= $pending[$i]['integrated_customer'] == 1 ? 'Oznaczyć zamówienie jako wysłane bez dokumentów? Sklep klienta dostaje powiadomienie tylko z Wyślij dzień (WDT).' : 'Oznaczyć zamówienie jako wysłane?' ?>" data-at-confirm-ok="Oznacz">
                                                            <i class="bi bi-check2-circle"></i>Oznacz jako wysłane
                                                        </button>
                                                        <button type="button" role="menuitem" onclick="archiveOrder(<?= $pending[$i]['id'] ?>, this)"><i class="bi bi-archive"></i>Archiwizuj…</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                    </tr>
                                <?php endfor; ?>

                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>
                                        Brak oczekujących zamówień
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Stopka formularza -->
                    <div class="d-flex align-items-center justify-content-between px-3 py-3 border-top">
                        <span class="text-muted small">
                            Zaznaczone zamówienia zostaną uwzględnione na liście kompletacyjnej.
                        </span>
                        <button class="btn btn-success btn-sm" type="button" onclick="getPickingList()">
                            <i class="bi bi-printer me-1"></i>Lista kompletacyjna [PDF]
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </section>
</div>


<?php $this->view("page_components/footer"); ?>

<script>

    function togglePending(source) {
        checkboxes = document.getElementsByName('order[]');
        for (var i = 0, n = checkboxes.length; i < n; i++) {
            checkboxes[i].checked = source.checked;
        }
    }

    function loadOrdersFromFranchise(){
        Swal.fire({
            title: 'Potwierdzenie',
            text: "Czy na pewno chcesz pobrać nowe zamówienia z systemu franczyzowego?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Tak, pobierz',
            cancelButtonText: 'Anuluj'
        }).then((result) => {
            if (result.isConfirmed) {
                // Pokazanie loadera
                Swal.fire({
                    title: 'Proszę czekać',
                    text: 'Trwa pobieranie zamówień...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading()
                    }
                });

                api(`/ajax/client-orders/fetch-from-franchise`, {
                    method: "POST"
                })
                .then(data => {
                    Swal.close(); // Zamknięcie loadera
                    if (data.ok) {
                        Swal.fire(
                            'Sukces',
                            data.message || 'Zamówienia zostały pomyślnie pobrane.',
                            'success'
                        ).then(() => {
                            location.reload(); // Odświeżenie strony
                        });
                    } else {
                        Swal.fire(
                            'Błąd',
                            data.message || 'Wystąpił błąd podczas pobierania zamówień.',
                            'error'
                        );
                    }
                })
                .catch(error => {
                    Swal.close(); // Zamknięcie loadera
                    Swal.fire(
                        'Błąd',
                        'Wystąpił błąd podczas pobierania zamówień.',
                        'error'
                    );
                    console.error('Error:', error);
                });
            }
        });
    }

    function validateZeroValue(input) {
        if (input.value === "0") {
            input.classList.add("is-invalid")
            console.log("X");
        } else {
            if (input.classList.contains("is-invalid")) input.classList.remove("is-invalid");
        }
    }

    async function insertAndGenerateProforma(idOrder, btn) {

        const confirmResult = await Swal.fire({
            title: "Potwierdzenie",
            text: "Czy na pewno chcesz wygenerować proformę dla tego zamówienia?",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Tak",
            cancelButtonText: "Anuluj"
        });
        console.log(confirmResult);
        if (!confirmResult.isConfirmed) {
            return;
        }

        btn.disabled = true;

        // Zapisz wysyłkę
        const res = await api(`/ajax/client-orders/${idOrder}/proforma`, {
            method: "POST"
        });

        if (res) {
            Swal.fire({
                icon: "success",
                title: "Sukces",
                html: "Proforma została wygenerowana"
            });

            // Pobierz dokumenty, przekazując ID nowej wysyłki
            await downloadPDF(`/ajax/proforma-documents/${res.data}/document`);


            //reload after 1 sec
            await new Promise(resolve => setTimeout(resolve, 1000));
            // Odśwież stronę po zakończeniu
            location.reload();
        } else {
            Swal.fire({
                icon: "error",
                title: "Błąd",
                html: res.message || "Wystąpił błąd podczas generowania proformy"
            });
            btn.disabled = false;
        }
    }

    function generateProforma(idProforma){
        downloadPDF(`/ajax/proforma-documents/${idProforma}/document`);
    }

    async function markProformaPaid(idProforma) {
        const confirmation = await Swal.fire({
            title: 'Oznaczyć proformę jako opłaconą?',
            text: 'Po potwierdzeniu jej pozycje nie będą już synchronizowane z edytowanym zamówieniem.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Tak, płatność otrzymana',
            cancelButtonText: 'Anuluj'
        });
        if (!confirmation.isConfirmed) return;

        const response = await api(`/ajax/proforma-documents/${idProforma}/paid`, { method: 'POST' });
        if (!response.ok) {
            await Swal.fire('Błąd', response.message || 'Nie udało się oznaczyć proformy jako opłaconej.', 'error');
            return;
        }
        await Swal.fire('Zapisano', response.message || 'Proforma została oznaczona jako opłacona.', 'success');
        window.location.reload();
    }

    async function regenerateProforma(idOrder) {
        const confirmation = await Swal.fire({
            title: 'Wygenerować nową proformę?',
            text: 'Obecna nieopłacona proforma zostanie anulowana. Nowy dokument będzie zawierał aktualne pozycje zamówienia.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Tak, wygeneruj',
            cancelButtonText: 'Anuluj'
        });
        if (!confirmation.isConfirmed) return;

        const response = await api(`/ajax/client-orders/${idOrder}/proforma/regenerate`, { method: 'POST' });
        if (!response.ok) {
            await Swal.fire('Błąd', response.message || 'Nie udało się wygenerować nowej proformy.', 'error');
            return;
        }
        await Swal.fire('Zapisano', response.message || 'Nowa proforma została wygenerowana.', 'success');
        window.location.reload();
    }

    function addToTable(btn, input_name) {
        const row = btn.parentNode.parentNode;
        const product_select = row.querySelectorAll("select")[0];
        const id_product = product_select.value;
        const product_name = product_select[product_select.selectedIndex].innerText;
        const quantityInput = row.querySelectorAll("input[type='number']")[0];
        const quantity = quantityInput.value;
        const target_body = row.parentNode.parentNode.querySelectorAll("tbody")[0];

        for (let i = 0; i < target_body.rows.length; i++) {
            if (target_body.rows[i].cells[0].innerText === product_name) {
                return;
            }
        }

        let insertedRow = target_body.insertRow(target_body.rows.length);
        const cell0 = insertedRow.insertCell(0);
        const cell1 = insertedRow.insertCell(1);
        const cell2 = insertedRow.insertCell(2);
        cell0.innerHTML = `${product_name}`
        cell1.innerHTML = `<input type="number" class="form-control" value="${quantity}" name="${input_name}[${id_product}]" onkeyup="validateZeroValue(this)">`
        cell2.innerHTML = `<button class="btn btn-danger" type="button" onclick="removeRow(this)">X</button>`;

        product_select.selectedIndex = 0;
        quantityInput.value = "";
    }

    function removeRow(btn) {
        const row = btn.parentNode.parentNode;
        row.parentNode.removeChild(row);
    }

    async function getPickingList() {
        const checkboxes = document.querySelectorAll('input[name="order[]"]:checked');
        if (checkboxes.length === 0) {
            showSnack("Nie wybrano żadnego zamówienia do wygenerowania PDF");
            return;
        }

        const ids = Array.from(checkboxes).map(checkbox => checkbox.value);


        downloadPDF(
            `/ajax/client-orders/picking-list`,
            ids,
            "orders.pdf",
            "POST"
        )
    }

    function updateStatus(id_order, btn) {
        const data = {
            "id_order": id_order,
            "status": 3
        }

        api(`/ajax/client-orders/${id_order}`, {
            method: 'PATCH',
            data
        })
            .then(data => {
                showSnack(data.ok)
                if (data.ok) {
                    btn.closest('tr')?.remove();
                }
            })
            .catch(error => console.error('Error:', error));

    }

    async function archiveOrder(idOrder, button) {
        const result = await Swal.fire({
            title: 'Archiwizacja zamówienia',
            text: 'Czy zamówienie zostało wysłane do klienta?',
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Tak, wysłano',
            denyButtonText: 'Nie',
            cancelButtonText: 'Anuluj'
        });
        if (!result.isConfirmed && !result.isDenied) return;

        const status = result.isConfirmed
            ? <?= (int) $orderStatusCodes['in_transit'] ?>
            : <?= (int) $orderStatusCodes['archived'] ?>;
        button.disabled = true;
        try {
            const response = await api(`/ajax/client-orders/${idOrder}`, {
                method: 'PATCH',
                data: { id_order: idOrder, status }
            });
            if (response.ok) {
                button.closest('tr')?.remove();
                showSnack(true, status === 3 ? 'Zamówienie oznaczono jako wysłane.' : 'Zamówienie zarchiwizowano.');
                return;
            }
            showSnack(false, response.error || response.message || 'Nie udało się zarchiwizować zamówienia.');
        } catch (error) {
            console.error('Archive order error:', error);
            showSnack(false, 'Nie udało się zarchiwizować zamówienia.');
        } finally {
            button.disabled = false;
        }
    }


    function pushSupplement(id_order, btn) {
        btn.disabled = true;
        api(`/ajax/client-orders/${id_order}/push-supplement`, {
            method: 'POST',
            data: {id_order}
        })
        .then(data => {
            showSnack(data.ok, data.ok ? null : data.error);
            btn.disabled = false;
        })
        .catch(err => { showSnack(false, err.error); btn.disabled = false; });
    }

</script>
