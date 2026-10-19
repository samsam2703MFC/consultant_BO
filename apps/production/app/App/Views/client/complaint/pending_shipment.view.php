<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row">
            <div class="col-12 col-md-6 order-md-1 order-last">
                <h3>Produkty oczekujące na wysłanie</h3>
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
                <h4 class="card-title">Oczekujące zwroty</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <th>Klient</th>
                            <th>Produkt</th>
                            <th>Ilość</th>
                            <th>Data wysyłki</th>
                            <th></th>
                            </thead>
                            <tbody>
                            <?php if(!empty($pending_complaints)): ?>
                                <?php foreach ($pending_complaints as $pending_complaint): ?>
                                    <tr>
                                        <td><?=$pending_complaint['customer_name']?></td>
                                        <td><?=$pending_complaint['product_name']?></td>
                                        <td><?=$pending_complaint['carton_qty']?></td>
                                        <td><input type="date" name="shipping_date" value="<?=$pending_complaint['shipping_date']?>" class="form-control" onchange="updateShipping(this, <?=$pending_complaint['id']?>)"></td>
                                        <td><button class="btn btn-success" type="button" onclick="hideComplaint(<?=$pending_complaint['id']?>, this)">Wysłane</button></td>
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

<script>
    function updateShipping(control, id){
        const data = {};
        data[control.name] = control.value

        api(`/ajax/client-complaints/pending-shipment/${id}`,{
            method: 'PATCH',
            data
        })
            .then((response) => {
                showSnack(response.ok);
            })
            .catch((error) => {
                showSnack(false);
            })
    }

    function hideComplaint(id, btn){
        const data = {
            status : 2
        };

        api(`/ajax/client-complaints/pending-shipment/${id}`,{
            method: 'PATCH',
            data
        })
            .then((response) => {
                showSnack(response.ok);
                btn.parentNode.parentNode.remove();
            })
            .catch((error) => {
                showSnack(false);
            })
    }

</script>
