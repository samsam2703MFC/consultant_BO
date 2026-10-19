<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Produkt gotowy</h3>
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
                <h4 class="card-title">Kalkulacja ceny receptury produktu gotowego</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-1"></div>
                    <div class="col-10">
                    </div>
                </div>
                <div class="row mt-5">
                    <div class="col-6">
                        <table class="table">
                            <thead>
                            <th></th>
                            <th></th>
                            </thead>
                            <tbody>
                            <tr>
                                <td>Nazwa produktu</td>
                                <td><?=$recipe_calculation_data['name']?></td>
                            </tr>
                            <tr>
                                <td>Ilość sztuk w opakowaniu</td>
                                <td>1 x <?=$recipe_calculation_data['ppc']?> <?=$recipe_calculation_data['unit_name']?></td>
                            </tr>
                            <tr>
                                <td>Koszt surowców</td>
                                <td><?=$recipe_price?> zł</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-6">
                        <?php show(recipeList2html($recipe_calculation_data['elements'])) ?>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-2"></div>
                    <div class="col-8">
                        <a href="<?=ROOT?>/recipes" role="button" class="btn btn-secondary float-end">Powrót</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script type="text/javascript">

    function expandChildren(rows_classname){
        showHideRow(rows_classname)
    }

</script>

