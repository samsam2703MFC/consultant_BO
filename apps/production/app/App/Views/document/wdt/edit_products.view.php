<?php $this->view("page_components/header"); ?>

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
            <div class="card-header">
                <h4 class="card-title">Edycja cen na fakturze</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($isLocked)): ?><div class="alert alert-warning">Pozycje tego WDT są zablokowane, ponieważ dokument został przekazany do KSeF. Zmiany wymagają faktury korygującej.</div><?php endif; ?>
                <div class="row">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger text-center"><?= implode("<br>", $errors) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <table class="table table-hover">
                            <thead>
                            <th>Produkt</th>
                            <th>Sugerowana cena</th>
                            <th>Cena / karton</th>
                            <th></th>
                            </thead>
                            <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?= $row['name'] ?></td>
                                        <td><?= $row['suggested_price'] ?></td>
                                        <td><input type="number" class="form-control" min="0" step="0.01"
                                                   value="<?= $row['price_carton'] ?>" disabled></td>
                                        <td>
                                            <button class="btn btn-warning" type="button" <?= !empty($isLocked) ? 'disabled' : '' ?>
                                                    onclick="enableUpdate(this)">Zmień
                                            </button>
                                            <button class="btn btn-success" type="button"
                                                    onclick="savePrice('<?= $row['id_product'] ?>', '<?= $id_wdt ?>', this)"
                                                    style="display: none">Zapisz
                                            </button>
                                        </td>
                                        <td><?= $row['id_product'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="row mt-5">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <a href="<?= ROOT ?>/shipments" class="btn btn-secondary" role="button">Powrót</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>


<?php $this->view("page_components/footer"); ?>

<script type="text/javascript">


    function savePrice(id_product, id_doc, btn) {
        const data = {
            "id_product": id_product,
            "price_carton": btn.parentNode.parentNode.children[2].children[0].value,
            "id_document_wdt": id_doc
        }

        api(`/ajax/wdt-documents/${id_doc}/products/${id_product}`, {
            method: 'PATCH',
            data
        })
            .then(function (res) {
                showSnack(res.ok);
                if (res) {
                    disableUpdate(btn);
                }
            })
            .catch(function (err) {
                console.error(err);
                showSnack(err?.message || "Wystąpił błąd podczas aktualizacji ceny produktu.");
            });
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
        previousColInput.disabled = true;
    }

</script>
