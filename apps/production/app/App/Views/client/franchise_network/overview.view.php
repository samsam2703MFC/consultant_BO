<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Sieć franczyzowa klientów</h3>
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
                <h4 class="card-title">Lista utworzonych sieci <a href="<?=ROOT?>/client-franchise-networks/new" class="btn btn-primary" role="button">Dodaj nowegą sieć</a></h4>
            </div>
            <div class="card-body">
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th>Nazwa sieci</th>
                            <th>Kontakt</th>
                            <th></th>
                            <th>Ilość aktywnych sklepów</th>
                            <th></th>
                            <th></th>
                            <th></th>
                            </thead>
                            <tbody>
                            <?php if(!empty($networks)): ?>
                                <?php foreach($networks as $row): ?>
                                    <tr>
                                        <td><?= $row['name']?></td>
                                        <td><?= $row['contact_email']?></td>
                                        <td><?= $row['contact_phone']?></td>
                                        <td><?= $row['qty_shop']?></td>
                                        <td><a href="<?=ROOT?>/client-franchise-networks/<?=$row['id']?>/franchises" class="btn btn-info">Franczyzy</a></td>
                                        <td><a href="<?=ROOT?>/client-franchise-networks/<?=$row['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                        <td><a href="<?=ROOT?>/client-franchise-networks/<?=$row['id']?>/delete" class="btn btn-danger">Usuń</a></td>
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