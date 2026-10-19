<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Rentowność klientów</h3>

            </div>

        </div>
    </div>
    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Rentowność klienta</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12">
                        <h4 class="text-center"><?= $customer['company'] ?></h4>
                    </div>
                    <div class="col-12">
                        <p class="text-center"><?=$last_month_year?></p>
                    </div>
                </div>
                <div class="row mt-4">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th style="width: 5%%"></th>
                            <th style="width: 25%">Kategoria</th>
                            <th style="width: 25%">Produkt</th>
                            <th style="width: 15%">Przychód</th>
                            <th style="width: 15%">Koszt surowców</th>
                            <th style="width: 15%">Dochód</th>
                            <th style="width: 15%">Marża</th>
                            </thead>
                            <tbody id="product_table_tbody">
                            <?php if(!empty($products_grouped_by_category)): ?>
                                <?php foreach ($products_grouped_by_category as $category): ?>
                                    <?php $category_marge = $category['total_earn'] > 0 ? number_format((($category['total_earn'] - $category['total_cost']) / $category['total_earn'])*100, 2) : 0 ?>

                                    <tr class="bg-light-secondary">
                                        <td><button class="btn btn-outline-success" onclick="showHideRow('category_<?=$category['id']?>')">+</button> </td>
                                        <td><?=$category['name']?></td>
                                        <td></td>
                                        <td style="background-color: #FEF9E7"><?=$category['total_earn']?> zł</td>
                                        <td style="background-color: #FDEDEC"><?=$category['total_cost']?> zł</td>
                                        <?php $income_category = $category['total_earn'] - $category['total_cost']?>
                                        <td style="background-color: #EAFAF1"><?=$income_category?> zł</td>
                                        <td><?=$category_marge?>%</td>
                                    </tr>


                                    <?php if(!empty($category['products'])): ?>
                                        <?php foreach ($category['products'] as $product): ?>
                                            <?php $product_marge = $product['earned_value_pln'] > 0 ? number_format((($product['earned_value_pln'] - $product['total_raw_cost']) / $product['earned_value_pln'])*100, 2) : 0 ?>

                                            <tr class="category_<?=$category['id']?> hideRow">
                                                <td></td>
                                                <td></td>
                                                <td><?=$product['name']?></td>
                                                <td><?=$product['earned_value_pln']?> zł</td>
                                                <td><?=$product['total_raw_cost']?> zł</td>
                                                <?php $income_product = $product['earned_value_pln'] - $product['total_raw_cost']?>
                                                <td><?=$income_product?> zł</td>
                                                <td class="float-end"><?=$product_marge?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

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
