<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Przewoźnik</h3>
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
                    <h4 class="card-title">Lista przewoźników <a href="<?=ROOT?>/product-carriers/new" class="btn btn-primary" role="button">Dodaj nowy</a></h4>
                </div>
                <div class="card-body">
                    <table class="table table-striped">
                        <thead>
                        <th style="width: 55%">Nazwa</th>
                        <th style="width: 15%"></th>
                        <th style="width: 15%"></th>
                        <th style="width: 15%"></th>
                        </thead>
                        <tbody>
                        <?php if(!empty($rows)): ?>
                            <?php foreach($rows as $row): ?>
                                <tr>
                                    <td><?= $row['name']?></td>
                                    <td><a href="<?=ROOT?>/product-carriers/<?=$row['id']?>/vehicles" class="btn btn-info">Pojazdy</a></td>
                                    <td><a href="<?=ROOT?>/product-carriers/<?=$row['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                    <td><a href="<?=ROOT?>/product-carriers/<?=$row['id']?>/delete" class="btn btn-danger">Usuń</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>