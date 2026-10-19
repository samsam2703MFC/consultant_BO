<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Rentowność działu</h3>
                <p class="text-subtitle text-muted"></p>
            </div>
            <div class="col-12 col-md-6 order-md-2 order-first">

            </div>
        </div>
    </div>
    <section class="section">

        <?php if($action == 'new'): ?>

        <?php elseif($action == 'details'): ?>
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Podsumowanie</h4>
                </div>
                <div class="card-body">

                    <div class="row">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <?php if(!empty($date_errors)):?>
                                <div class="alert alert-danger text-center"><?=implode("<br>", $date_errors)?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12 text-center">
                            <h3>Kalkulacja dla miesiąca: <?=$last_month_year?></h3>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <table class="table">
                                <thead class="bg-light-info">
                                <th style="width: 14%">Surowce</th>
                                <th style="width: 14%">Pracownicy</th>
                                <th style="width: 14%">Dodatkowe koszty</th>
                                <th style="width: 14%">Koszty łącznie</th>
                                <th style="width: 14%">Przychód</th>
                                <th style="width: 14%">Dochód</th>
                                <th style="width: 14%">Marża</th>
                                </thead>
                                <tbody>
                                <tr>
                                    <td><?=$total_material_cost?> zł</td>
                                    <td><?=$total_employee_cost?> zł</td>
                                    <td><?=$total_cost_without_employee_and_material?> zł</td>
                                    <td style="background-color: #FDEDEC"><?=number_format($total_cost, 2, '.', ' ')?> zł</td>
                                    <td style="background-color: #EAFAF1"><?=number_format($total_earning,2, '.', ' ')?> zł</td>
                                    <td style="background-color: #FEF9E7"><?=$quota_margin?> zł</td>
                                    <td style="background-color: #EAF2F8"><?=$percentage_margin?> %</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <table class="table">
                                <thead>
                                    <th>Sprzedanych kartonów</th>
                                    <th>Średni zysk / karton</th>
                                    <th>Średni koszt / karton</th>
                                    <th>Średnia marża</th>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?=$quantity_of_sold_carton?></td>
                                        <td>
                                            <?= $earn_per_carton = $quantity_of_sold_carton > 0 ? number_format(($total_earning / $quantity_of_sold_carton), 2) : 0 ?> zł
                                        </td>
                                        <td>
                                            <?= $cost_per_carton = $quantity_of_sold_carton > 0 ? number_format(($total_cost / $quantity_of_sold_carton), 2) : 0 ?> zł
                                        </td>
                                        <td>
                                            <?= $earn_per_carton > 0 ? number_format((($earn_per_carton - $cost_per_carton) / $earn_per_carton)*100, 2) : 0 ?> %
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>


                    <div class="row mt-5">
                        <div class="col-12">
                            <table class="table table-hover text-sm">
                                <thead>
                                <th style="width: 15%">Kategoria</th>
                                <th style="width: 15%"></th>
                                <th style="width: 15%">Koszt surowców</th>
                                <th style="width: 15%">Przychód</th>
                                <th style="width: 10%">Sprzedanych k.</th>
                                <th style="width: 10%">%</th>
                                <th style="width: 10%">Udział w łącznych kosztach</th>
                                <th style="width: 10%">Udział w łącznym zysku</th>
                                </thead>
                                <tbody id="general_table">
                                <?php if(!empty($category_with_products)): ?>
                                    <?php foreach($category_with_products as $category): ?>
                                        <tr class="bg-light-secondary">
                                            <td><?= $category['name'] ?></td>
                                            <td></td>
                                            <td><?= $category['total_cost'] ?> zł</td>
                                            <td><?= $category['total_earn'] ?> zł</td>
                                            <td></td>
                                            <?php $category_marge = $category['total_earn'] > 0 ? number_format((($category['total_earn'] - $category['total_cost']) / $category['total_earn'])*100, 2) : 0 ?>

                                            <td><?=$category_marge?> %</td>

                                            <td colspan="2"></td>
                                        </tr>

                                        <?php if(!empty($category['products'])): ?>
                                            <?php foreach($category['products'] as $product): ?>
                                                <tr>
                                                    <td></td>
                                                    <td><?=$product->name?></td>
                                                    <td style="background-color: #FDEDEC"><?=$product->total_raw_cost?> zł</td>
                                                    <td style="background-color: #EAFAF1"><?=$product->earned_value_pln?> zł</td>
                                                    <td><?=$product->sold_quantity?></td>
                                                    <?php $marge = $product->earned_value_pln > 0 ? number_format((($product->earned_value_pln - $product->total_raw_cost) / $product->earned_value_pln)*100, 2) : 0 ?>
                                                    <?php
                                                    if($marge <= 45){
                                                        $bg_color = '#FDEDEC';
                                                    }else if($marge > 45 && $marge <= 75){
                                                        $bg_color = '#FEF9E7';
                                                    } else{
                                                        $bg_color = '#EAFAF1';
                                                    }
                                                    ?>
                                                    <td style="background-color: <?=$bg_color?>">
                                                        <?= $marge ?> %
                                                    </td>
                                                    <td>
                                                        <?= $total_cost > 0 ? number_format(($product->total_raw_cost / $total_cost) * 100, 2) : 0 ?> %
                                                    </td>
                                                    <td>
                                                        <?= $total_earning > 0 ? number_format(($product->earned_value_pln / $total_earning) * 100, 2) : 0 ?> %
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-4">
                            <a href="<?=ROOT?>/ProductionSection/calculation" role="button" class="btn btn-secondary">Powrót</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else:?>
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Podsumowanie</h4>
                </div>
                <div class="card-body">
                    <table class="table table-hover">
                        <thead>
                        <th style="width: 20%">Dział</th>
                        <th style="width: 15%">Liczba pracowników</th>
                        <th style="width: 15%">Miesięczny koszt pracowników</th>
                        <th style="width: 20%">Koszt surowców w ostatnich 30 dniach <span class="text-xl" data-bs-toggle="tooltip" data-bs-placement="top" title="Wyliczenie obejmuje produkty WYSŁANE 30 dni wstecz od dnia dzisiejszego">&#x1F6C8;</span></th>
                        <th style="width: 20%">Przychód w ostatnich 30 dniach <span class="text-xl" data-bs-toggle="tooltip" data-bs-placement="top" title="Wyliczenie obejmuje produkty WYSŁANE 30 dni wstecz od dnia dzisiejszego">&#x1F6C8;</span></th>
                        <th style="width: 10%"></th>
                        </thead>
                        <tbody>
                        <?php if(!empty($rows)): ?>
                            <?php foreach($rows as $row): ?>
                                <tr>
                                    <td><?= $row->section_name ?></td>
                                    <td><?= $row->employee_qty ?></td>
                                    <td><?= $row->employee_cost_zl ?> zł</td>
                                    <td><?= $row->total_raw_price_zl ?> zł</td>
                                    <td><?= $row->total_income_eur ?> € (<?=$row->total_income_zl?> zł)</td>
                                    <td><a href="<?=ROOT?>/ProductionSection/profitability/details/<?=$row->id?>" class="btn btn-info">Szczegóły</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                            <tr class="bg-light-info border-dark">
                                <td class="text-center" colspan="2">Łącznie:</td>
                                <td><?= $emp_cost_zl ?> zł</td>
                                <td><?= $raw_price_zl ?> zł</td>
                                <td><?= $total_income_eur ?> € (<?=$total_income_zl?> zł)</td>
                                <td><?=$summary_zl?> zł</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </section>
