<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Reklamacja</h3>
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
                <h4 class="card-title">Wygenerowane dokumenty zwrotów</h4>
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
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 25%">Data</th>
                            <th style="width: 60%">Firma</th>
                            <th style="width: 15%"></th>
                            </thead>
                            <tbody>
                            <?php if(!empty($generated_docs)): ?>
                                <?php foreach($generated_docs as $document): ?>
                                    <tr>
                                        <td><?= $document['shipment_date']?></td>
                                        <td><?= $document['customer_name']?></td>
                                        <td><button class="btn btn-info" type="button" onclick="getShippingDocuments(<?=$document['id']?>)">
                                                Dokumenty wysyłki
                                            </button>
                                        </td>

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

<?php $this->view("page_components/footer"); ?>

<script>

    function getShippingDocuments(id_document){

        downloadPDF(`/ajax/client-complaints/shipping-documents/${id_document}`);
    }

</script>