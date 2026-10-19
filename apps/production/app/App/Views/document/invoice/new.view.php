<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Faktura</h3>
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
    <div class="mb-3"><a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/invoices"><i class="bi bi-archive me-1"></i>Archiwum faktur</a></div>
    <section class="section">

        <?php if (!empty($client)): ?>
        <div class="card mb-3">
            <div class="card-body py-3">
                <div class="d-flex align-items-start gap-4 flex-wrap">

                    <div class="flex-shrink-0">
                        <div class="avatar avatar-xl bg-light-primary rounded-circle d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                            <span class="fw-bold text-primary fs-4">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($client['name'] ?? '?', 0, 1) . mb_substr($client['surname'] ?? '', 0, 1))) ?>
                            </span>
                        </div>
                    </div>

                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <h5 class="mb-0 fw-semibold">
                                    <?= htmlspecialchars($client['company'] ?? '') ?>
                                </h5>
                                <span class="text-muted small">
                                    <?= htmlspecialchars(($client['name'] ?? '') . ' ' . ($client['surname'] ?? '')) ?>
                                </span>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if (!empty($client['active'])): ?>
                                    <span class="badge bg-light-success text-success">Aktywny</span>
                                <?php endif; ?>
                                <?php if (!empty($client['default_currency'])): ?>
                                    <span class="badge bg-light-info text-info">
                                        <i class="bi bi-currency-exchange me-1"></i><?= htmlspecialchars($client['default_currency']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (isset($client['payment_termin'])): ?>
                                    <span class="badge bg-light-secondary text-secondary">
                                        Termin: <?= (int)$client['payment_termin'] ?> dni
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-12 col-md-4">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-geo-alt text-muted mt-1"></i>
                                    <div>
                                        <div class="small fw-semibold text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Adres</div>
                                        <div><?= htmlspecialchars(($client['fac_street'] ?? '') . ' ' . ($client['fac_street_num'] ?? '')) ?></div>
                                        <div><?= htmlspecialchars(($client['fac_zip'] ?? '') . ' ' . ($client['fac_town'] ?? '')) ?></div>
                                        <?php if (!empty($client['fac_country'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($client['fac_country']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-envelope text-muted mt-1"></i>
                                    <div>
                                        <div class="small fw-semibold text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">Kontakt</div>
                                        <?php if (!empty($client['email_head'])): ?>
                                            <div><a href="mailto:<?= htmlspecialchars($client['email_head']) ?>" class="text-reset"><?= htmlspecialchars($client['email_head']) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (!empty($client['phone_head'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($client['phone_head']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-receipt text-muted mt-1"></i>
                                    <div>
                                        <div class="small fw-semibold text-muted text-uppercase" style="font-size:.7rem;letter-spacing:.05em;">NIP</div>
                                        <div class="fw-semibold"><?= htmlspecialchars($client['tax_number'] ?? '—') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($client['franchisee_id']) || !empty($client['franchise_network_id'])): ?>
        <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3">
            <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
            <span>
                Klient posiada przypisany cennik zewnętrzny. Produkty nieobecne w cenniku zewnętrznym
                lub oznaczone jako nieaktywne otrzymają cenę z <strong>cennika ogólnego</strong>.
            </span>
        </div>
        <?php endif; ?>

        <div class="card">

            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-12">
                            <table class="table table-hover">
                                <thead>
                                <th style="width: 10%">Numer</th>
                                <th style="width: 40%">Produkt</th>
                                <th style="width: 10%">Ilość kartonów</th>
                                <th style="width: 10%">Cena/karton</th>
                                <th style="width: 10%">Stawka VAT</th>
                                <th style="width: 10%">Sztuk/karton</th>
                                <th style="width: 10%"></th>
                                </thead>
                                <tbody id="product_table">
                                <?php if (!empty($old_values['invoice_product'])): ?>
                                    <?php foreach ($old_values['invoice_product'] as $old_product): ?>
                                        <tr>
                                            <td>
                                                <?= $old_product['id'] ?>
                                                <input type="hidden"
                                                       name="invoice_product[<?= $old_product['id'] ?>][id]"
                                                       value="<?= $old_product['id'] ?>">
                                            </td>
                                            <td>
                                                <?= $old_product['product_name'] ?>
                                                <input type="hidden"
                                                       name="invoice_product[<?= $old_product['id'] ?>][product_name]"
                                                       value="<?= $old_product['product_name'] ?>">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control"
                                                       name="invoice_product[<?= $old_product['id'] ?>][quantity]"
                                                       value="<?= $old_product['quantity'] ?>" required>
                                            </td>
                                            <td>
                                                <input type="number" class="form-control" step="0.01"
                                                       name="invoice_product[<?= $old_product['id'] ?>][single_price]"
                                                       value="<?= $old_product['single_price'] ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control" step="1"
                                                       name="invoice_product[<?= $old_product['id'] ?>][tax_perc]"
                                                       value="<?= $old_product['tax_perc'] ?>">
                                            </td>
                                            <td>
                                                <input type="number" class="form-control" step="1"
                                                       name="invoice_product[<?= $old_product['id'] ?>][ppc]"
                                                       value="<?= $old_product['ppc'] ?>">
                                            </td>
                                            <td>
                                                <button class="btn btn-danger" type="button"
                                                        onclick="removeProduct(this)">X
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                                <tbody>
                                <tr>
                                    <td colspan="2">
                                        <select class="form-select choices" name="product">
                                            <?php if (!empty($price_list)): ?>
                                                <?php foreach ($price_list as $product): ?>
                                                    <option value="<?= $product->getIdProduct() ?>"><?= $product->getProductName() ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                    <td><input class="form-control"></td>
                                    <td>
                                        <button class="btn btn-success" type="button" onclick="add(this)">Dodaj</button>
                                    </td>
                                    <td colspan="3"></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-4">
                            <button class="btn btn-primary">Utwórz</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer") ?>

<script>

    // Mapa id_product => dane produktu, zbudowana po stronie PHP
    const PRODUCT_MAP = <?php
        $map = [];
        if (!empty($price_list)) {
            foreach ($price_list as $item) {
                if (!$item->getIsActive()) continue;
                $map[(int)$item->getIdProduct()] = [
                    'id'           => (int)$item->getIdProduct(),
                    'name'         => $item->getProductName(),
                    'single_price' => (float)$item->getPriceNet(),
                    'tax_perc'     => (float)$item->getVatRate(),
                    'ppc'          => (int)$item->getPackageSize(),
                ];
            }
        }
        echo json_encode($map, JSON_UNESCAPED_UNICODE);
    ?>;

    // Uzupełnij pola w wierszu dodawania po wyborze produktu z selecta
    document.addEventListener('DOMContentLoaded', function () {
        const select = document.querySelector('tbody:last-child select[name="product"]');
        if (select) {
            select.addEventListener('change', function () {
                fillAddRowFromPriceList(this);
            });
            // Uzupełnij od razu przy załadowaniu jeśli coś jest wybranie
            fillAddRowFromPriceList(select);
        }
    });

    function fillAddRowFromPriceList(select) {
        const productId = parseInt(select.value, 10);
        const row       = select.closest('tr');
        if (!row) return;

        const inputs = row.querySelectorAll('input.form-control');
        // inputs[0] = ilość (jedyne pole które wypełnia użytkownik – nie ruszamy)

        // Wyczyść poprzednie podpowiedzi
        const hint = row.querySelector('.price-hint');
        if (hint) hint.remove();

        if (!PRODUCT_MAP[productId]) return;

        const p = PRODUCT_MAP[productId];

        // Wstaw informację o cenie i ppc jako podpowiedź pod selectem
        const info = document.createElement('small');
        info.className = 'text-muted price-hint d-block mt-1';
        info.textContent = `Cena: ${p.single_price.toFixed(2)} | VAT: ${p.tax_perc}% | Sztuk/karton: ${p.ppc}`;
        select.closest('td').appendChild(info);
    }

    function isExistInTable(table_name, id) {
        const rows = document.getElementById(table_name).rows;
        for (let i = 0; i < rows.length; i++) {
            if (rows[i].cells[0].innerText == id) {
                return true;
            }
        }
        return false;
    }

    function add(button) {
        const row       = button.closest('tr');
        const select    = row.querySelector('select[name="product"]');
        const productId = parseInt(select.value, 10);

        if (isExistInTable("product_table", productId)) {
            alert("Produkt został już wybrany");
            return;
        }

        const quantityInput = row.querySelector('input.form-control');
        const quantity      = quantityInput ? quantityInput.value : '';

        if (!quantity || quantity <= 0) {
            alert("Podaj ilość kartonów");
            return;
        }

        // if (!PRODUCT_MAP[productId]) {
        //     // Fallback – produkt nie ma ceny w liście, użyj AJAX jak wcześniej
        //     const customer = document.getElementById("customer").value;
        //     api(`/ajax/clients/${customer}/products/${productId}/price`)
        //         .then(function (res) {
        //             if (!res.ok) { showSnack(false); return; }
        //             insertIntoTable(res.data, row);
        //             select.selectedIndex = 0;
        //             if (quantityInput) quantityInput.value = '';
        //             const hint = row.querySelector('.price-hint');
        //             if (hint) hint.remove();
        //         });
        //     return;
        // }

        const p = PRODUCT_MAP[productId];
        insertIntoTable({
            id:           p.id,
            name:         p.name,
            single_price: p.single_price,
            tax_perc:     p.tax_perc,
            ppc:          p.ppc,
        }, row, quantity);

        select.selectedIndex = 0;
        if (quantityInput) quantityInput.value = '';
        const hint = row.querySelector('.price-hint');
        if (hint) hint.remove();
        // Odśwież podpowiedź dla nowo wybranej (pierwszej) opcji
        fillAddRowFromPriceList(select);
    }

    function insertIntoTable(product_data, row, quantityOverride) {
        const input    = row.querySelector('input.form-control');
        const quantity = quantityOverride ?? (input ? input.value : '');
        const table    = document.getElementById("product_table");

        const new_row = table.insertRow(table.rows.length);
        new_row.insertCell(0).innerHTML = `${product_data.id} <input type="hidden" name="invoice_product[${product_data.id}][id]" value="${product_data.id}">`;
        new_row.insertCell(1).innerHTML = `${product_data.name} <input type="hidden" name="invoice_product[${product_data.id}][product_name]" value="${product_data.name}">`;
        new_row.insertCell(2).innerHTML = `<input type="text" class="form-control" name="invoice_product[${product_data.id}][quantity]" value="${quantity}" required>`;
        new_row.insertCell(3).innerHTML = `<input type="text" class="form-control" name="invoice_product[${product_data.id}][single_price]" value="${product_data.single_price}" required>`;
        new_row.insertCell(4).innerHTML = `<input type="text" class="form-control" name="invoice_product[${product_data.id}][tax_perc]" value="${product_data.tax_perc ?? 0}" required>`;
        new_row.insertCell(5).innerHTML = `<input type="text" class="form-control" name="invoice_product[${product_data.id}][ppc]" value="${product_data.ppc}" required>`;
        new_row.insertCell(6).innerHTML = `<button class="btn btn-danger" type="button" onclick="removeProduct(this)">X</button>`;

        if (!quantityOverride && input) input.value = '';
    }

    function removeProduct(button) {
        button.parentNode.parentNode.remove();
    }

</script>
