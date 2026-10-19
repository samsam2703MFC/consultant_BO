<?php $this->view("page_components/header"); ?>

    <style>
        .draggable-table {
            position: relative;
            background: white;

            .draggable-table__drag {
                font-size: .95em;
                font-weight: lighter;
                text-transform: capitalize;
                position: absolute;
                width: 100%;
                text-indent: 50px;
                border: 1px solid #f1f1f1;
                z-index: 10;
                cursor: grabbing;
                -webkit-box-shadow: 2px 2px 3px 0px rgba(0, 0, 0, 0.05);
                box-shadow: 2px 2px 3px 0px rgba(0, 0, 0, 0.05);

                opacity: 1;
            }

            thead {

                th {
                    user-select: none;
                }
            }

            tbody {

                tr {
                    cursor: grabbing;

                    td {
                        font-size: .95em;
                        font-weight: lighter;
                        text-transform: capitalize;

                        user-select: none;
                    }
                }

                tr:nth-child(even) {
                    background-color: #f7f7f7;
                }

                tr:nth-child(odd) {
                    background-color: #ffffff;
                }

                tr.is-dragging {
                    background: #f1c40f;

                    td {
                        color: #ffe683;
                    }
                }
            }
        }

    </style>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Etapy produktu testowego</h3>
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
                    <h4 class="card-title">Lista istniejących etapów <a href="<?=ROOT?>/test-product-stages/new" class="btn btn-primary" role="button">Dodaj nowy</a></h4>
                </div>
                <div class="card-body">
                    <table class="table table-striped draggable-table" id="table">
                        <thead>
                        <th style="width: 5%"></th>
                        <th style="width: 65%">Nazwa</th>
                        <th style="width: 15%"></th>
                        <th style="width: 15%"></th>
                        </thead>
                        <tbody id="stages_table">
                        <?php if(!empty($rows)): ?>
                            <?php foreach($rows as $row): ?>
                                <tr>
                                    <td style="text-align: center" class='icon dripicons-move'><input type='hidden' value='<?=$row['id']?>'></td>
                                    <td><?= $row['name'] ?></td>
                                    <td><a href="<?=ROOT?>/test-product-stages/<?=$row['id']?>/edit" class="btn btn-warning">Edytuj</a></td>
                                    <td><a href="<?=ROOT?>/test-product-stages/<?=$row['id']?>/delete" class="btn btn-danger">Usuń</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="row">
                        <div class="col-3">
                            <a href="<?=ROOT?>/test-products" class="btn btn-secondary" role="button">Powrót</a>
                        </div>
                        <div class="col-6"></div>
                    </div>
                </div>
            </div>

        </section>
    </div>
<?php $this->view("page_components/footer"); ?>

<script>
    (function() {
        "use strict";

        const table = document.getElementById('table');
        const tbody = table.querySelector('tbody');

        var currRow = null,
            dragElem = null,
            mouseDownX = 0,
            mouseDownY = 0,
            mouseX = 0,
            mouseY = 0,
            mouseDrag = false;

        function init() {
            bindMouse();
        }

        function bindMouse() {
            document.addEventListener('mousedown', (event) => {
                if(event.button != 0) return true;
                if(event.target.parentNode.children[0] != event.target){
                    return;
                }


                let target = getTargetRow(event.target);
                if(target) {
                    currRow = target;
                    addDraggableRow(target);
                    currRow.classList.add('is-dragging');


                    let coords = getMouseCoords(event);
                    mouseDownX = coords.x;
                    mouseDownY = coords.y;

                    mouseDrag = true;
                }
            });

            document.addEventListener('mousemove', (event) => {
                if(!mouseDrag) return;

                let coords = getMouseCoords(event);
                mouseX = coords.x - mouseDownX;
                mouseY = coords.y - mouseDownY;

                moveRow(mouseX, mouseY);
            });

            document.addEventListener('mouseup', (event) => {
                if(!mouseDrag) return;

                currRow.classList.remove('is-dragging');
                table.removeChild(dragElem);

                dragElem = null;
                mouseDrag = false;
                updateRowPriority();
            });
        }


        function swapRow(row, index) {
            let currIndex = Array.from(tbody.children).indexOf(currRow),
                row1 = currIndex > index ? currRow : row,
                row2 = currIndex > index ? row : currRow;

            tbody.insertBefore(row1, row2);
        }

        function moveRow(x, y) {
            dragElem.style.transform = "translate3d(" + x + "px, " + y + "px, 0)";

            let	dPos = dragElem.getBoundingClientRect(),
                currStartY = dPos.y, currEndY = currStartY + dPos.height,
                rows = getRows();

            for(var i = 0; i < rows.length; i++) {
                let rowElem = rows[i],
                    rowSize = rowElem.getBoundingClientRect(),
                    rowStartY = rowSize.y, rowEndY = rowStartY + rowSize.height;

                if(currRow !== rowElem && isIntersecting(currStartY, currEndY, rowStartY, rowEndY)) {
                    if(Math.abs(currStartY - rowStartY) < rowSize.height / 2)
                        swapRow(rowElem, i);
                }
            }
        }

        function addDraggableRow(target) {
            dragElem = target.cloneNode(true);
            dragElem.classList.add('draggable-table__drag');
            dragElem.style.height = getStyle(target, 'height');
            dragElem.style.background = getStyle(target, 'backgroundColor');
            for(var i = 0; i < target.children.length; i++) {
                let oldTD = target.children[i],
                    newTD = dragElem.children[i];
                newTD.style.width = getStyle(oldTD, 'width');
                newTD.style.height = getStyle(oldTD, 'height');
                newTD.style.padding = getStyle(oldTD, 'padding');
                newTD.style.margin = getStyle(oldTD, 'margin');
            }

            table.appendChild(dragElem);


            let tPos = target.getBoundingClientRect(),
                dPos = dragElem.getBoundingClientRect();
            dragElem.style.bottom = ((dPos.y - tPos.y) - tPos.height) + "px";
            dragElem.style.left = "-1px";

            document.dispatchEvent(new MouseEvent('mousemove',
                { view: window, cancelable: true, bubbles: true }
            ));
        }

        function getRows() {
            return table.querySelectorAll('tbody tr');
        }

        function getTargetRow(target) {
            let elemName = target.tagName.toLowerCase();

            if(elemName == 'tr') return target;
            if(elemName == 'td') return target.closest('tr');
        }

        function getMouseCoords(event) {
            return {
                x: event.clientX,
                y: event.clientY
            };
        }

        function getStyle(target, styleName) {
            let compStyle = getComputedStyle(target),
                style = compStyle[styleName];

            return style ? style : null;
        }

        function isIntersecting(min0, max0, min1, max1) {
            return Math.max(min0, max0) >= Math.min(min1, max1) &&
                Math.min(min0, max0) <= Math.max(min1, max1);
        }



        init();

    })();

    function updateRowPriority(){
        const table_rows = document.getElementById('stages_table').rows;
        let data = [];
        for(let i=0; i<table_rows.length; i++){
            data.push({prio: i+1, id: table_rows[i].getElementsByTagName("input")[0].value})
        }

        api(`/ajax/test-product-stages/prio`, {
            method: 'PATCH',
            data
        })
            .then(function (res) {
                showSnack(res);
            })
            .catch(function (err) {
                showSnack(err, "Błąd podczas aktualizacji priorytetu etapów");
            });
    }

</script>

