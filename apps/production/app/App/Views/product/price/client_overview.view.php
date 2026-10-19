<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Cena produktu gotowego</h3>
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
                <h4 class="card-title">Lista klientów</h4>
            </div>
            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                    <th style="width: 75%">Klient</th>
                    <th style="width: 25%"></th>
                    </thead>
                    <tbody>
                    <?php //TODO: dodać możliwość generowania listy cenowej dla wybranego klienta ?>
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= $row['company'] ?></td>
                                <td class="text-center"><a
                                            href="<?= ROOT ?>/clients/<?= $row['id'] ?>/price-list"
                                            class="btn btn-info">Zmień ceny</a></td>
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
