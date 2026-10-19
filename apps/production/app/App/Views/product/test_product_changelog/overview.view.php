<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Dziennik produktu testowego</h3>
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
                    <h4 class="card-title">Lista testowych produktów  <a href="<?=ROOT?>/test-products/<?=$id_product?>/changelogs/new" class="btn btn-primary" role="button">Dodaj nowy wpis</a></h4>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                        <th style="width: 20%">Nazwa etapu</th>
                        <th style="width: 40%">Komentarz</th>
                        <th style="width: 15%">Ostateczny termin</th>
                        <th style="width: 15%">Data utworzenia</th>
                        <th style="width: 5%"></th>
                        <th style="width: 5%"></th>
                        </thead>
                        <tbody>
                        <?php if(!empty($rows)): ?>
                            <?php foreach($rows as $row): ?>
                                <tr>
                                    <td><?= $row['title'] ?></td>
                                    <td><?= $row['description'] ?></td>
                                    <td><?= $row['deadline'] ?></td>
                                    <td><?= $row['create_timestamp'] ?></td>
                                    <td><a href="<?=ROOT?>/TestProductChangelog/getChangelogPDF/<?=$row['id']?>" class="btn btn-info" target="_blank">PDF</a></td>
                                    <td><a href="<?=ROOT?>/test-products/<?=$id_product?>/changelogs/<?=$row['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                    <td><a href="<?=ROOT?>/test-products/<?=$id_product?>/changelogs/<?=$row['id']?>/delete" class="btn btn-danger">Usuń</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="row">
                        <div class="col-3">
                            <a href="<?=ROOT?>/test-products" class="btn btn-secondary" role="button">Powrót</a>
                        </div>
                        <div class="col-6"></div>
                        <div class="col-3">
                            <a href="<?=ROOT?>/test-product-stages" class="btn btn-success float-end" role="button">Zarządzaj etapami</a>
                        </div>
                    </div>
                </div>
            </div>

        </section>
    </div>
<?php $this->view("page_components/footer"); ?>