</div>

<?php $this->view("page_components/footer"); ?>

<script>

    function updateTotalRevenue(input){
        const general_table = document.getElementById('general_table');
        const general_table_inputs = general_table.getElementsByTagName("input");

        const total_additional_cost = document.getElementById('total_additional_cost');
        const total_revenue = document.getElementById('total_revenue');
        const total_raw_price_zl = document.getElementById('total_raw_price_zl');
        const total_employee_cost = document.getElementById('total_employee_cost');
        const total_income = document.getElementById('total_income');
        const total_marge = document.getElementById('total_marge');
        const total_costs = document.getElementById('total_costs');

        let additional_cost = 0;
        for(let i=0; i<general_table_inputs.length; i++){
            additional_cost = Number(additional_cost) + Number(general_table_inputs[i].value);
        }

        total_additional_cost.innerHTML = additional_cost;
        let costs = Number(total_additional_cost.innerHTML) + Number(total_raw_price_zl.innerHTML) + Number(total_employee_cost.innerHTML);
        total_revenue.innerText = (Number(total_income.innerHTML) - Number(costs)).toFixed(2)
        total_costs.innerHTML = costs.toFixed(2);
        total_marge.innerHTML = ((Number(total_income.innerHTML) - Number(costs))/Number(total_income.innerHTML) * 100).toFixed(2);
    }

    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
</script>
