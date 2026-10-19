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

        <?php if($action == 'product'): ?>

        <div class="row">
            <div class="col-5">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-header"></h4>
                            </div>
                            <div class="card-body">
                                <table class="table">
                                    <thead>
                                    <th style="width: 60%"></th>
                                    <th style="width: 40%"></th>
                                    </thead>
                                    <tbody>
                                    <tr>
                                        <td>Produkt</td>
                                        <td><?=$product_data->name?></td>
                                    </tr>
                                    <tr>
                                        <td>Kategoria</td>
                                        <td><?=$product_data->category_name?></td>
                                    </tr>
                                    <tr>
                                        <td>Sekcja</td>
                                        <td><?=$product_data->section_name?></td>
                                    </tr>
                                    <tr>
                                        <td>Cena sprzedaży (€)</td>
                                        <td><input type="number" class="form-control" id="selling_price_eur" value="<?=$product_data->price?>" onkeyup="recalculateData()"></td>
                                    </tr>
                                    <tr>
                                        <td>Kurs euro</td>
                                        <td><input type="number" class="form-control" id="exchange_rate_euro" value="4.30" onkeyup="recalculateData()"></td>
                                    </tr>
                                    <tr>
                                        <td>Cena sprzedaży (zł)</td>
                                        <td><span id="selling_price_pln"></span> zł</td>
                                    </tr>
                                    <tr>
                                        <td>Marża kwotowa</td>
                                        <td><span id="quota_margin"></span> zł</td>
                                    </tr>
                                    <tr>
                                        <td>Marża procentowa</td>
                                        <td><span id="percentage_margin"></span>%</td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title">Próg rentowności</h4>
                            </div>
                            <div class="card-body">

                                <div class="row">
                                    <div class="col-2"></div>
                                    <div class="col-8">
                                        <?php if(!empty($errors)):?>
                                            <div class="alert alert-danger text-center"><?=implode("<br>", $errors)?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <table class="table">
                                            <thead>
                                            <th style="width: 60%"></th>
                                            <th style="width: 40%"></th>
                                            </thead>
                                            <tbody>
                                            <tr>
                                                <td>Całowite mies. koszty</td>
                                                <td><span id="total_fixed_costs"></span> zł</td>
                                            </tr>
                                            <tr>
                                                <td>Jedn. koszt całk. zmienny</td>
                                                <td><span id="unit_variable_cost"></span> zł</td>
                                            </tr>
                                            <tr>
                                                <td>Jedn. zysk brutto</td>
                                                <td><span id="contribution_margin"></span> zł</td>
                                            </tr>
                                            <tr class="bg-light-warning">
                                                <td>Ilościowy próg rentowności</td>
                                                <td><span id="quantity_bep"></span></td>
                                            </tr>
                                            <tr class="bg-light-warning">
                                                <td>Wartościowy próg rentowności</td>
                                                <td><span id="value_bep"></span> zł</td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-7">
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-header"></h4>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-12">
                                        <h5 class="text-center">Koszty bezpośrednie</h5>
                                        <table class="table mt-3">
                                            <thead>
                                            <th style="width: 60%"></th>
                                            <th style="width: 40%"></th>
                                            </thead>
                                            <tbody id="direct_cost_tbody">
                                            <tr>
                                                <td>Koszt surowców</td>
                                                <td><span id="id_recipe_price"><?=$recipe_price?></span> zł</td>
                                            </tr>
                                            <tr>
                                                <td>Koszt pracowników</td>
                                                <td><input type="number" class="form-control" id="employee_cost" onkeyup="recalculateData()" value="<?=$employee_cost?>"></td>
                                            </tr>
                                            </tbody>
                                            <tbody>
                                            <tr>
                                                <td class="float-end">Suma:</td>
                                                <td><span id="sum_direct_cost"></span> zł</td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="col-6 mt-5">
                                        <h5 class="text-center">Koszty pośrednie stałe (dział)</h5>
                                        <table class="table mt-3">
                                            <thead>
                                            <th style="width: 60%"></th>
                                            <th style="width: 40%"></th>
                                            </thead>
                                            <tbody id="indirect_fixed_tbody">
                                            <tr>
                                                <td>Amortyzacja</td>
                                                <td><input type="number" class="form-control" id="section_depreciation" onkeyup="recalculateData()" value="<?=$section_cost->depreciation?>"></td>
                                            </tr>
                                            </tbody>
                                            <tbody>
                                            <tr>
                                                <td class="float-end">Suma:</td>
                                                <td><span id="sum_indirect_fixed_cost"></span> zł</td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="col-6 mt-5">
                                        <h5 class="text-center">Koszty pośrednie zmienne (dział)</h5>
                                        <table class="table mt-3">
                                            <thead>
                                            <th style="width: 60%"></th>
                                            <th style="width: 40%"></th>
                                            </thead>
                                            <tbody id="indirect_variable_tbody">
                                            <tr>
                                                <td>Woda</td>
                                                <td><input type="number" class="form-control" id="water_cost" onkeyup="recalculateData()" value="<?=$section_cost->water_cost?>"></td>
                                            </tr>
                                            <tr>
                                                <td>Prąd</td>
                                                <td><input type="number" class="form-control" id="power_supply_cost" onkeyup="recalculateData()" value="<?=$section_cost->power_supply_cost?>"></td>
                                            </tr>
                                            <tr>
                                                <td>Paliwo</td>
                                                <td><input type="number" class="form-control" id="fuel_cost" onkeyup="recalculateData()" value="<?=$section_cost->fuel_cost?>"></td>
                                            </tr>
                                            <tr>
                                                <td>Inne</td>
                                                <td><input type="number" class="form-control" id="other_cost" onkeyup="recalculateData()" value="<?=$section_cost->other_cost?>"></td>
                                            </tr>
                                            </tbody>
                                            <tbody>
                                            <tr>
                                                <td class="float-end">Suma:</td>
                                                <td><span id="sum_indirect_variable_cost"></span> zł</td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>




        <script type="text/javascript">

            window.onload = recalculateData();

            function recalculateData(){

                calculateDirectCost();
                calculateIndirectFixedCost();
                calculateIndirectVariableCost();
                calculateProductSellingPrice();

                calculateTFC();
                calculateUVC();
                calculateCM();
                calculateQBE();
                calculateVBE();
                calculateMargin();
            }

            function calculateProductSellingPrice(){
                const exchange_rate = document.getElementById('exchange_rate_euro').value;
                const selling_price_eur = document.getElementById('selling_price_eur').value;
                document.getElementById('selling_price_pln').innerText = (Number(selling_price_eur) * Number(exchange_rate)).toFixed(2);
            }

            function calculateDirectCost(){
                const recipe_price = document.getElementById('id_recipe_price').innerText;
                let costs = Number(recipe_price);
                const inputs = document.getElementById('direct_cost_tbody').getElementsByTagName("input");
                for(let i=0; i<inputs.length; i++){
                    costs = Number(costs) + Number(inputs[i].value);
                }
                document.getElementById('sum_direct_cost').innerText = costs.toFixed(2);
            }

            function calculateIndirectFixedCost(){
                let costs = 0;
                const inputs = document.getElementById('indirect_fixed_tbody').getElementsByTagName("input");
                for(let i=0; i<inputs.length; i++){
                    costs = Number(costs) + Number(inputs[i].value);
                }
                document.getElementById('sum_indirect_fixed_cost').innerText = costs.toFixed(2);
            }

            function calculateIndirectVariableCost(){
                let costs = 0;
                const inputs = document.getElementById('indirect_variable_tbody').getElementsByTagName("input");
                for(let i=0; i<inputs.length; i++){
                    costs = Number(costs) + Number(inputs[i].value);
                }
                document.getElementById('sum_indirect_variable_cost').innerText = costs.toFixed(2);
            }

            function calculateTFC(){
                document.getElementById('total_fixed_costs').innerText =
                    (Number(document.getElementById('sum_direct_cost').innerText) +
                    Number(document.getElementById('sum_indirect_fixed_cost').innerText) +
                    Number(document.getElementById('sum_indirect_variable_cost').innerText)).toFixed(2)
            }

            function calculateUVC(){
                document.getElementById('unit_variable_cost').innerText = document.getElementById('id_recipe_price').innerText;
            }

            function calculateCM(){
                document.getElementById('contribution_margin').innerText =
                    (Number(document.getElementById('selling_price_pln').innerText) -
                    Number(document.getElementById('id_recipe_price').innerText)).toFixed(2)
            }

            function calculateQBE(){
                document.getElementById('quantity_bep').innerText = (Number(document.getElementById('total_fixed_costs').innerText) / Number(document.getElementById('contribution_margin').innerText)).toFixed(0)
            }

            function calculateVBE(){
                document.getElementById('value_bep').innerText = (Number(document.getElementById('quantity_bep').innerText) * Number(document.getElementById('selling_price_pln').innerText)).toFixed(2)
            }

            function calculateMargin(){
                const quota_margin = (Number(document.getElementById('selling_price_pln').innerText) - Number(document.getElementById('id_recipe_price').innerText))
                document.getElementById('quota_margin').innerText = quota_margin.toFixed(2);

                document.getElementById('percentage_margin').innerText = ((Number(quota_margin) / Number(document.getElementById('selling_price_pln').innerText)) * 100).toFixed(2);
            }

        </script>

        <?php else:?>



        <?php endif; ?>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

