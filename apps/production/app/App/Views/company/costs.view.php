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
                    <h4 class="card-title">Szacunkowe miesięczne koszty</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12">
                            <table class="table">
                                <thead>
                                <th style="width: 20%">Dział</th>
                                <th style="width: 13%">Amortyzacja</th>
                                <th style="width: 13%">Prąd</th>
                                <th style="width: 13%">Woda</th>
                                <th style="width: 13%">Paliwo</th>
                                <th style="width: 13%">Inne</th>
                                <th style="width: 15%" class="text-center">Suma</th>
                                </thead>
                                <tbody id="cost_tbody">
                                <?php if(!empty($sections)): ?>
                                    <?php foreach($sections as $section): ?>
                                        <tr>
                                            <td><?= $section['name'] ?></td>
                                            <td><input type="number" class="form-control" value="<?= $section['depreciation'] ?>" name="depreciation" onblur="updateCost(this, <?= $section['id'] ?>)"></td>
                                            <td><input type="number" class="form-control" value="<?= $section['water_cost'] ?>" name="water_cost" onblur="updateCost(this, <?= $section['id'] ?>)"></td>
                                            <td><input type="number" class="form-control" value="<?= $section['power_supply_cost'] ?>" name="power_supply_cost" onblur="updateCost(this, <?= $section['id'] ?>)"></td>
                                            <td><input type="number" class="form-control" value="<?= $section['fuel_cost'] ?>" name="fuel_cost" onblur="updateCost(this, <?= $section['id'] ?>)"></td>
                                            <td><input type="number" class="form-control" value="<?= $section['other_cost'] ?>" name="other_cost" onblur="updateCost(this, <?= $section['id'] ?>)"></td>
                                            <td class="text-center"><span></span> zł</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                                <tbody>
                                <tr>
                                    <td class="float-end">Suma:</td>
                                    <td class="text-center"><span id="sum_depreciation"></span> zł</td>
                                    <td class="text-center"><span id="sum_water_cost"></span> zł</td>
                                    <td class="text-center"><span id="sum_power_supply_cost"></span> zł</td>
                                    <td class="text-center"><span id="sum_fuel_cost"></span> zł</td>
                                    <td class="text-center"><span id="sum_other_cost"></span> zł</td>
                                    <td class="text-center"><span id="sum_total_cost"></span> zł</td>
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

<script type="text/javascript">

    window.onload = recaltulateData();

    function recaltulateData(){

        const tbody = document.getElementById('cost_tbody');
        let sum_depreciation = 0;
        let sum_water_cost = 0;
        let sum_power_supply_cost = 0;
        let sum_fuel_cost = 0;
        let sum_other_cost = 0;
        let sum_cost = 0;


        for(let i=0; i<tbody.rows.length; i++){
            sum_depreciation += Number(tbody.rows[i].cells[1].firstChild.value);
            sum_water_cost += Number(tbody.rows[i].cells[2].firstChild.value);
            sum_power_supply_cost += Number(tbody.rows[i].cells[3].firstChild.value);
            sum_fuel_cost += Number(tbody.rows[i].cells[4].firstChild.value);
            sum_other_cost += Number(tbody.rows[i].cells[5].firstChild.value);

            let sum_section_cost = Number(tbody.rows[i].cells[1].firstChild.value) +
                Number(tbody.rows[i].cells[2].firstChild.value) +
                Number(tbody.rows[i].cells[3].firstChild.value) + Number(tbody.rows[i].cells[4].firstChild.value) +
                Number(tbody.rows[i].cells[5].firstChild.value);

            sum_cost += Number(sum_section_cost);
            tbody.rows[i].cells[6].firstChild.innerText = sum_section_cost.toFixed(2);
        }


        document.getElementById('sum_depreciation').innerText = sum_depreciation.toFixed(2)
        document.getElementById('sum_water_cost').innerText = sum_water_cost.toFixed(2)
        document.getElementById('sum_power_supply_cost').innerText = sum_power_supply_cost.toFixed(2)
        document.getElementById('sum_fuel_cost').innerText = sum_fuel_cost.toFixed(2)
        document.getElementById('sum_other_cost').innerText = sum_other_cost.toFixed(2)
        document.getElementById('sum_total_cost').innerText = sum_cost.toFixed(2)

    }

    function updateCost(control, id){
        const data = {
            id : id
        }
        data[control.name] = control.value;

        api(`/ajax/company-costs/${id}`, {
            method: 'PATCH',
            data
        })
            .then(function (res){
                showSnack(res);
                recaltulateData();
            })
            .catch(function (err){
                console.error(err);
                showSnack({
                    type: 'error',
                    message: 'Wystąpił błąd podczas aktualizacji kosztów'
                });
            });

    }
</script>
