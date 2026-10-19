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
    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Istniejące faktury <button class="btn btn-primary" type="button" onclick="runClientPickerModal()">Nowa faktura</button>

<!--                    <a href="--><?php //=ROOT?><!--/invoice-documents/new" class="btn btn-primary" role="button">Nowa faktura</a></h4>-->
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th>Faktura</th>
                            <th>Klient</th>
                            <th>Data utworzenia</th>
                            <th></th>
                            </thead>
                            <tbody>
                            <?php if(!empty($existed_invoices)): ?>
                                <?php foreach($existed_invoices as $invoice): ?>
                                    <tr>
                                        <td><?=$invoice['doc_num']?></td>
                                        <td><?=$invoice['company']?></td>
                                        <td><?=$invoice['create_timestamp']?></td>
                                        <td class="d-flex gap-2"><button class="btn btn-primary" type="button" onclick="generateInvoice(<?=$invoice['id']?>)">PDF</button><a class="btn btn-outline-primary" href="<?= ROOT ?>/communication/compose/invoice/<?= (int)$invoice['id'] ?>">E-mail</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="clientPickerModal" tabindex="-1" aria-labelledby="clientPickerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="clientPickerModalLabel">Wybierz klienta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <select class="form-select" id="clientSelect">
                    <option value="" selected disabled>Wybierz klienta</option>
                    <?php foreach ($clients as $client): ?>
                        <option value="<?= $client['id'] ?>"><?= $client['company'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" onclick="moveToCreator()">Utwórz fakture</button>
            </div>
        </div>
    </div>
</div>

<?php $this->view("page_components/footer"); ?>

<script>

    function generateInvoice(id) {
        downloadPDF(`/ajax/invoice-documents/${id}/document`)
    }

    function runClientPickerModal(){
        const modal = document.getElementById('clientPickerModal');
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }

    function moveToCreator() {
        const clientId = document.getElementById('clientSelect').value;
        if(clientId){
            window.location.href = `<?= ROOT ?>/clients/${clientId}/invoices/create`;
        }else{
            alert("Nie wybrano klienta");
        }
    }

</script>
