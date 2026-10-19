<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Kalkulacja</h3>

                </div>

            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Kalkulacja dla ostatniego pełnego miesiąca</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12">
                            <p class="float-end">Obliczone dla kursu euro: <?=$exchange_rate_euro?> zł</p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <table class="table text-sm">
                                <thead>
                                <th style="width: 5%"></th>
                                <th style="width: 5%">Dział produkcyjny</th>
                                <th style="width: 5%"></th>
                                <th style="width: 10%">Pracownik</th>
                                <th style="width: 10%">Koszt</th>
                                <th style="width: 10%">Przychód</th>
                                <th style="width: 10%">Suma</th>
                                <th style="width: 10%">Śr. zysk / karton</th>
                                <th style="width: 10%">Śr. koszt / karton</th>
                                <th style="width: 10%">Śr. marża / karton</th>
                                <th style="width: 5%"></th>
                                </thead>
                                <tbody>
                                <?php if(!empty($final_products_grouped_by_section)): ?>
                                    <?php foreach($final_products_grouped_by_section as $item): ?>
                                        <tr>
                                            <td><button class="btn btn-outline-success" type="button" onclick="showHideRow('row_<?=$item['id_section']?>')">+</button></td>
                                            <td colspan="2"><?= $item['section_name'] ?></td>
                                            <td style="background-color: #f8d7da"><?= number_format($item['employee_cost'], 2, '.', ' ') ?> zł</td>
                                            <td style="background-color: #f8d7da"><?= number_format($item['cost_without_employee'], 2, '.', ' ') ?> zł</td>
                                            <td style="background-color: #d4edda"><?= number_format($item['earning'], 2, '.', ' ') ?> zł</td>
                                            <td><?= number_format($item['earning']-$item['cost_without_employee']-$item['employee_cost'],2,'.', ' ') ?> zł</td>
                                            <td>
                                                <?php $earn_per_carton = $quantity_of_sold_carton > 0 ? ($item['earning'] / $quantity_of_sold_carton) : 0 ?>
                                                <?= number_format($earn_per_carton, 2, '.', ' ') ?> zł
                                            </td>
                                            <td>
                                                <?php $cost_per_carton = $quantity_of_sold_carton > 0 ? (($item['cost_without_employee'] + $item['employee_cost']) / $quantity_of_sold_carton) : 0 ?>
                                                <?= number_format($cost_per_carton,2, '.', ' ') ?> zł
                                            </td>
                                            <td>
                                                <?php $ratio = $earn_per_carton > 0 ? (($earn_per_carton - $cost_per_carton) / $earn_per_carton)*100 : 0 ?>
                                                <?= number_format($ratio, 2) ?> %
                                            </td>
                                            <td><a href="<?=ROOT?>/production-sections/<?=$item['id_section']?>/profitability/details" role="button" class="btn btn-secondary float-end">Szczegóły</a> </td>
                                        </tr>

                                        <?php if(!empty($item['products'])): ?>
                                            <?php foreach($item['products'] as $product): ?>
                                                <?php $bg_color = $product['id_recipe'] ? "bg-light-success" : ""; ?>
                                                <tr class="row_<?=$item['id_section']?> hideRow <?=$bg_color?>">
                                                    <td></td>
                                                    <td></td>
                                                    <td colspan="2"><?=$product['name']?></td>
                                                    <td><a href="<?=ROOT?>/products/<?=$product['id']?>/profitability" role="button" class="btn btn-info">Kalkulacja</td>
                                                    <td></td>
                                                    <td></td>
                                                    <td></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                                <tbody>
                                <tr>
                                    <td></td>
                                    <td></td>
                                    <td>Suma:</td>
                                    <td><?=number_format($total_employee_cost, 2, '.', ' ')?> zł</td>
                                    <td><?=number_format($total_cost_without_employee, 2, '.', ' ')?> zł</td>
                                    <td><?=number_format($total_earning, 2, '.', ' ')?> zł</td>
                                    <td><?= number_format($total_earning - $total_employee_cost - $total_cost_without_employee, 2, '.', ' ') ?> zł</td>
                                    <td>
                                        <?php $total_earn_per_carton = $quantity_of_sold_carton > 0 ? ($total_earning / $quantity_of_sold_carton) : 0 ?>
                                        <?= number_format($total_earn_per_carton, 2, '.', ' ') ?> zł
                                    </td>
                                    <td>
                                        <?php $total_cost_per_carton = $quantity_of_sold_carton > 0 ? (($total_cost_without_employee + $total_employee_cost) / $quantity_of_sold_carton) : 0 ?>
                                        <?= number_format($total_cost_per_carton, 2, '.', ' ') ?> zł
                                    </td>
                                    <td>
                                        <?= $total_earn_per_carton > 0 ?
                                            number_format(((
                                                        $total_earn_per_carton -
                                                        $total_cost_per_carton) /
                                                    $total_earn_per_carton)*100, 2) : 0 ?> %
                                    </td>
                                    <td></td>
                                </tr>
                                </tbody>
                            </table>

                        </div>
                        <div class="col-12">
                            <table class="table">
                                <thead>
                                <th>Materiały</th>
                                <th>Amortyzacja</th>
                                <th>Woda</th>
                                <th>Prąd</th>
                                <th>Paliwo</th>
                                <th>Inne</th>
                                </thead>
                                <tbody>
                                <tr>
                                    <td><?=$total_material_cost?></td>
                                    <td><?=$total_depreciation_cost?></td>
                                    <td><?=$total_water_cost?></td>
                                    <td><?=$total_power_supply_cost?></td>
                                    <td><?=$total_fuel_cost?></td>
                                    <td><?=$total_other_cost?></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>