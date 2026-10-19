<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Rentowność działów</h3>
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
                        <div class="col-4"></div>
                        <div class="col-4 text-center">
                            <p>Wybierz przedział</p>
                        </div>
                    </div>
                    <form method="POST">
                        <div class="row">
                            <div class="col-4"></div>
                            <div class="col-2">
                                <input type="date" class="form-control" name="start_date" id="start_date" value="<?=old_value('start_date')?>">
                            </div>
                            <div class="col-2">
                                <input type="date" class="form-control" name="end_date" id="end_date" value="<?=old_value('end_date')?>">
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <button class="btn btn-primary">Załaduj</button>
                            </div>
                        </div>
                    </form>

                    <div class="row">
                        <div class="col-12">
                            <table class="table table-hover">
                                <thead>
                                <th style="width: 40%">Kategoria</th>
                                <th style="width: 20%">Koszt surowców</th>
                                <th style="width: 20%">Przychód</th>
                                <th style="width: 20%">Dodatkowe koszty [zł]</th>
                                </thead>
                                <tbody id="general_table">
                                <?php if(!empty($rows)): ?>
                                    <?php foreach($rows as $row): ?>
                                        <tr>
                                            <td><?= $row->category_name ?></td>
                                            <td><?= $row->raw_price_zl ?> zł</td>
                                            <td><?= $row->sum_total_price_eur ?> € (<?=$row->sum_total_price_zl?> zł)</td>
                                            <td><input type="number" class="form-control" onkeyup="updateTotalRevenue(this)" placeholder="0"></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="row">
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
                                        <td><span id="total_raw_price_zl"><?=$total_raw_price_zl?></span> zł</td>
                                        <td><span id="total_employee_cost"><?=$total_employee_cost?></span> zł</td>
                                        <td><span id="total_additional_cost">0</span> zł</td>
                                        <td style="background-color: #f8d7da"><span id="total_costs"><?=$total_costs?></span> zł</td>
                                        <td style="background-color: #d4edda"><span id="total_income"><?=$total_income_zl?></span> zł</td>
                                        <td style="background-color: #fff3cd"><span id="total_revenue"><?=$total_revenue?></span> zł</td>
                                        <td style="background-color: #d1ecf1"><span id="total_marge"><?=$total_marge?></span> %</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-4">
                            <a href="<?=ROOT?>/ProductionSection/profitability" role="button" class="btn btn-secondary">Powrót</a>
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
