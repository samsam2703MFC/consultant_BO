<?php $this->view("page_components/header"); ?>
    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Surowiec</h3>
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
                    <h4 class="card-title">Lista produktów</h4>
                </div>
                <div class="card-body">
                    <?php if(!empty($rows)): ?>
                        <table class="table table-striped">
                            <thead>
                            <th>Nazwa</th>
                            <th>W magazynie</th>
                            <th>Na palecie</th>
                            <th>łącznie</th>
                            </thead>
                            <tbody>
                            <?php if(!empty($rows)): ?>
                                <?php foreach($rows as $row): ?>
                                    <tr>
                                        <td><?= $row['name'] ?></td>
                                        <td><?= $row['in_stock'] ?></td>
                                        <td><?= $row['palletised'] ?></td>
                                        <td><?= $row['total_in_stock'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-4">
                                <div class="alert alert-danger text-center">Brak produktów dla wybranej kategorii</div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="row mt-4">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <a href="<?=ROOT?>/stock" role="button" class="btn btn-secondary">Powrót</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
<?php $this->view("page_components/footer"); ?>
