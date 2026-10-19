<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Receptura</h3>
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



            <?php if($action == 'calculation'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Kalkulacja ceny receptury</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-1"></div>
                            <div class="col-10">
                                <?php if($calculation['recipe_final_data']['id_recipe_type'] == 2 || $calculation['recipe_final_data']['id_recipe_type'] == 3): ?>
                                    <h2 class="text-center"><?=$calculation['recipe_final_data']['recipe_name']?></h2>
                                    <p class="text-center">Cena mat. za 1 sztukę: <b><?=number_format($calculation['recipe_final_data']['total_price'],2,'.','')?> zł</b></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="row mt-5">
                            <div class="col-12">
                                <table class="table table">
                                    <thead>
                                    <th style="width: 35%">Receptura</th>
                                    <th style="width: 35%">Produkt</th>
                                    <th style="width: 15%">Ilość</th>
                                    <th style="width: 15%">Cena</th>
                                    </thead>
                                    <tbody>
                                    <?php if(!empty($calculation)): ?>
                                        <?php foreach ($calculation as $calc_item): ?>
                                            <tr class="bg-light-info">
                                                <td><?=$calc_item['recipe_name']?></td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                            </tr>
                                            <?php if(!empty($calc_item['ingredients_data'])): ?>
                                                <?php foreach ($calc_item['ingredients_data'] as $calc_ing_item): ?>
                                                    <tr>
                                                        <td></td>
                                                        <td><?=$calc_ing_item['name']?></td>
                                                        <td><?=$calc_ing_item['quantity']?> <?=$calc_ing_item['unit']?></td>
                                                        <td><?=number_format($calc_ing_item['price_in_recipe'],2,'.','')?> zł</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <tr class="bg-light-secondary border border-bottom border-dark border-1">
                                                <td>Łącznie:</td>
                                                <td></td>
                                                <?php if($calc_item['outcome_unit'] == 5): ?>
                                                    <td>1 <?=$calc_item['outcome_unit_name']?></td>
                                                <?php else: ?>
                                                    <td><?=$calc_item['total_quantity']?> <?=$calc_item['outcome_unit_name']?></td>
                                                <?php endif; ?>
                                                <td><?=number_format($calc_item['total_price'],2,'.','')?> zł</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <a href="<?=ROOT?>/Recipe" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif($action == 'tree'): ?>

                <div class="card" onload="runTree()">
                    <div class="card-header">
                        <h4 class="card-title">Drzewo</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12 mt-5">
                                <div id="myTreeContainer"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>

                    window.addEventListener("load", (event) => {
                        runTree(<?=$id_recipe?>);
                    });

                    function runTree(id_recipe){

                        const promise = getJSON(validAjaxUrl()+`ajax/recipe/get_tree`, "id_recipe="+JSON.stringify(id_recipe));
                        promise.then(function (res){
                            console.log(res);
                            t = new ECOTree('myTree','myTreeContainer');
                            t.config.iRootOrientation = ECOTree.RO_LEFT;
                            t.config.colorStyle = ECOTree.CS_LEVEL;
                            t.config.levelColors = ["#FFFFFF"];
                            t.config.nodeFill = ECOTree.NF_FLAT;
                            t.config.useTarget = false;
                            t.config.selectMode = ECOTree.SL_NONE;
                            t.config.defaultNodeWidth = 200;
                            t.config.defaultNodeHeight = 25;
                            t.config.iSubtreeSeparation = 20;
                            t.config.iSiblingSeparation = 15;
                            t.config.iLevelSeparation = 30;
                            t.config.expandedImage = "";
                            t.config.collapsedImage = "";
                            t.config.transImage = "";
                            t.config.topXAdjustment = 200;

                            t.add(id_recipe,-1, res.recipe_data.name,null,null,null);

                            for (let i=0; i<res.recipe_tree.length; i++){
                                let parent_id = res.recipe_tree[i].parent_recipe_id;
                                let id = 0;

                                if(res.recipe_tree[i].child_ingredient_id == "0"){
                                    id = res.recipe_tree[i].child_recipe_id;
                                } else {
                                    id = res.recipe_tree[i].child_ingredient_id;
                                }

                                t.add(id, parent_id, res.recipe_tree[i].name);
                            }
                            t.UpdateTree();

                            // t.add(2,1,'plants');
                            // t.add(3,1,'fungi');
                            // t.add(4,1,'lichens');
                            // t.add(5,1,'animals');
                            // t.add(6,2,'mosses');
                            // t.add(7,2,'ferns');
                            // t.add(8,2,'gymnosperms');
                            // t.add(9,2,'dicotyledons');
                            // t.add(10,2,'monocotyledons');
                            // t.add(11,5,'invertebrates');
                            // t.add(12,5,'vertebrates');
                            // t.add(13,11,'insects');
                            // t.add(14,11,'molluscs');
                            // t.add(15,11,'crustaceans');
                            // t.add(16,11,'others');
                            // t.add(17,12,'fish');
                            // t.add(18,12,'amphibians');
                            // t.add(19,12,'reptiles');
                            // t.add(20,12,'birds');
                            // t.add(21,12,'mammals');
                            t.UpdateTree();
                        })

                    }

                </script>

            <?php elseif($action == 'debug'): ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Lista utworzonych receptur</h4>
                        <br>
                        <div class="row text-center">
                            <div class="col-4">
                                <a href="<?=ROOT?>/Recipe/compositions/new" class="btn btn-primary" role="button" target="_blank">Dodaj nową kompozycję</a>
                            </div>
                            <div class="col-4">
                                <a href="<?=ROOT?>/Recipe/semi_final_recipe/new" class="btn btn-primary" role="button" target="_blank">Dodaj nową recepturę dla półproduktu</a>
                            </div>
                            <div class="col-4">
                                <a href="<?=ROOT?>/Recipe/final_recipe/new" class="btn btn-primary" role="button" target="_blank">Dodaj nową recepturę dla produktu</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mt-4">
                            <div class="col-4">
                                <input class="form-control" type="text" placeholder="Wyszukaj po nazwie..." onkeyup="searchInTable(this.value)">
                            </div>
                            <div class="col-4">
                                <select class="form-select" onchange="filterTable(this.value)">
                                    <option value="-1">Wybierz typ</option>
                                    <option value="2">Półprodukt</option>
                                    <option value="1">Kompozycja</option>
                                    <option value="3">Produkt gotowy</option>
                                </select>
                            </div>
                            <div class="col-4">
                                <a href="<?=ROOT?>/Document/generate/missing_recipe" role="button" class="btn btn-dark text-sm" target="_blank">Brakujące receptury [PDF]</a>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <table class="table table-hover">
                                    <thead>
                                    <th style="width: 40%">Kategoria</th>
                                    <th style="width: 30%">Typ</th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%">Raw</th>
                                    </thead>
                                    <tbody id="id_recipe_table">
                                    <?php if(!empty($rows)): ?>
                                        <?php foreach($rows as $row): ?>
                                            <tr>
                                                <td><?= $row->name ?> <input type="hidden" value="<?= $row->id_type ?>"</td>
                                                <td><?= $row->type_name ?></td>
                                                <td class="text-center"><a href="<?=ROOT?>/Recipe/recipes/calculation/<?=$row->id?>" class="btn btn-info" target="_blank">Kalkulacja</a></td>
                                                <?php if($row->id_type == 1): ?>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/compositions/edit/<?=$row->id?>" class="btn btn-warning" target="_blank">Edytuj</a></td>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/compositions/delete/<?=$row->id?>" class="btn btn-danger" target="_blank">Usuń</a></td>
                                                <?php elseif($row->id_type == 2): ?>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/semi_final_recipe/edit/<?=$row->id?>" class="btn btn-warning" target="_blank">Edytuj</a></td>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/semi_final_recipe/delete/<?=$row->id?>" class="btn btn-danger" target="_blank">Usuń</a></td>
                                                <?php elseif($row->id_type == 3): ?>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/final_recipe/edit/<?=$row->id?>" class="btn btn-warning" target="_blank">Edytuj</a></td>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/final_recipe/delete/<?=$row->id?>" class="btn btn-danger" target="_blank">Usuń</a></td>
                                                <?php endif; ?>




                                                <td class="text-center"><a href="<?=ROOT?>/Recipe/recipes/tree/<?=$row->id?>" class="btn btn-info" target="_blank">Podgląd</a></td>
                                                <td><?=$row->final_price?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>


            <?php else: ?>

                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title">Lista utworzonych receptur</h4>
                        <br>
                        <div class="row text-center">
                            <div class="col-4">
                                <a href="<?=ROOT?>/Recipe/compositions/new" class="btn btn-primary" role="button">Dodaj nową kompozycję</a>
                            </div>
                            <div class="col-4">
                                <a href="<?=ROOT?>/Recipe/semi_final_recipe/new" class="btn btn-primary" role="button">Dodaj nową recepturę dla półproduktu</a>
                            </div>
                            <div class="col-4">
                                <a href="<?=ROOT?>/Recipe/final_recipe/new" class="btn btn-primary" role="button">Dodaj nową recepturę dla produktu</a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mt-4">
                            <div class="col-4">
                                <input class="form-control" type="text" placeholder="Wyszukaj po nazwie..." onkeyup="searchInTable(this.value)">
                            </div>
                            <div class="col-4">
                                <select class="form-select" onchange="filterTable(this.value)">
                                    <option value="-1">Wybierz typ</option>
                                    <option value="2">Półprodukt</option>
                                    <option value="1">Kompozycja</option>
                                    <option value="3">Produkt gotowy</option>
                                </select>
                            </div>
                            <div class="col-4">
                                <a href="<?=ROOT?>/Document/generate/missing_recipe" role="button" class="btn btn-dark text-sm" target="_blank">Brakujące receptury [PDF]</a>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <table class="table table-hover">
                                    <thead>
                                    <th style="width: 40%">Kategoria</th>
                                    <th style="width: 30%">Typ</th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%"></th>
                                    <th style="width: 10%"></th>
                                    </thead>
                                    <tbody id="id_recipe_table">
                                    <?php if(!empty($rows)): ?>
                                        <?php foreach($rows as $row): ?>
                                            <tr>
                                                <td><?= $row->name ?> <input type="hidden" value="<?= $row->id_type ?>"</td>
                                                <td><?= $row->type_name ?></td>
                                                <td class="text-center"><a href="<?=ROOT?>/Recipe/recipes/calculation/<?=$row->id?>" class="btn btn-info">Kalkulacja</a></td>
                                                <?php if($row->id_type == 1): ?>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/compositions/edit/<?=$row->id?>" class="btn btn-warning">Edytuj</a></td>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/compositions/delete/<?=$row->id?>" class="btn btn-danger">Usuń</a></td>
                                                <?php elseif($row->id_type == 2): ?>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/semi_final_recipe/edit/<?=$row->id?>" class="btn btn-warning">Edytuj</a></td>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/semi_final_recipe/delete/<?=$row->id?>" class="btn btn-danger">Usuń</a></td>
                                                <?php elseif($row->id_type == 3): ?>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/final_recipe/edit/<?=$row->id?>" class="btn btn-warning">Edytuj</a></td>
                                                    <td class="text-center"><a href="<?=ROOT?>/Recipe/final_recipe/delete/<?=$row->id?>" class="btn btn-danger">Usuń</a></td>
                                                <?php endif; ?>


                                                <td class="text-center"><a href="<?=ROOT?>/Recipe/recipes/tree/<?=$row->id?>" class="btn btn-info">Podgląd</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </section>
    </div>

    <script>

        function searchInTable(input_value){
            // Declare variables
            var filter, table, tr, td, i, txtValue;
            filter = input_value.toUpperCase();
            table = document.getElementById("id_recipe_table");
            tr = table.getElementsByTagName("tr");

            // Loop through all table rows, and hide those who don't match the search query
            for (i = 0; i < tr.length; i++) {
                td = tr[i].getElementsByTagName("td")[0];
                if (td) {
                    txtValue = td.textContent || td.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        function filterTable(select_value){
            const table = document.getElementById('id_recipe_table');
            const tr = table.getElementsByTagName("tr");
            let txtValue;
            for(let i=0; i<tr.length; i++){
                console.log(tr[i]);

                let td = tr[i].getElementsByTagName("td")[0].getElementsByTagName("input")[0].value;
                if (td) {
                    txtValue = td;
                    if (txtValue.toUpperCase().indexOf(select_value) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

    </script>

<?php $this->view("page_components/footer"); ?>