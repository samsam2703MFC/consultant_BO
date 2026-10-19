<?php $this->view("page_components/header"); ?>
<style>
    /* Wyrównanie wysokości opisów pod polami, żeby inputy były w jednej linii */
    .hint-spacer{
        min-height: 16px;      /* rezerwuje miejsce */
        line-height: 16px;
        margin-top: 4px;       /* jak standardowe form-text */
    }
</style>
<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Nowa dostawa / zakup</h3>
                <p class="text-subtitle text-muted">Szybkie dodawanie pozycji i zapis w modelu: purchase_document + purchase_item.</p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">
                <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="<?= ROOT ?>/purchases">Zakupy</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Nowa dostawa</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <section class="section">

        <!-- Nagłówek dokumentu -->
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Dane dokumentu</h4>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end">

                    <div class="col-12 col-md-4">
                        <label class="form-label">Dostawca</label>
                        <select id="supplier_id" class="choices form-select">
                            <option value="">— wybierz —</option>
                            <?php foreach (($suppliers ?? []) as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label">Data</label>
                        <input id="doc_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label">Waluta</label>
                        <select id="currency_code" class="form-select">
                            <option value="PLN" selected>PLN</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-2">
                        <label class="form-label">Nr dokumentu (opc.)</label>
                        <input id="doc_no" type="text" class="form-control" placeholder="FV/123/2026">
                    </div>

                    <div class="col-12 col-md-2">
                        <label class="form-label">Status</label>
                        <select id="status" class="form-select">
                            <option value="POSTED" selected>Zatwierdź od razu</option>
                        </select>
                    </div>

                    <div class="col-12 mt-3">
                        <label class="form-label">Notatka (opc.)</label>
                        <input id="note" type="text" class="form-control" placeholder="np. dostawa na produkcję / korekta cen">
                    </div>

                </div>
            </div>
        </div>

        <!-- Szybkie dodawanie pozycji -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h4 class="card-title mb-0">Pozycje zakupu</h4>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="clearItems()">
                        Wyczyść pozycje
                    </button>
                    <button class="btn btn-primary btn-sm" type="button" onclick="savePurchase()">
                        Zapisz dostawę
                    </button>
                </div>
            </div>

            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                <?php endif; ?>

                <!-- Pasek szybkiego dodawania (czytelny układ 2-rzędowy) -->
                <div class="border rounded p-3">

                    <!-- RZĄD 1: surowiec + tryb ceny -->
                    <div class="row g-2 align-items-start">
                        <div class="col-12 col-lg-9">
                            <label class="form-label">Surowiec</label>
                            <select id="raw_material_id" class="form-select choices">
                                <option value="">— wybierz —</option>
                                <?php foreach (($raw_materials ?? []) as $rm): ?>
                                    <option
                                            value="<?= (int)$rm['id'] ?>"
                                            data-unit="<?= htmlspecialchars($rm['unit_name'] ?? '') ?>"
                                            data-pack="<?= htmlspecialchars($rm['package_size_in_base_unit'] ?? '') ?>"
                                            data-vat="<?= htmlspecialchars($rm['vat_rate'] ?? '8.00') ?>"
                                    >
                                        <?= htmlspecialchars($rm['name']) ?>
                                        <?php if (!empty($rm['unit_name'])): ?> (<?= htmlspecialchars($rm['unit_name']) ?>)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text hint-spacer">Wpisz w wyszukiwarkę i wybierz Enterem / kliknięciem.</div>
                        </div>

                        <div class="col-12 col-lg-3">
                            <label class="form-label">Tryb ceny</label>
                            <select id="price_mode" class="form-select">
                                <option value="PACK" selected>Za opakowanie</option>
                                <option value="UNIT">Za jednostkę bazową</option>
                            </select>
                            <div class="form-text hint-spacer">&nbsp;</div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <!-- RZĄD 2: parametry pozycji -->
                    <div class="row g-2 align-items-start">
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label" id="qty_label">Ilość (bazowa)</label>
                            <input id="qty_base" type="number" step="0.001" class="form-control" placeholder="np. 1000">
                            <div class="form-text hint-spacer" id="qty_hint">g / szt — wg surowca</div>
                        </div>

                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label">VAT %</label>
                            <input id="vat_rate" type="number" step="0.01" class="form-control" placeholder="np. 8">
                            <div class="form-text hint-spacer">&nbsp;</div>
                        </div>

                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Cena netto</label>
                            <input id="price_net" type="number" step="0.000001" class="form-control" placeholder="np. 12.50">
                            <div class="form-text hint-spacer" id="price_hint">
                                Cena netto za opakowanie. System przeliczy na cenę za 1 jednostkę bazową.
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-lg-3" id="pack_size_wrap">
                            <label class="form-label">Rozmiar opakowania (bazowa)</label>
                            <input id="package_size_base" type="number" step="0.001" class="form-control" placeholder="np. 1000">
                            <div class="form-text hint-spacer">Wymagane przy “Za opakowanie”.</div>
                        </div>

                        <div class="col-12 col-lg-2 d-grid">
                            <label class="form-label d-none d-lg-block">&nbsp;</label>
                            <button class="btn btn-success" type="button" onclick="addItemFast()">
                                + Dodaj pozycję
                            </button>
                            <div class="form-text hint-spacer">&nbsp;</div>
                        </div>
                    </div>

                </div>

                <hr class="my-4">

                <!-- Tabela pozycji (koszyk) -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover text-sm align-middle" id="items_table">
                        <thead>
                        <tr>
                            <th style="width: 32%">Surowiec</th>
                            <th style="width: 10%">Ilość</th>
                            <th style="width: 12%">Cena netto</th>
                            <th style="width: 10%">VAT</th>
                            <th style="width: 12%">Wartość netto</th>
                            <th style="width: 10%">Tryb</th>
                            <th style="width: 8%"></th>
                        </tr>
                        </thead>
                        <tbody id="items_tbody">
                        <!-- rows inserted by JS -->
                        </tbody>
                        <tfoot>
                        <tr class="bg-light">
                            <td colspan="4" class="text-end"><strong>Suma netto:</strong></td>
                            <td><strong id="sum_net">0.00</strong></td>
                            <td colspan="2"></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button class="btn btn-primary" type="button" onclick="savePurchase(false)">
                        Zapisz (wg statusu)
                    </button>
                </div>

            </div>
        </div>

    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>

    const RM_META = <?= json_encode(
        array_reduce(($raw_materials ?? []), function($acc, $rm){
            $acc[(string)$rm['id']] = [
                'vat_rate' => (string)($rm['vat_rate'] ?? '8.00'),
                'pack'     => (string)($rm['package_size_in_base_unit'] ?? ''),
                'unit'     => (string)($rm['unit_name'] ?? '')
            ];
            return $acc;
        }, []),
        JSON_UNESCAPED_UNICODE
    ) ?>;


    // ====== MVP: lokalny koszyk pozycji ======
    const items = [];

    function money(n) {
        const x = Number(n || 0);
        return x.toFixed(2);
    }

    function num(n, decimals = 6) {
        const x = Number(n || 0);
        return x.toFixed(decimals);
    }

    function recalcSum() {
        let sum = 0;
        items.forEach(i => sum += Number(i.line_total_net || 0));
        document.getElementById('sum_net').innerText = money(sum);
    }

    function escapeHtml(str) {
        return String(str ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renderItems() {
        const tbody = document.getElementById('items_tbody');
        tbody.innerHTML = "";

        items.forEach((it, idx) => {
            const tr = document.createElement('tr');

            tr.innerHTML = `
                <td>
                    <div><strong>${escapeHtml(it.raw_material_name)}</strong></div>
                    <div class="text-muted" style="font-size: 12px;">
                        ID: ${it.raw_material_id} • jednostka: ${escapeHtml(it.unit || '')}
                    </div>
                </td>
                <td>
                  ${it.price_mode === 'PACK'
                                ? `${num(it.qty_packs, 0)} opak.`
                                : `${num(it.qty_base, 3)}`
                            }
                </td>
                <td>
                  ${it.price_mode === 'PACK'
                                ? `<div>${money(it.package_price_net)} / opak.</div>
                         <div class="text-muted" style="font-size:12px;">${num(it.unit_price_net, 6)} / bazowa</div>`
                                : `<div>${num(it.unit_price_net, 6)} / bazowa</div>`
                            }
                </td>
                <td>${it.vat_rate !== null && it.vat_rate !== undefined ? num(it.vat_rate, 2) + '%' : '—'}</td>
                <td>${money(it.line_total_net)}</td>
                <td>${it.price_mode === 'PACK' ? 'opakowanie' : 'za 1'}</td>
                <td class="text-end">
                    <button class="btn btn-danger btn-sm" type="button" onclick="removeItem(${idx})">X</button>
                </td>
            `;

            tbody.appendChild(tr);
        });

        recalcSum();
    }

    function removeItem(idx) {
        items.splice(idx, 1);
        renderItems();
    }

    function clearItems() {
        items.length = 0;
        renderItems();
    }

    // ====== DOM refs ======
    const supplierSelect = document.getElementById('supplier_id');
    const rmSelect = document.getElementById('raw_material_id');

    const vatInput = document.getElementById('vat_rate');
    const packInput = document.getElementById('package_size_base');
    const priceMode = document.getElementById('price_mode');
    const priceHint = document.getElementById('price_hint');

    const qtyInput = document.getElementById('qty_base');
    const priceInput = document.getElementById('price_net');
    const packWrap = document.getElementById('pack_size_wrap');
    const qtyLabel = document.getElementById('qty_label');
    const qtyHint  = document.getElementById('qty_hint');


    // ====== Choices init (manual; safe even if Mazer also does it) ======
    // Jeśli Mazer już inicjalizuje .choices, to ponowna inicjalizacja może robić duble.
    // Dlatego robimy "best-effort": inicjalizujemy tylko jeśli nie ma kontenera Choices obok selecta.
    function initChoicesIfNeeded(selectEl) {
        const already = selectEl.parentElement?.querySelector('.choices');
        // jeśli wrapper istnieje i nie jesteśmy nim (select ma class 'choices'), to znaczy że już zrobione
        // Ale bywa, że wrapper jest wewnątrz - dlatego dodatkowa heurystyka:
        if (selectEl.closest('.choices') && selectEl.closest('.choices') !== selectEl) {
            return null;
        }
        try {
            return new Choices(selectEl, {
                searchEnabled: true,
                shouldSort: false,
                itemSelectText: '',
                allowHTML: false
            });
        } catch (e) {
            return null;
        }
    }

    // Spróbuj ręcznie zainicjalizować (jeśli już zainicjalizowane przez template, będzie null)
    const supplierChoices = initChoicesIfNeeded(supplierSelect);
    const rawMatChoices = initChoicesIfNeeded(rmSelect);

    // Helper: focus na input wyszukiwarki Choices dla surowców
    function focusRawMaterialSearch() {
        // Jeśli mamy instancję rawMatChoices, to mamy dostęp do containerOuter
        if (rawMatChoices && rawMatChoices.containerOuter?.element) {
            const input = rawMatChoices.containerOuter.element.querySelector('input[type="search"], input.choices__input');
            if (input) { input.focus(); return; }
        }
        // fallback: spróbuj znaleźć input choices obok selecta
        const wrapper = rmSelect.closest('.choices') || rmSelect.parentElement?.querySelector('.choices');
        if (wrapper) {
            const input = wrapper.querySelector('input[type="search"], input.choices__input');
            if (input) input.focus();
        }
    }

    // ====== Autouzupełnianie VAT + pack po wyborze surowca ======
    rmSelect.addEventListener('change', () => {
        const id = rmSelect.value;
        if (!id) return;

        const meta = RM_META[id];
        // meta może być undefined jeśli np. lista dynamiczna – wtedy nic nie uzupełniamy
        if (meta) {
            if (meta.vat_rate !== null && meta.vat_rate !== undefined && meta.vat_rate !== "") {
                vatInput.value = meta.vat_rate;
            }
            if (meta.pack !== null && meta.pack !== undefined && meta.pack !== "") {
                packInput.value = meta.pack;
            }
        }

        updatePriceHint();
        setTimeout(() => qtyInput.focus(), 0);
    });

    priceMode.addEventListener('change', updatePriceHint);

    function updatePriceHint() {
        const mode = priceMode.value;

        if (mode === 'PACK') {
            priceHint.innerText = "Cena netto za opakowanie. System przeliczy na cenę za 1 jednostkę bazową.";
            if (packWrap) packWrap.style.display = '';

            // Ilość = liczba opakowań
            if (qtyLabel) qtyLabel.innerText = "Ilość (opak.)";
            if (qtyHint)  qtyHint.innerText = "Liczba opakowań (np. 1, 2).";
            qtyInput.step = "1";
            qtyInput.placeholder = "np. 1";
        } else {
            priceHint.innerText = "Cena netto za 1 jednostkę bazową (np. PLN/gram lub PLN/szt).";
            if (packWrap) packWrap.style.display = 'none';

            // Ilość = bazowa
            if (qtyLabel) qtyLabel.innerText = "Ilość (bazowa)";
            if (qtyHint)  qtyHint.innerText = "g / szt — wg surowca";
            qtyInput.step = "0.001";
            qtyInput.placeholder = "np. 1000";
        }
    }

    // ====== UX: Enter w polach = szybkie dodanie ======
    // Enter na qty/price/pack => dodaj pozycję
    [qtyInput, priceInput, packInput, vatInput].forEach(el => {
        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addItemFast();
            }
        });
    });

    // ====== Dodawanie pozycji ======
    function addItemFast() {
        const supplierId = supplierSelect.value;
        if (!supplierId) {
            Swal.fire({ icon: 'warning', title: 'Brak dostawcy', text: 'Najpierw wybierz dostawcę w nagłówku.' });
            return;
        }

        const rawMaterialId = rmSelect.value;
        if (!rawMaterialId) {
            Swal.fire({ icon: 'warning', title: 'Brak surowca', text: 'Wybierz surowiec.' });
            return;
        }

        const opt = rmSelect.options[rmSelect.selectedIndex];
        const rmName = opt ? opt.text : rawMaterialId;

        const meta = RM_META[String(rawMaterialId)] || {};
        const unitName = meta.unit || '';

        const mode = priceMode.value;

        const vatRate = vatInput.value !== "" ? Number(vatInput.value) : null;

        const priceNetInput = Number(priceInput.value);
        if (!priceNetInput || priceNetInput <= 0) {
            Swal.fire({ icon: 'warning', title: 'Cena', text: 'Podaj cenę netto > 0.' });
            return;
        }

        let qtyBase = 0;                 // ilość w jednostce bazowej (do API/magazynu)
        let qtyPacks = null;             // liczba opakowań (tylko do UI)
        let unitPriceNet = 0;            // cena za 1 bazową
        let packageSizeBase = null;
        let packagePriceNet = null;
        let lineTotalNet = 0;

        if (mode === 'PACK') {
            // UWAGA: w PACK ilość = liczba opakowań
            qtyPacks = Number(qtyInput.value);
            if (!qtyPacks || qtyPacks <= 0) {
                Swal.fire({ icon: 'warning', title: 'Ilość', text: 'Podaj liczbę opakowań > 0.' });
                return;
            }

            packagePriceNet = priceNetInput;
            packageSizeBase = Number(packInput.value);

            if (!packageSizeBase || packageSizeBase <= 0) {
                Swal.fire({ icon: 'warning', title: 'Rozmiar opakowania', text: 'Podaj rozmiar opakowania > 0.' });
                return;
            }

            // przeliczenia
            qtyBase = qtyPacks * packageSizeBase;
            unitPriceNet = packagePriceNet / packageSizeBase;
            lineTotalNet = qtyPacks * packagePriceNet; // czytelniej i bez “0.09”
        } else {
            // UNIT: ilość = bazowa
            qtyBase = Number(qtyInput.value);
            if (!qtyBase || qtyBase <= 0) {
                Swal.fire({ icon: 'warning', title: 'Ilość', text: 'Podaj ilość bazową > 0.' });
                return;
            }

            unitPriceNet = priceNetInput;
            lineTotalNet = qtyBase * unitPriceNet;
        }

        // merge (mniej wrażliwe na floaty)
        const keyUnit = Number(unitPriceNet.toFixed(6));
        const keyPackPrice = packagePriceNet !== null ? Number(packagePriceNet.toFixed(2)) : null;
        const keyPackSize  = packageSizeBase !== null ? Number(packageSizeBase.toFixed(3)) : null;

        const existingIndex = items.findIndex(i => {
            if (String(i.raw_material_id) !== String(rawMaterialId)) return false;

            if (mode === 'PACK') {
                return i.price_mode === 'PACK'
                    && Number(Number(i.package_price_net || 0).toFixed(2)) === keyPackPrice
                    && Number(Number(i.package_size_base || 0).toFixed(3)) === keyPackSize;
            }

            return i.price_mode !== 'PACK'
                && Number(Number(i.unit_price_net || 0).toFixed(6)) === keyUnit;
        });

        if (existingIndex >= 0) {
            if (mode === 'PACK') {
                items[existingIndex].qty_packs = Number(items[existingIndex].qty_packs || 0) + qtyPacks;
                items[existingIndex].qty_base  = Number(items[existingIndex].qty_base || 0) + qtyBase;
                items[existingIndex].line_total_net = Number(items[existingIndex].qty_packs) * Number(items[existingIndex].package_price_net);
            } else {
                items[existingIndex].qty_base = Number(items[existingIndex].qty_base) + qtyBase;
                items[existingIndex].line_total_net = Number(items[existingIndex].qty_base) * Number(items[existingIndex].unit_price_net);
            }
        } else {
            items.push({
                raw_material_id: Number(rawMaterialId),
                raw_material_name: rmName,
                unit: unitName,

                qty_base: qtyBase,
                qty_packs: qtyPacks, // null dla UNIT

                unit_price_net: unitPriceNet,
                vat_rate: vatRate,
                line_total_net: lineTotalNet,

                price_mode: mode,
                package_size_base: packageSizeBase,
                package_price_net: packagePriceNet
            });
        }

        // reset
        qtyInput.value = "";
        priceInput.value = "";

        if (rawMatChoices) {
            rawMatChoices.removeActiveItems();
        } else {
            rmSelect.value = "";
            rmSelect.dispatchEvent(new Event('change'));
        }

        renderItems();
        setTimeout(() => focusRawMaterialSearch(), 50);
    }


    // ====== Zapis (AJAX) ======
    async function savePurchase(forceDraft = null) {
        const supplierId = supplierSelect.value;
        const docDate = document.getElementById('doc_date').value;
        const currencyCode = document.getElementById('currency_code').value;
        const docNo = document.getElementById('doc_no').value;
        const note = document.getElementById('note').value;

        let status = document.getElementById('status').value;
        if (forceDraft === true) status = 'DRAFT';

        if (!supplierId) {
            Swal.fire({ icon: 'warning', title: 'Brak dostawcy', text: 'Wybierz dostawcę.' });
            return;
        }
        if (!docDate) {
            Swal.fire({ icon: 'warning', title: 'Brak daty', text: 'Wybierz datę dokumentu.' });
            return;
        }
        if (items.length === 0) {
            Swal.fire({ icon: 'warning', title: 'Brak pozycji', text: 'Dodaj przynajmniej jedną pozycję.' });
            return;
        }

        const payloadItems = items.map(i => ({
            raw_material_id: i.raw_material_id,
            qty_base: i.qty_base,
            unit_price_net: i.unit_price_net,
            vat_rate: i.vat_rate,
            line_total_net: Number(i.qty_base) * Number(i.unit_price_net),
            package_size_base: i.package_size_base,
            package_price_net: i.package_price_net
        }));

        const payloadDoc = {
            supplier_id: Number(supplierId),
            doc_date: docDate,
            currency_code: currencyCode,
            doc_no: docNo || null,
            note: note || null,
            status: status,
            products : payloadItems
        };



        const confirm = await Swal.fire({
            title: 'Zapis dostawy',
            html: `Zapiszę dokument i <b>${payloadItems.length}</b> pozycji.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Zapisz',
            cancelButtonText: 'Anuluj'
        });

        if (!confirm.isConfirmed) return;

        Swal.fire({
            title: 'Zapisywanie…',
            text: 'Proszę czekać',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        try {
            const docRes = await api(`/ajax/purchases`, {
                method: "POST",
                data: payloadDoc
            });

            if (!docRes || !docRes.ok) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Błąd', text: docRes?.message || 'Nie udało się zapisać dokumentu.' });
                return;
            }

            const purchaseId = docRes.data?.id ?? docRes.data ?? null;
            if (!purchaseId) {
                Swal.close();
                Swal.fire({ icon: 'error', title: 'Błąd', text: 'Brak ID dokumentu po zapisie.' });
                return;
            }

            Swal.close();

            Swal.fire({
                icon: 'success',
                title: 'Zapisano',
                html: `Dokument <b>#${purchaseId}</b> został zapisany.`,
            }).then(() => {
                window.location.href = `<?= ROOT ?>/purchases`;
            });

        } catch (e) {
            Swal.close();
            console.error(e);
            Swal.fire({ icon: 'error', title: 'Błąd', text: 'Wystąpił błąd podczas zapisu.' });
        }
    }

    // start hint
    updatePriceHint();
</script>

