<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Produkty oczekujące na wysłanie</h3>
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
                    <h4 class="card-title">Oczekujące produkty</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12">
                            <table class="table table-hover">
                                <thead>
                                <th>Klient</th>
                                <th>Produkt</th>
                                <th>Oczekujące / Ilość w kartonie</th>
                                <th></th>
                                </thead>
                                <tbody>
                                <?php if (!empty($pending_products)): ?>
                                    <?php foreach ($pending_products as $pending_product): ?>
                                        <tr>
                                            <td><?= $pending_product['company'] ?></td>
                                            <td><?= $pending_product['product_name'] ?></td>
                                            <td><?= $pending_product['pending_product_qty'] ?>
                                                / <?= $pending_product['ppc'] ?></td>
                                            <td>
                                                <a href="<?= ROOT ?>/client-complaints/<?= $pending_product['id'] ?>/realisation"
                                                   role="button" class="btn btn-secondary">Przejdź do realizacji</a>
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