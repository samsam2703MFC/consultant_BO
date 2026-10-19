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
                    <h4 class="card-title">Lista kategorii produktów w magazynie</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <a href="<?= ROOT ?>/products/stock/all" role="button" class="btn btn-info">Wyświetl
                                wszystkie produkty</a>
                        </div>
                        <div class="col-3 text-center">
                            <button class="btn btn-dark text-sm" type="button" onclick="getStockState()">Stan magazynowy na bieżący dzień
                                [PDF]</button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <table class="table table-striped">
                                <thead>
                                <th style="width: 75%">Kategoria</th>
                                <th style="width: 25%"></th>
                                </thead>
                                <tbody>
                                <?php if (!empty($categories)): ?>
                                    <?php foreach ($categories as $category): ?>
                                        <tr>
                                            <td><?= $category['name'] ?></td>
                                            <td class="text-center"><a
                                                        href="<?= ROOT ?>/product-categories/<?= $category['id'] ?>/products/stock"
                                                        role="button" class="btn btn-info">Wyświetl
                                                    produkty</a></td>
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

<script type="text/javascript">

    function getStockState() {
        downloadPDF(`/ajax/products/stock-state`);
    }


</script>
