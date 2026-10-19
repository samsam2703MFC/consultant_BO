<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Listy kompletacyjne dla zwrotów</h3>
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
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title mb-0">Dokument PDF</h4>
                <a class="btn btn-outline-secondary btn-sm" href="<?= ROOT ?>/archive/documents/future/complaint-picking-lists"><i class="bi bi-archive me-1"></i>Archiwum list</a>
            </div>
            <div class="card-body">
                <?php if (!empty($unique_shipping_date)): ?>
                    <div class="row">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <label for="shipping_date" class="form-label">
                                Data wysyłki
                            </label>
                            <select class="form-select" name="shipping_date" id="shipping_date" required>
                                <?php foreach ($unique_shipping_date as $item): ?>
                                    <option value="<?= $item['shipping_date'] ?>"><?= $item['shipping_date'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-4">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <button class="btn btn-primary" onclick="getShippingList()">Wygeneruj listę</button>
                        </div>
                    </div>
                <?php else: ?>
                    <h3>Brak dat do wyświetlenia</h3>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>
    async function getShippingList() {
        const date = document.getElementById('shipping_date').value;
        if(!date) {
            showSnack("Proszę wybrać datę dostawy");
            return;
        }

        downloadPDF(
            `/ajax/client-complaints/picking-list`,
            {
                "shipping_date": date
            },
            "shipping_list.pdf",
            "GET"
        )
    }
</script>
