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

        <?php if($action == 'summary'): ?>





        <?php else:?>



        <?php endif; ?>
    </section>
</div>

<?php $this->view("page_components/footer"); ?>

